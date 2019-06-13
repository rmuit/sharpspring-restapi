<?php

namespace SharpSpring\RestApi\examples;

use DrunkinsCronTab;
use DrunkinsJob;
use Exception;
use RuntimeException;
use SharpSpring\RestApi\Connection;
use SharpSpring\RestApi\CurlClient;
use SharpSpring\RestApi\LocalLeadCache;
use SharpSpring\RestApi\SharpSpringRestApiException;

/**
 * Drunkins Job class to regularly synchronize contacts into Sharpspring.
 *
 * Drunkins is https://www.drupal.org/project/drunkins. If you don't use
 * Drupal, you can still use this code as inspiration - just know that Drunkins
 * - calls start() to fetch all source items;
 *   - you should implement parent::start() or something else to return items
 *     from your source system,
 *   - and convertToLead() to convert those source items into Lead objects,
 *   - and probably set $this->settings['sharpspring_no_time_constraints'] in
 *     the constructor, to skip fuzzy code in start() that's no use explaining.
 * - queues the items somehow and then calls processItem() on them one by one;
 * - calls finish() once to finish things off.
 * The parent class does not hold much extremely interesting and there are not
 * many drupalisms left in the code. Only $this->log() unfortunately still has a
 * signature that differs from PSR-3 loggers.
 *
 * This job is suitable for:
 * - one way synchronizations (where the 'source' system is supposed to hold
 *   authorative data which is essentially mirrored in Sharpspring;
 * - where the link between a source contact and a Sharpspring Lead is
 *   established by the source contact's ID number being present in Sharpspring,
 *   and the Sharpspring ID not in the source system. (Sharpspring leads without
 *   source ID will be linked via their e-mail address, but having the source
 *   ID in Sharpspring means that source contacts can change e-mail - so a link
 *   through ID is preferred over a link through e-mail.)q
 *
 * start(), after fetching contacts from somewhere, has all kinds of checks on
 * e.g. multiple contacts being created with duplicate e-mail addresses.
 *
 * This job, like other Drunkins 'sync' jobs, is subject to race conditions: It
 * will update items in processItem() even when these are updated by an external
 * process after start() was run. (Basically this job is meant for situations
 * where no other process is supposed to update Sharpspring contact data, and/or
 * all your other processes/users are sleeping at some time of night.)
 */
class SharpSpringSyncJob extends DrunkinsJob
{
    /* Settings in $this->settings, used by this class:
     * - job_id:                   defined in parent
     * - list_format:              defined in parent
     * - opt_fetcher_timestamp:    defined in fetcher
     * - fetcher_timestamp_ignore: defined in fetcher
     * - sharpspring_api_account_id (string)
     * - sharpspring_api_secret_key (string)
     * - sharpspring_lead_custom_properties (array):
     *   The custom properties for the leads. Inside this, at least the
     *   'sourceId' key must be defined because this class uses $lead->sourceId.
     * - doublecheck_sharpspring_remotely (boolean):
     *   Doublecheck missing contacts in Sharpspring. See settingsForm()
     *   description.
     * - update_inactive: (not implemented yet. See settingsForm() description.)
     * - ss_leads_timestamp_from_date: see fetcher_timestamp_from_date.
     * - ss_leads_timestamp_ignore: see fetcher_timestamp_ignore.
     * - ss_leads_cache_unset_after_start (boolean):
     *   If true, unset the LocalLeadsCache object (which is kept in the context
     *   otherwise), after determining which leads to create/update in start().
     *   The advantage to this is it is less expensive in terms of memory usage
     *   and (un)serializing the context that gets stored between processItem()
     *   calls. The disadvantage is the cache is not updated, so a next job run
     *   will have to reinitialize the local cache by fetching all leads from
     *   Sharpspring again. This setting is not present in the settingsForm.
     * - start_min_time_fetching (integer, minimum; default 6):
     *   The minimum amount of seconds start() may take fetching items. If the
     *   time from when start() gets called until items are fetched takes
     *   shorter than this, start() will progress to fetching further batches of
     *   items / caching Sharpspring leads. If not, it may halt execution (and
     *   be called again later) for fear of timeouts. For more info on why: see
     *   the code. This is inexact value: start() may run for this amount of
     *   seconds _plus_ however long it takes to fetch one batch of items.
     *   Ignored if 'sharpspring_no_time_constraints' is true.
     * - start_min_time_fetching_caching (integer; default 10):
     *   The minimum amount of seconds start() may take fetching items and
     *   caching leads. If the time from when start() gets called until the
     *   Sharpspring leads cache is updated takes shorter than this, start will
     *   progress to preprocessing items and returning them. If not, it may halt
     *   execution (and be called an extra time later) for fear of timeouts
     *   while preprocessing. Ignored if 'sharpspring_no_time_constraints' is
     *   true.
     * - sharpspring_no_time_constraints (boolean):
     *   See just above.
     */

    /**
     * Schedule (cron expression) for fully refreshing the key-value store.
     *
     * ONLY IMPLEMENTED FOR UI, not for non-interactive job runs, as long as the
     * Sharpspring API issues noted in start() at 'new LocalLeadCache' are not
     * solved.
     *
     * Having a key-value store introduces risks of skipping updates**, by
     * definition. (Or doing too many updates, but that does not have big
     * consequences except having to deal with Sharpspring's "302 no-op
     * failure".) So we want to purge and repopulate the whole key-value store
     * every once in a while.
     *
     * ** If the updated source object is equal to the cached Lead in the
     * key-value store but the actual Sharpspring lead has a different value.
     * Possible situations could be:
     * - Somehow the Lead was not updated in Sharpspring (because it returned a
     *   false 'success' response? Unfortunately Sharpspring does that) so the
     *   update was only done in the key-value store, preventing a current
     *   'essentially-no-op update' in the source system from going through and
     *   rectifying the situation;
     * - An update was done manually in Sharpspring, and further
     *   'essentially-no-op updates' in the source system will not overwrite the
     *   manual changes. (In our case where the sync is explicitly one-way only,
     *   this seems like "not really our problem/we can't do this well anyway".)
     *
     * We should probably base our value on the likelihood of the first thing
     * happening. Setting to twice a month.
     */
    const KEYVALUE_REFRESH_SCHEDULE = '2 3 12,27 * *';

    /**
     * Overlap period (in seconds) from previous update.
     *
     * If we do not trust updates to show up in the getLeadsDateRange return
     * value immediately, then we can experiment with setting this value. The
     * main goal is to be able to catch values that would have been updated by
     * external processes in the last N seconds, which we would otherwise miss.
     * (For other goals, we can set LEADS_FETCH_AFTER_UPDATE_WAIT.)
     *
     * It is not known whether this value is ever useful in Sharpspring; it was
     * mainly introduced because this kind of lag was observed in other systems,
     * and _some_ kind of update throttling behavior was experienced in
     * Sharprpsing around the beginning of 2017 but that seems to have gone
     * away.
     *
     * Note: once it becomes possible for start() / to start looping to populate
     * LocalLeadCache's cache, it's detrimental for this to be too high.
     */
    const KEYVALUE_UPDATE_OVERLAP = 0;

    /**
     * Wait time in seconds before fetching changed leads on finish().
     *
     * If we do not trust updates to show up in the getLeadsDateRange return
     * value immediately, then we can experiment with setting this value.
     *
     * It is not known whether this is ever useful; it was introduced at a time
     * where the getLeadsDateRange suddenly changed its output format, which was
     * only spotted after this constant was created.
     *
     * This value is effectively adding to the LEADS_UPDATE_WAIT time (which is
     * the number of seconds to sleep after an updateLeads call).
     */
    const LEADS_FETCH_AFTER_UPDATE_WAIT = 0;

    /**
     * The maximum number of leads to create/update in one Sharpspring API call.
     */
    const LEADS_UPDATE_LIMIT = 6;

    /**
     * Wait time in seconds after an update/creaete API call.
     *
     * This is because Sharpspring starts throwing "Service temporarily
     * unavailable, try again in a few minutes" errors if you make too many
     * consecutive updates. It seems like the REST API is not actually done
     * processing after it returns, so a synchronization can overload it.
     *
     * (While I was writing this from jan-mar 2017 this seems to have gone away)
     */
    const LEADS_UPDATE_WAIT = 0;

    /**
     * A mapping for action => action code, as used in start().
     *
     * Should be a private const (but array consts don't work in 5.5). Private
     * because tied to code behavior; must not be changed.
     */
    private $actionCode = [
      'clash_inactive' => 1,
      'clash' => 2,
      'invalid' => 3,
      'deactivate' => 5,
      'new' => 6,
      'update_id' => 7,
      'update_email' => 8,
      'update' => 9,
      'equal' => 10,
      'deactivate_not_present' => 4,
    ];

    /**
     * The key-value store implementation used for our leads cache.
     *
     * Type name is an indicator / for IDE help. There is no formal interface
     * spec yet so the type name may change (without changing behavior).
     *
     * @var \SharpSpring\RestApi\examples\Drupal7SqlStorage
     */
    protected $keyValueStore;

    /**
     * Sharpspring Connection object.
     *
     * This will probably only be used in start() to initialize the leads cache;
     * afterwards, the cache will be used as a 'proxy' connection object.
     *
     * @var \SharpSpring\RestApi\Connection
     */
    protected $sharpSpringConnection;

    /**
     * PSR-3 compatible logger.
     *
     * A NOTE: this DrunkinsJob class has not been converted to use a PSR-3
     * logger yet, so $this->>log() actually logs somewhere else (for now).
     *
     * @todo When DrunkinsJob supports PSR-3 loggers fully, this var might go?
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * A quick place to store logs because I didn't want to duplicate commands.
     *
     * (This class gives no clue what these are used for, but subclasses can do
     * things like send an e-mail report containing them.)
     */
    protected $temporaryLogs;

    /**
     * Constructor function. Sets up the necessary settings.
     */
    public function __construct(array $settings = [])
    {
        // Settings required by this job to have a value:
        if (!isset($settings['start_min_time_fetching']) || !is_numeric($settings['start_min_time_fetching'])) {
            $settings['start_min_time_fetching'] = 6;
        }
        if (!isset($settings['start_min_time_fetching_caching']) || !is_numeric($settings['start_min_time_fetching_caching'])) {
            $settings['start_min_time_fetching_caching'] = 10;
        }
        // We always want to support a rolling timestamp (or a
        // 'fetcher_timestamp_from_date' setting), though this is optional.
        if (!isset($settings['opt_fetcher_timestamp'])) {
            $settings['opt_fetcher_timestamp'] = true;
        }

        parent::__construct($settings);
    }

    /**
     * settingsForm; not part of interface definition; may move to own UI class.
     *
     * It's up to the caller to collect these settings and pass them as the
     * constructor's $settings argument, after which other code can access them.
     * (drunkins module takes care of this.)
     */
    public function settingsForm()
    {
        $form = parent::settingsForm();

        $form['selection']['fetcher_timestamp_ignore']['#title'] .= '. ' . t(' This will also disable Sharpspring contacts which are not found in the source system anymore. (This will ignore "Doublecheck missing contacts" because that would just generate too many useless calls (and possibly exceed request quota), for all disabled contacts in the full source data set.)');

        $form['doublecheck_sharpspring_remotely'] = [
            '#type' => 'checkbox',
            '#title' => t('Doublecheck missing contacts in Sharpspring'),
            '#description' => t("This is necessary if there are inactive contacts in your Sharpspring dataset (because these won't be in the local cache). If you do, and this option is not checked, the synchronization will try to create new Leads for e-mail addresses that are already there, and get errors. On the other hand, if this option is checked it will cause a (often) unnecessary call to Sharpspring for each contact that is not found in the cache, e.g. for new contacts. When there are a bunch of those, this seems likely to cause a temporary block (resulting in timeouts) from the REST API."),
            '#default_value' => !empty($this->settings['doublecheck_sharpspring_remotely']),
            '#states' => ['enabled' => [':input[name="fetcher_timestamp_ignore"]' => ['checked' => false]]],
        ];

        $form['update_inactive'] = [
            '#type' => 'checkbox',
            '#title' => t('Update data of inactive contacts in Sharpspring'),
            '#description' => t("If unchecked, then contacts which are marked as inactive in both Source and Sharpspring will not (necessarily) have their details updated anymore. 'Checked' IS NOT IMPLEMENTED. (Yet?)"),
            '#default_value' => !empty($this->settings['update_inactive']),
            '#disabled' => true,
        ];

        // Extra selectors to make explicit the fact that we also get items from
        // Sharpspring.
        $last_update = $this->getLastLeadCacheUpdateTimeString(true);
        $form['selection']['leads'] = [
            '#type' => 'fieldset',
            '#title' => t('Local leads cache'),
            '#description' => t('We have Sharpspring Leads cached in a local key-value store to minimize API calls. This is completely separate from the above fetched/cached Source items.'),
            '#weight' => 4,
        ];
        $form['selection']['leads']['ss_leads_timestamp_from_date'] = [
            '#type' => 'textfield',
            '#title' => t('Refresh leads cache with items changed in Sharpspring since'),
            '#description' => 'Format: anything which PHP can parse. "-" to skip refreshing the leads cache altogether.',
            '#default_value' => $last_update,
            '#weight' => 1,
            '#states' => ['enabled' => [
                ':input[name="ss_leads_timestamp_ignore"]' => ['checked' => false],
                // No states connected to cache_items, because otherwise
                // refreshing the leads cache would not be possible (if we took
                // "-" as an enforced default when we use cached items.) The
                // disadvantage is that now we HAVE to manually enter "-" each
                // time, if we want to skip fetching from Sharpspring.
//        ':input[name="cache_items"]' => ['!value' => '2'],
            ]],
        ];
        $form['selection']['leads']['ss_leads_timestamp_ignore'] = [
            '#type' => 'checkbox',
            '#title' => t('Ignore "items changed in Sharpspring since"; refresh full cache.'),
            '#default_value' => (!$last_update || $last_update === '-'),
            '#weight' => 2,
//      '#states' => ['enabled' => [':input[name="cache_items"]' => ['!value' => '2'))),
// @TODO! Option in the leads cache to flush only _active_ ones, but to leave the inactive ones intact.
        ];
        if ($last_update && $last_update !== '-') {
            // Check if we should do a full refresh.
            $calculator = new DrunkinsCronTab(static::KEYVALUE_REFRESH_SCHEDULE);
            $next = $calculator->nextTime(strtotime($last_update));
            if ($next <= time()) {
                $form['selection']['ss_leads_timestamp_ignore']['#description'] = "<em>According to the schedule, it's time for a new update!</em>";
                $form['selection']['ss_leads_timestamp_ignore']['#default_value'] = true;
            }
        }

        $form['logging_display'] = [
            '#type' => 'fieldset',
            '#title' => t('Logging / display'),
            '#description' => t('These options govern logging with the "Start batch" action, and display/visibility of items with the "Display" action.'),
            '#weight' => 14,
        ];
        // This option is almost useless because it only governs 'action code 1'
        $form['logging_display']['log_include_clashes'] = [
            '#type' => 'checkbox',
            '#title' => t("Log about / include inactive items that won't be sent because they are duplicates of another active contact (action code 1)"),
            '#weight' => 1,
            '#states' => ['enabled' => [
                ':input[name="fetcher_timestamp_ignore"]' => ['checked' => false],
            ]],
        ];
        // - noops are only doing anything when displaying items.
        // - We want to be able to select the option from the UI for both full
        //   and time-incremental runs.
        // - We have a slight preference for having default true for incremental
        //   runs; it only makes a difference for "Display" but then we won't
        //   wrongly assume from he "No items to process" message that there
        //   were in fact no source updates. This however means that for full
        //   updates, the default is also true. We may reevaluate later.
        $form['logging_display']['log_include_noops'] = [
            '#type' => 'checkbox',
            '#title' => t('Display leads that are already up to date (action code 10)'),
            '#description' => t('This does nothing with the "Start batch" action.'),
            '#default_value' => true,
            '#weight' => 2,
        ];
        $form['logging_display']['display_changed_values'] = [
            '#type' => 'checkbox',
            '#title' => t('Display existing values along with the new values for changed fields.'),
            '#description' => t('This does nothing with the "Start batch" action.'),
            '#default_value' => true,
            '#weight' => 5,
        ];
        return $form;
    }

    /**
     * Detects whether this class is started manually from the UI (not cron).
     *
     * Once there is a better way, this can be replaced.
     *
     * @return bool
     */
    protected function isStartedFromUI()
    {
        // I can't think of a better way to determine 'run from ui' than
        // checking for a setting that is only used in the settingsForm. (This
        // should be a setting that is never conditionally disabled by JS.)
        return isset($this->settings['display_changed_values']);
    }

    /**
     * Fetches/prepares items, returns them to be queued for processItem().
     */
    public function start(array &$context)
    {
        if ($this->isStartedFromUI()) {
            // We consider the absence of both a date and 'ignore' value _for
            // Sharpspring_ to be an error on form submission. (The job can
            // handle it but we don't want users to submit a form like that.)
            // Since we have no form validation yet, we have no better place to
            // check/abort than to throw an exception here on start.
            if (empty($this->settings['ss_leads_timestamp_from_date']) && empty($this->settings['ss_leads_timestamp_ignore'])) {
                throw new RuntimeException("Either 'ss_leads_timestamp_from_date' or 'ss_leads_timestamp_ignore' must have a value.");
            }
        }

        // Fetch items before trying to update the Sharpspring cache (because
        // processing a slightly older dataset using an up-to-date cache is
        // always ok - but processing using a slightly older cache is not).
        $start_time = time();
        while (empty($context['sharpspring_done_fetching_items'])) {
            $fetched_items = parent::start($context);
            // If $context['drunkins_process_more'] is set now, we wont
            // necessarily return as Drunkins jobs usually do. Reason: we cannot
            // return any items for queueing because we need to preprocess the
            // full data set below. So if we return, we have to temporarily
            // store all items in context (which probably gets stored as one big
            // data blob somewhere), and return an empty array now. Prepare:
            if (empty($context['sharpspring_sync_fetched_items'])) {
                $context['sharpspring_sync_fetched_items'] = $fetched_items;
            } elseif ($fetched_items) {
                // array_values() because they will never overwrite others. That
                // was not necessary above because they keys are unimportant.
                $context['sharpspring_sync_fetched_items'] = array_merge($context['sharpspring_sync_fetched_items'], array_values($fetched_items));
            }
            unset($fetched_items);
            // So it becomes a tradeoff: on one hand, we risk timeouts if we
            // continue. On the other hand, if we return [] here, we risk the
            // context being so big that the caller cannot store it. We rank
            // timeouts a higher risk because they are less catchable (so we
            // cannot inform the user if they happen during an automatic
            // process) than database errors for too-big blobs. So we'll only
            // check time. If we are afraid of not having enough time left for
            // preprocessing all items, return and let the parent loop back here
            // later. See comments earlier in this function.
            if (time() - $start_time > $this->settings['start_min_time_fetching']
                && empty($this->settings['sharpspring_no_time_constraints'])) {
                // Time taken is at least C (constant) seconds. Anywhere
                // between C and C+1 seconds may return (as here), or may
                // continue. We could compare using microtime() instead but we
                // are inexact anyway because we don't know how long a fetch
                // takes.
                if (empty($context['drunkins_process_more'])) {
                    // We'll need to set drunkins_process_more to get back here,
                    // but should not fetch items next time.
                    $context['drunkins_process_more'] = TRUE;
                    $context['sharpspring_done_fetching_items'] = TRUE;
                }
                return [];
            }
            if (empty($context['drunkins_process_more'])) {
                // We're done; continue updating Sharpspring cache. We might
                // still pause & loop back later on, so set context:
                $context['sharpspring_done_fetching_items'] = TRUE;
                break;
            }
            // Loop and run start() again. This unfortunately means we need to
            // violate separation of concerns, and do things that are a runner
            // class' responsibility:
            // - unset 'drunkins_process_more'
            // - increment 'drunkins_rerun_start'. (Even though theoretically
            //   this is for use by the runner only, fetchers commonly use this
            //   for determining which batch of a dataset they need to fetch.)
            //   This reflects half the truth: it will reflect how often the
            //   parent start() is re-run, not how often the runner re-runs us.
            //   (We know of no situations yet, where a runner can't handle this
            //   incremented value.)
            // We will not increment 'drunkins_rerun_start_count' here because
            // no items are queued yet; it would become too high when we return
            // the items later; and it is known to be 'more inexact' than
            // 'drunkins_rerun_start' so hopefully no fetchers will use it in a
            // way that is incompatible with this class.
            unset($context['drunkins_process_more']);
            if (!empty($context['drunkins_rerun_start']) && is_int($context['drunkins_rerun_start'])) {
                $context['drunkins_rerun_start']++;
            } else {
                $context['drunkins_rerun_start'] = 1;
            }
        }

        $field_source_id = $this->settings['sharpspring_lead_custom_properties']['sourceId'];
        // Call getSharpSpring() before constructing LocalLeadCache to make sure
        // $this->logger is already initialized.
        $sharpspring = $this->getSharpSpring();
        // Update the Sharpspring cache, unless a previous call just did it.
        if (!isset($context['sharpspring_start_updates'])) {
            // Get new timestamp before we start fetching data from Sharpspring.
            $new_timestamp = time();
            // Initialize cache for Leads.
            $refresh_since = $this->getLastLeadCacheUpdateTimeString();
            $leads_cache = new LocalLeadCache($sharpspring, $this->getKeyValueStore(), $refresh_since, $field_source_id, [], $this->logger);
            // @todo we could build an option to fetch only N Sharpspring leads
            //   at a time (using $refresh_since = '--'), to prevent timeouts,
            //   just like we do with the fetched items above. The issue with
            //   that is: we don't know how long the wait will be so the result
            //   from the next call to Sharpspring getLeads(DateRange) we would
            //   make here (with an offset parameter) isn't really guaranteed to
            //   exactly match the earlier ones. We cannot do this reliably
            //   until Sharpspring gets options to sort the output on a fixed
            //   field (like id,created) & do queries with 'larger/smaller'
            //   filters on that field. OR if Sharpspring guarantees that
            //   the return value from getLeadsDateRange is sorted by the
            //   requested date, so we can just increase the 'from' date in the
            //   query.
            if ($refresh_since !== '-' && $refresh_since !== '--') {
                $this->setLastLeadCacheUpdateTime($new_timestamp);
            }
            // Set start timestamp which we'll use in finish(). (We set our own
            // so it is independent of opt_fetcher_timestamp setting.)
            $context['sharpspring_start_updates'] = $new_timestamp;

            // If we are afraid of not having enough time left for
            // preprocessing all items, return and let the parent loop back here
            // later. It's inexact; see comments earlier in this function.
            if (time() - $start_time > $this->settings['start_min_time_fetching_caching']
                && empty($this->settings['sharpspring_no_time_constraints'])) {
                // This log call will help us distinguish this return point
                // point from the return point above, before cache refresh.
                $this->log('Pausing fetch after refreshing Sharpspring cache.', [], WATCHDOG_DEBUG);
                $context['drunkins_process_more'] = TRUE;
                return [];
            }
        } else {
            // We'll still need a LocalLeadCache, which will again take time
            // reading leads. It's a real shame that we load all leads twice.
            $leads_cache = new LocalLeadCache($sharpspring, $this->getKeyValueStore(), '-', $field_source_id, [], $this->logger);
        }

        // For accounting:
        $context += [
            'sent' => [],
            // Errors in processItem().
            'error' => [],
            // Skipped in start() because of errors (e.g. missing e-mail) or
            // update clashes.
            'skipped' => [],
            // Skipped in start() because equal to Sharpspring contact.
            'equal' => [],
            // Skipped in start() because inactive.
            'inactive' => [],
            // Skipped in start() because inactive duplicate of another
            // contact. (Unlike 'skipped', we don't care about these.)
            'dupes_ignored' => [],
            // Deactivated because removed from the source system. This is a
            // subset of 'sent'/'error': only the above categories add up to
            // the total items processed; this category is not part of it.
            'remove' => [],
            // Separate: mapping of SS id => some values e-mail for update calls
            // made.
            'updated_values' => [],
        ];

        // Preprocess the deduped items. We'll go through all items and check if
        // any of the updates 'clash'. The reason for this are possible edge
        // cases that we want to catch, which are influenced by our setup where
        // - the source item does not have the Sharpspring ID; only the
        //   Sharpspring item has the source ID as a 'foreign key';
        // - in Sharpspring, the e-mail address is unique; not in the source;
        // - the source item can be 'linked' to an existing Sharpspring item by
        //   either the source id == foreign key, or by e-mail (in order of
        //   preference, by compareLead()).
        // - there can be multiple items with the same foreign key in
        //   Sharpspring. (Hopefully not, but there is nothing stopping it. The
        //   'linked' lead for a compared source item will be either the one
        //   that is completely equal, or the 'first' lead, for whatever
        //   definition of first.)
        // Descriptions of edge cases we've thought of so far:
        // 1)
        // The source system turns off Sharpspring-sync for one contact and
        // turns it on for another with the same e-mail address at the same
        // time: the former should be canceled (and the latter will just get
        // seen as an update that happens to change the source-foreign-key too).
        // 2)
        // If one source item changes e-mail address and another one creates a
        // new record with the old e-mail address, we should treat it as such.
        // That is:
        // - despite the fact that our compareLead() check would link the create
        //   operation to the same item in Sharpspring, we should disregard that
        // - we should make sure that the change of e-mail gets processed first.
        // 3)
        // Chained e-mail changes. If source item changes e-mail address and
        // then source item #2 changes e-mail to #1... the order of execution
        // matters: the above works, but executing #2 before #1 will yield an
        // error. Worse, actually: on API v1.117 the update will fail silently
        // while reporting success. So _depending on the order_, we should
        // cancel rename #2. (This happens to be more straightforward than
        // always canceling #2; see below.)
        // 4)
        // E-mail change while there is another record in Sharpspring with the
        // same e-mail address (which has no(nexistent) source ID; otherwise it
        // would be caught by the below): the update will fail but Sharpspring
        // will report success (see the Connection::updateLead comments). We
        // should cancel the update _except_ if the lead is going to be
        // deactivated, in which case it's better to just deactivate it without
        // changing e-mail and without flagging a clash.
        // 5)
        // If there are two contacts with the same e-mail address in the source
        // system which are synced to Sharpspring, the Lead in Sharpspring will
        // likely 'flip-flop':
        // - source contact 1 gets updated/synchronized: the Sharpspring lead
        //   will be updated (including the source ID);
        // - source contact 2 gets synchronized: (assuming this source ID does
        //   not exist in Sharpspring yet, with whatever e-mail address,) this
        //   update will 'steal' the same Sharpspring contact**, meaning the
        //   source ID gets updated to 2, and 1 won't exist anymore in
        //   Sharpspring;
        // - source contact 1 gets updated: 'steals' the same Sharpspring
        //   contact again... etc.
        // ** This will happen as long as the e-mail address from both source
        // contacts stays the same. Apparently no two contacts with the same
        // e-mail can exist in Sharpspring, luckily.
        // To build at least partial detection of these flip-flops (that is, at
        // least within the dataset that we are preprocessing right now), we
        // don't want to process two items that would update the same
        // Sharpspring lead, so we built detection / logging of 'clashes'.
        //
        // During preprocessing, we create structures with 3 values: a lead
        // object constructed from source data (i.e. no Sharpspring ID yet), the
        // ID for the linked Sharpspring lead and a code that signifies the
        // action to take on the lead (which can change as we preprocess more
        // leads) Codes are:
        // 1 - do not update because already-inactive duplicate (clash) of
        //     another contact. (The difference with 2 is we don't care about
        //     logging this.)
        // 2 - do not update because of same-target-sharpspring-lead clash.
        // 3 - do not update because of invalid data. (In practice: empty
        //     e-mail.)
        // 5 - update to be inactive **
        // 6 - new creation (implying no existing lead in Sharpspring has either
        //     the source ID or the e-mail address)
        // 7 - update that changes source ID (implying e-mail stays the same;
        //     otherwise it would not be 'linked' to the Sharpspring contact)
        // 8 - update that changes e-mail (implying source ID stays the same)
        // 9 - update that does not change source ID or e-mail
        // 10- do not update because compared contacts are equal
        // Values are ordered in a way that if we discover two leads would
        // update the same lead in Sharpspring, the higher one 'wins'. Value 1/5
        // won't get log messages about 'clashing' items; value 1/2/3 won't get
        // queued. Not in this list: updates of items which are already inactive
        // in Sharpspring; those get skipped without need for clash detection.
        //
        // Things worth keeping in mind if this logic gets tweaked later:
        // - creates can never 'clash' with updates
        // - the ordering of importance is based on what I figured would be good
        //   to tackle the above documented edge cases. (I'm not sure if I
        //   documented everything)
        // - 7 and 8 'change the game': if compareLead() were called after
        //   updates of these types were done... the outcome might be different.
        //   We don't do anything with this fact here though (except dealing
        //   with above edge case 2 which is a clash between types 7 and 8);
        //   it's just a note.
        //
        // ** value 4 is not used in the loop below; it is used later to signify
        //    items that are deactivated because their corresponding source item
        //    does not exist anymore. This needs to be accounted for separately
        //    from code 5. (Which doesn't mean it needs to get a different code
        //    because those are only temporary... but since we show these codes
        //    in "Display items", displaying a special code there generates less
        //    confusion.)
        $preprocessed_items = $seen_source_ids = $seen_ss_ids = $seen_emails = $this->temporaryLogs = [];
        $lead_key = 0;
        // If the job configuration contains a separate fetcher, parent::start()
        // will return items from it.
        foreach ($context['sharpspring_sync_fetched_items'] as $fetched_item) {
            // Remember the source ID, if we are checking for deleted ones.
            if ($this->hasFullDataset($context)) {
                $seen_source_ids[$fetched_item['id']] = true;
            }

            $lead = $this->convertToLead($fetched_item);
            // Some items (e.g. incomplete leads, with missing e-mail) we still
            // want to run through below comparison and duplicate-checking. But
            // if we got nothing returned, this means we can't even do that.
            // We'll assume the error was logged and just discard it.
            if (!$lead) {
                $context['skipped'][] = $fetched_item['id'];
                continue;
            }
            $lead_action_code = 0;
            if (empty($lead->emailAddress)) {
                // Don't log inactive contacts without e-mail; it's too useless.
                if (!empty($lead->active)) {
                    $this->logAndStore('Missing e-mail address for contact, skipping: @c', ['@c' => $this->getLeadDescription($lead)], WATCHDOG_ERROR);
                }
                // We're still going ahead with the comparison/checking because
                // we want to get a notification if another source item updates
                // the same Sharpspring ID. (Then the user will know they can
                // just disable this item.)
                $lead_action_code = $this->actionCode['invalid'];
            }

            // Compare the lead against our Sharpspring leads cache.
            $lead->leadStatus = 'contact';
            $compare = $leads_cache->compareLead($lead, !empty($this->settings['doublecheck_sharpspring_remotely']));
            if (isset($compare['leadStatus']) && $compare['leadStatus'] === 'contactWithOpp') {
                // We set this leadStatus because we want to update it to
                // 'contact' - except if it is equal to contactWithOpp now; then
                // we cannot update it so if this is the only difference, the
                // lead is actually 'equal'.
                unset($compare['leadStatus']);
            }

            // Check what we would do with this lead if there were no 'clashes'.
            if (!$compare) {
                if (empty($lead->active)) {
                    // An inactive lead does not need to be recorded for dupe
                    // checking.
                    $context['inactive'][] = $fetched_item['id'];
                    continue;
                }
                // Create new lead, unless there was an error.
                $lead_action_code = $lead_action_code ?: $this->actionCode['new'];
            } else {
                if (empty($lead->active)) {
                    if (empty($compare['active'])) {
                        // An inactive-to-inactive update does not to be
                        // recorded for dupe checking. Errors have already been
                        // logged if necessary.
                        $context['inactive'][] = $fetched_item['id'];
                        continue;
                    }
                    // Deactivate. (Also if this has code 2 because no e-mail;
                    // then we have an ID, so deactivating is possible.)
                    $lead_action_code = $this->actionCode['deactivate'];
                    // Check for edge case 4 part 1: the e-mail changes at the
                    // same time but that e-mail already exists in Sharpspring?
                    if (array_key_exists('emailAddress', $compare) && $leads_cache->getLeadsByEmail($lead->emailAddress, false)) {
                        // The deactivate-and-change would fail silently, so
                        // only deactivate. Log, but don't warn the user. This
                        // will likely be the last time we update the record
                        // anyway (since it's inactive in the source system).
                        unset($lead->emailAddress);
                        $this->log('Source record @c1 is being deactivated and changing e-mail address, and starts at address @e1. The new e-mail address already exists in Sharpspring (on a lead with a different source id). We will process the deactivation without changing e-mail.',
                            ['@c1' => $this->getLeadDescription($lead), '@e1' => $compare['emailAddress']], WATCHDOG_NOTICE);
                    }
                }
                if (!$lead_action_code) {
                    // The active, non-error lead has a 'linked' lead in
                    // Sharpspring. Check what we want to do with it.
                    if (count($compare) == 1) {
                        // It's the same (at least all the fields we want to
                        // update from the source system are); skip.
                        $lead_action_code = $this->actionCode['equal'];
                    }
                    // If 'emailAddress' key is not set in $compare, the
                    // existing address is equal to the $lead->emailAddress. (We
                    // already know that exists.)
                    elseif (array_key_exists('emailAddress', $compare)) {
                        $lead_action_code = $this->actionCode['update_email'];
                        // Check for edge case 4 part 2: another lead with same
                        // e-mail.
                        if ($leads_cache->getLeadsByEmail($lead->emailAddress, false)) {
                            $this->logAndStore('Source record @c1 is changing e-mail address and starts at address @e1. The new e-mail address already exists in Sharpspring (on a lead with a different source id). This update is skipped and the leads should be reconciled manually',
                                ['@c1' => $this->getLeadDescription($lead), '@e1' => $compare['emailAddress']], WATCHDOG_WARNING);
                        }
                    }
                    // If {$field_source_id} key is set in $compare, we know the
                    // field exists in $lead AND it is not equal. If the field
                    // is not set in $lead, we classify it as 'equal to the
                    // current value' because that is how our update process
                    // works. (We assume the source field is not nullable.)
                    elseif (array_key_exists($field_source_id, $compare)) {
                        $lead_action_code = $this->actionCode['update_id'];
                    } else {
                        $lead_action_code = $this->actionCode['update'];
                    }
                }

                // Now check if
                // - multiple source items would update the same Sharpspring
                //   lead (not necessary for creates);
                // - multiple source items have the same e-mail address. (It is
                //   possible that this is not caught by the first check, in
                //   cases where e-mails are changed and/or new items would be
                //   created with the same (target) e-mail address.)
                // 1) Check clashing target Sharpspring leads.
                if ($lead_action_code > 2 && isset($seen_ss_ids[$compare['id']])) {
                    // Get the key in $preprocessed_items for the lead with the
                    // highest action-code. (There could be multiple, but
                    // there's maximum one that has not been set to 1 yet.)
                    $other_lead_key = $seen_ss_ids[$compare['id']];
                    $other_lead_code = $preprocessed_items[$other_lead_key][2];
                    // Special handling (documented above as edge case 2):
                    // change of e-mail and change of source ID of the same
                    // Sharpspring lead, should become change of e-mail plus new
                    // lead (executed in that order).
                    if ($lead_action_code == $this->actionCode['update_id'] && $other_lead_code == $this->actionCode['update_email']) {
                        $lead_action_code = $this->actionCode['new'];
                        // Note that $compare still contains another lead, that
                        // is now not supposed to be 'linked' to this lead! We
                        // should check 'linked-ness' through the action code,
                        // not through $compare.
                    } elseif ($lead_action_code == $this->actionCode['update_email'] && $other_lead_code == $this->actionCode['update_id']) {
                        $preprocessed_items[$other_lead_key][2] = $this->actionCode['new'];
                        $preprocessed_items[$other_lead_key][1] = 0;
                    } elseif ($other_lead_code > $this->actionCode['clash']) {
                        if ($lead_action_code <= $other_lead_code) {
                            // If this is an update-to-be-inactive clashing with
                            // another non-error update: it turns out we can
                            // silently ignore this one. (This could be edge
                            // case 1 documented above, but is more likely to be
                            // a contact that is not in Sharpspring in the first
                            // place.)
                            if ($lead_action_code == $this->actionCode['deactivate']) {
                                $lead_action_code = $this->actionCode['clash_inactive'];
                            } else {
                                // If this item had an error (nonexistent
                                // e-mail), we still log it as a clash.
                                $this->logCanceledUpdate($preprocessed_items[$other_lead_key][0], $lead, 'linked to the same Sharpspring contact');
                                $lead_action_code = $this->actionCode['clash'];
                            }
                        } else {
                            // Reverse of above.
                            if ($other_lead_code == $this->actionCode['deactivate']) {
                                $preprocessed_items[$other_lead_key][2] = $this->actionCode['clash_inactive'];
                            } else {
                                $this->logCanceledUpdate($lead, $preprocessed_items[$other_lead_key][0], 'linked to the same Sharpspring contact');
                                $preprocessed_items[$other_lead_key][2] = $this->actionCode['clash'];
                            }
                        }
                    }
                }
            }
            // 2) Check clashing target (possibly changed) e-mail addresses,
            //    which have not been picked up by the check on source ID in
            //    part 1. In practice this kind of clash only happens for mail
            //    changes (which by definition have a source ID) and/or
            //    creations. Mail changes get preference; creations (or a second
            //    rename) should get canceled. Do not check if this update was
            //    just canceled because of a clash above, or if the e-mail
            //    address does not exist.
            if ($lead_action_code > $this->actionCode['clash'] && isset($lead->emailAddress) && isset($seen_emails[$lead->emailAddress])) {
                // Same logic as above except we will not log if the other side
                // is canceled - because that might have been done just before
                // and we could be logging the same.
                $other_lead_key = $seen_emails[$lead->emailAddress];
                $other_lead_code = $preprocessed_items[$other_lead_key][2];
                if ($other_lead_code > $this->actionCode['clash']) {
                    if ($lead_action_code <= $other_lead_code) {
                        $this->logCanceledUpdate($preprocessed_items[$other_lead_key][0], $lead, 'with the same e-mail address');
                        $lead_action_code = $this->actionCode['clash'];
                    } else {
                        $this->logCanceledUpdate($lead, $preprocessed_items[$other_lead_key][0], 'with the same e-mail address');
                        $preprocessed_items[$other_lead_key][2] = $this->actionCode['clash'];
                    }
                }
            }
            // 2a) Check clashing source e-mails which will change with the
            //     update. (Or _would_ change; we also check this for canceled
            //     e-mail changes.)
            if (!empty($compare['emailAddress']) && $compare['emailAddress'] != $lead->emailAddress && isset($seen_emails[$compare['emailAddress']])) {
                // The 'start' address of the e-mail change clashes with another
                // operation. If we get here, that can only be yet another
                // rename. Since that would be processed before us, it would
                // fail so we should cancel it. (This is edge case 3 documented
                // above, and is indeed 'asymmetrical'; if the updates came in
                // in reverse order, nothing would get canceled. Now we don't
                // need to do more tricks and can keep the error message more
                // to the point.)
                $other_lead_key = $seen_emails[$compare['emailAddress']];
                $other_lead_code = $preprocessed_items[$other_lead_key][2];
                if ($other_lead_code > $this->actionCode['invalid']) {
                    $this->logAndStore('Source record @c1 is changing e-mail address and starts at address @e1. This clashes with another record, whose update we are skipping and which should be checked/processed manually: @c2',
                        ['@c1' => $this->getLeadDescription($lead), '@e1' => $compare['emailAddress'], '@c2' => $this->getLeadDescription($preprocessed_items[$other_lead_key][0])], WATCHDOG_WARNING);
                    $preprocessed_items[$other_lead_key][2] = $this->actionCode['clash'];
                }
            }

            // Now queue the lead up. Also if it's an error / clash, because we
            // want to log all extra clashes with it explicitly. (These won't
            // cause different behavior but the user will at least see them.)
            if (!$lead_action_code) {
                throw new RuntimeException("Internal error (code should be changed): could not determine what to do with contact {$this->getLeadDescription($lead)}.");
            } else {
                $preprocessed_items[$lead_key] = [$lead, $lead_action_code === $this->actionCode['new'] ? 0 : $compare['id'], $lead_action_code];
                // Update the 'seen' caches. (We know that if we are >3, any
                // existing lead referenced in there currently will have been
                // set to 1 or 2.)
                if ($compare && ($lead_action_code > $this->actionCode['invalid'] || !isset($seen_ss_ids[$compare['id']]))) {
                    $seen_ss_ids[$compare['id']] = $lead_key;
                }
                if (!empty($lead->emailAddress) && ($lead_action_code > $this->actionCode['invalid'] || !isset($seen_emails[$lead->emailAddress]))) {
                    $seen_emails[$lead->emailAddress] = $lead_key;
                }
                $lead_key++;

                if (!empty($this->settings['list_format']) && !empty($this->settings['display_changed_values'])
                    && $lead_action_code !== $this->actionCode['new'] && count($compare) > 1) {
                    // Add the 'changed' old values into the lead. We'll need to
                    // convert fieldnames to properties. (Do this after we don't
                    // need the lead properties anymore, for above caching. the
                    // lead will still get updated inside $preprocessed_items.)
                    // @todo this is not fully done yet - at the moment we add HTML tags
                    //    but do not escape values, and print the full value (with HTML)
                    //    escaped in the list. (Which obviously is weird - but at least we
                    //    can derive what changed.) This needs custom display code.
                    foreach ($compare as $field => $value) {
                        if ($field !== 'id') {
                            // array_search is slow but it seems better than
                            // creating another lookup index just for the (non-
                            // time critical) display case.
                            $property = array_search($field, $this->settings['sharpspring_lead_custom_properties']);
                            if ($property === false) {
                                $property = $field;
                            }
                            if (!isset($value) || $value === '') {
                                $value = '-';
                            }
                            $lead->$property = "<del>$value</del><br>" . $lead->$property;
                        }
                    }
                }
            }
        }
        unset($context['sharpspring_sync_fetched_items']);
        unset($seen_emails);
        if (empty($this->settings['ss_leads_cache_unset_after_start'])) {
            $context['ss_leads_cache'] = $leads_cache;
        }
        unset($leads_cache);

        // If we have a full source data set, check if some source items are not
        // seen anymore. If not, the Sharpspring contacts should be deactivated.
        if ($this->hasFullDataset($context)) {
            $offset = 0;
            do {
                $leads = $this->getKeyValueStore()->getAllBatched(1024, $offset);
                foreach ($leads as $lead_array) {
                    // Checking if an item is 'seen in the source system' is
                    // done by checking all items we've processed so far (which
                    // will also catch items that will be 'renumbered' but not
                    // items that were skipped above), plus all source IDs
                    // collected.
                    if (!isset($seen_ss_ids[$lead_array['id']])
                        && !empty($lead_array[$field_source_id]) && !isset($seen_source_ids[$lead_array[$field_source_id]])
                        && !empty($lead_array['active'])
                        && isset($lead_array['leadStatus']) && ($lead_array['leadStatus'] === 'contact' || $lead_array['leadStatus'] === 'contactWithOpp')
                    ) {
                        // This should be deactivated, and accounted for
                        // separately.
                        $context['remove'][] = $lead_array[$field_source_id];
                        $lead_array['active'] = 0;
                        // It's strange to convert this lead into an object but
                        // that way we can keep the below code the same (in the
                        // 'display' as well as 'process' case).
                        $lead = new LeadWithSourceId($lead_array, $this->settings['sharpspring_lead_custom_properties']);
                        // Use code 4 which was not used above.
                        $preprocessed_items[] = [$lead, $lead_array['id'], $this->actionCode['deactivate_not_present']];
                    }
                }
                $offset = count($leads) == 1024 ? $offset + 1024 : 0;
            } while ($offset);
        }
        unset($seen_ss_ids);
        unset($seen_source_ids);

        // Now that we know all action codes, we can construct the items for
        // processing. Make separate groups for creates and updates.
        $items = [];
        if (!empty($this->settings['list_format'])) {
            // When creating a list for display, you can display extra
            // properties (which would not be sent into Sharpspring) if you
            // set these with an underscore in your convertToLead(), and
            // specify them in the following setting:
            $extra_properties = isset($this->settings['sharpspring_lead_list_extra_properties']) && is_array($this->settings['sharpspring_lead_list_extra_properties'])
              ? $this->settings['sharpspring_lead_list_extra_properties'] : [];
            $include_noops = !empty($this->settings['log_include_noops']);
            $include_clashes = !empty($this->settings['log_include_clashes']);
            foreach ($preprocessed_items as $item) {
                if (($item[2] != $this->actionCode['equal'] || $include_noops)
                    && ($item[2] != $this->actionCode['clash_inactive'] || $include_clashes)
                ) {
                    // We just converted the array to an object; now back to an
                    // array... (This does not map the properties back to system
                    // field names, on purpose.) .
                    $items[] = $item[0]->toArray($extra_properties) + ['*ssid' => $item[1], '*action code' => "$item[2]: " . array_search($item[2], $this->actionCode) ];
                }
            }
            unset($preprocessed_items);
        } else {
            $creates = [];
            foreach ($preprocessed_items as $item) {
                switch ($item[2]) {
                    case $this->actionCode['clash_inactive']:
                        $context['dupes_ignored'][] = $item[0]->sourceId;
                        break;
                    case $this->actionCode['clash']:
                    case $this->actionCode['invalid']:
                        $context['skipped'][] = $item[0]->sourceId;
                        break;
                    case $this->actionCode['equal']:
                        $context['equal'][] = $item[0]->sourceId;
                        break;
                    case $this->actionCode['new']:
                        // Doublecheck: must be creates.
                        if ($item[1]) {
                            throw new RuntimeException("Internal error (code should be changed): Item is marked as create but is still marked internally as having a Sharpspring ID $item[1]: " . json_encode($item[0]));
                        }
                        $creates[] = $item[0];
                        break;
                    default:
                        // Doublecheck: must be updates.
                        if (!$item[1]) {
                            throw new RuntimeException("Internal error (code should be changed): Item is marked as update but still has no Sharpspring ID: " . json_encode($item[0]));
                        }
                        $item[0]->id = $item[1];
                        $items[] = $item[0];
                }
            }
            unset($preprocessed_items);

            // One big operation, to not reference 2 big arrays with 2
            // variables. Do updates before creations because it minimizes the
            // chance of clashes if an update changes e-mail address; see above.
            $items = array_merge(
                empty($items) ? [] : array_chunk($items, self::LEADS_UPDATE_LIMIT),
                empty($creates) ? [] : array_chunk($creates, self::LEADS_UPDATE_LIMIT)
            );
        }

        return $items;
    }


    /**
     * Log about a lead not being updated because of another coinciding update.
     *
     * Since this is sometimes not useful, settings govern the actual logging.
     * (E.g. when processing a full dataset that we know has duplicate e-mail
     * addresses, logging would add nothing.) As this is a private method, we
     * can still change it to take e.g. the action codes into account.
     */
    protected function logCanceledUpdate(LeadWithSourceId $kept_lead, LeadWithSourceId $skipped_lead, $descn)
    {
        $this->logAndStore("Source system contains duplicate records $descn: @c1 and @c2. One of them should have Sharpspring synchronization disabled. We are skipping the latter.",
            ['@c1' => $this->getLeadDescription($kept_lead), '@c2' => $this->getLeadDescription($skipped_lead)], WATCHDOG_WARNING);
    }

    protected function logAndStore($message, array $variables = [], $severity = WATCHDOG_NOTICE)
    {
        $this->log($message, $variables, $severity);
        // Store this until we send the e-mail. We don't make a difference
        // between errors and warnings. (Maybe we should. Maybe not.)
        $this->temporaryLogs[] = t($message, $variables);
    }

    /**
     * Signifies whether we are processing a full data set.
     */
    protected function hasFullDataset(array &$context)
    {
      return $this->callFetcherMethod('hasFullDataSet', false, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function processItem($item, array &$context)
    {
        // An item is supposed to be an array of leads that all need to be
        // either created or updated. Doublecheck the format to protect against
        // inadvertent code changes / bugs. One property always exists for us:
        // 'active'. (Not id.)
        /** @var LeadWithSourceId[] $item */
        if (!isset($item[0]->active)) {
            // t() in exceptions? meh.
            throw new RuntimeException(t('Invalid format for queued item to process: @item', ['@item' => json_encode($item)]));
        }
        $create_new = empty($item[0]->id);
        foreach ($item as $lead) {
            if (empty($lead->id) != $create_new) {
                // We could split them and send create/update statements
                // separately instead of throwing an exception, but we shouldn't
                // have to do that.
                throw new RuntimeException(t("Invalid format for queued item to process; it has 'create' and 'update' leads mixed up: @item", ['@item' => json_encode($item)]));
            }
        }

        // Create/update data in Sharpspring.
        $method = $create_new ? 'createLeads' : 'updateLeads';
        try {
            $result = $this->getSharpSpring($context)->$method($item);
            // It sucks, but we can't trust our updates; they may have failed
            // (but returned a success code) if the e-mail address changed and
            // there's another record with the same e-mail address in the db. So
            // we need to doublecheck that the lead is actually in Sharpspring
            // and that the e-mail address is actually updated. We'll do it in
            // finish() getting all updated items with one query.

            // Do accounting of successful updated/created items.
            foreach ($item as $i => $lead) {
                // We record the source IDs for the items sent, just like
                // elsewhere. But in 'sent', we'll try to key them by
                // Sharpspring ID; who knows the record will be useful somehow.
                // For updates, we already had the Sharpspring ID; for creates,
                // we just got it returned.
                $ss_id = !empty($lead->id) ? $lead->id : (!empty($result[$i]['id']) ? $result[$i]['id'] : '');
                $src_id = !empty($lead->sourceId) ? $lead->sourceId : $ss_id;
                if (empty($ss_id)) {
                    // This should never happen. We won't bother with the
                    // e-mail doublecheck here.
                    $context['sent'][] = $src_id;
                } else {
                    $context['sent'][$ss_id] = $src_id;
                    if (!$create_new) {
                        $context['updated_values'][$ss_id]['emailAddress'] = $lead->emailAddress;
                        if (isset($lead->active)) {
                            $context['updated_values'][$ss_id]['active'] = $lead->active;
                        }
                    }
                }
            }
        } catch (SharpSpringRestApiException $e) {
            if ($e->isObjectLevel()) {
                // At least one object-level error was encountered but some
                // leads may have been updated successfully. (Since we have
                // called XXXateLeads, not XXXateLead, this exception is always
                // a 'wrapper' around the actual object errors.) Do logging /
                // accounting for each.
                foreach ($e->getData() as $i => $object_result) {
                    // Derive Sharpspring ID and source ID like above.
                    $ss_id = !empty($item[$i]->id) ? $item[$i]->id : (!empty($object_result['id']) ? $object_result['id'] : '');
                    $src_id = !empty($item[$i]->sourceId) ? $item[$i]->sourceId : $ss_id;
                    try {
                        $this->getSharpSpring()->validateObjectResult($object_result);
                        // Creates don't have an ID; updates do.
                        if (empty($item[$i]->id)) {
                            // We're hoping this will just get a 'low' number,
                            // so we won't get confused with Sharpspring IDs. It
                            // won't be a huge deal tho.
                            $context['sent'][] = $src_id;
                        } else {
                            $context['sent'][$ss_id] = $src_id;
                            $context['updated_values'][$ss_id]['emailAddress'] = $item[$i]->emailAddress;
                            if (isset($item[$i]->active)) {
                                $context['updated_values'][$ss_id]['active'] = $item[$i]->active;
                            }
                        }
                    } catch (SharpSpringRestApiException $e) {
                        $compare = false;
                        if (in_array($e->getCode(), [301, 302])) {
                            // We got a "entry already exists" or "no table rows
                            // affected" error. (We assume upon create or
                            // update, respectively.)
                            $compare = $this->recheckEqualLead($item[$i], $context);
                        }
                        if (is_array($compare) && count($compare) == 1) {
                            // Upon a re-check, our lead appears equal to
                            // Sharpspring. This seems to be caused by our cache
                            // being out of date, then. Warn.
                            $this->log('Sharpspring REST API call @method on source id @id encountered error @c: @e:. On further checking, the lead in Sharpspring is already equal. This indicates that our local leads cache is probably outdated.', ['@method' => $method, '@id' => $src_id, '@c' => $e->getCode(), '@e' => $e->getMessage()], WATCHDOG_WARNING);
                        } elseif ($compare === [] && empty($item[$i]->active)) {
                            // Upon a re-check, our lead appears to be removed
                            // from Sharpspring. (For an lead update with
                            // active=0, this apparently yields a 302 "no table
                            // rows affected".) Since we wanted to deactivate
                            // it, that is not a huge deal but our cache is out
                            // of date, just like above here.
                            $this->log('Sharpspring REST API call @method on source id @id encountered error @c: @e. On further checking, the lead does not exit anymore in Sharpspring. This indicates that our local leads cache is probably outdated.', ['@method' => $method, '@id' => $src_id, '@c' => $e->getCode(), '@e' => $e->getMessage()], WATCHDOG_WARNING);
                        } else {
                            // Log anything else as error.
                            $this->log('Sharpspring REST API call @method on source id @id encountered: @e', ['@method' => $method, '@id' => $src_id, '@e' => (string)$e], WATCHDOG_ERROR);
                        }
                        // We don't want to create a new category for the edge
                        // cases that we logged as a warning, so just add it to
                        // 'error'. ('sent' is not good for things that are not
                        // actually updated, as long as we doublecheck the
                        // contents of 'sent' in finish().)
                        $context['error'][] = $src_id;
                    } catch (Exception $e) {
                        // A non-SharpSpringRestApiException is extremely
                        // unlikely, but we treat it the same.
                        $context['error'][] = $src_id;
                        $this->log('Sharpspring REST API call @method on source id @id encountered: @e', ['@method' => $method, '@id' => $src_id, '@e' => (string)$e], WATCHDOG_ERROR);
                    }
                }
            } else {
                // API-level error. Assume none of the leads have been created.
                foreach ($item as $lead) {
                    $context['error'][] = $lead->sourceId;
                }
                // Casting to string yields a description of the whole exception
                // including type & data (good; we need it) and backtrace
                // (unnecessary).
                $this->log('Sharpspring REST API call @method threw: @e', ['@method' => $method, '@e' => (string)$e], WATCHDOG_ERROR);
            }
        } catch (Exception $e) {
            // Same as the API-level error above.
            foreach ($item as $lead) {
                $context['error'][] = $lead->sourceId;
            }
            $this->log('Sharpspring REST API call @method threw: @e', ['@method' => $method, '@e' => (string)$e], WATCHDOG_ERROR);
        }

        if (static::LEADS_UPDATE_WAIT) {
            sleep(static::LEADS_UPDATE_WAIT);
        }
    }

    /**
     * Check if a lead is already updated in Sharpspring.
     *
     * In practice this is a 'recheck' of a lead which we thought was updatable;
     * we now actually check Sharpspring instead of our local cache, and make
     * sure that our cache is updated too.
     *
     * @param \SharpSpring\RestApi\examples\LeadWithSourceId $lead
     *   A lead object.
     * @param array $context
     *   The job context.
     *
     * @return array|false
     *   False if we could not perform the equality check. Otherwise the same
     *   returnvalue as LocalLeadCache::compareLead(), that is: empty array
     *   means the lead was not found, 1 array value means the lead is equal and
     *   a larger array means it differs.
     */
    protected function recheckEqualLead(LeadWithSourceId $lead, array $context)
    {
        if (!isset($context['ss_leads_cache'])) {
            return false;
        }

        /** @var \SharpSpring\RestApi\LocalLeadCache $cache */
        $cache = $context['ss_leads_cache'];
        if (empty($lead->id)) {
            // Assume emailAddress exists if id does not; otherwise we would
            // never get here.
            $compare = $cache->getLeads(['emailAddress' => $lead->emailAddress]);
        } else {
            $compare = $cache->getLeadRemote($lead->id);
        }

        // If we didn't get anything returned here, we don't need to call
        // compareLead() because that would return the same (empty array).
        if ($compare) {
            $compare = $cache->compareLead($lead, false);
        }
        return $compare;
    }

    /**
     * {@inheritdoc}
     */
    public function finish(array &$context)
    {
        $this->temporaryLogs = [];

        // As mentioned in processItems(), we unfortunately can't be sure that
        // updates which returned success, actually succeeded. So retrieve
        // updates made since we started, and check whether all updates that we
        // *think* succeeded, are among them.
        $sent_ss_ids = $context['sent'];
        if ($sent_ss_ids) {
            if (empty($context['sharpspring_start_updates']) || !is_int($context['sharpspring_start_updates'])) {
                $this->log('Start timestamp was lost! Now we cannot doublecheck whether all updates actually succeeded.', [], WATCHDOG_ERROR);
            } else {
                if (static::LEADS_FETCH_AFTER_UPDATE_WAIT) {
                    sleep(static::LEADS_FETCH_AFTER_UPDATE_WAIT);
                }
                $ignore_cache = $this->getLastLeadCacheUpdateTime() == -1;
                $sharpspring = $this->getSharpSpring($ignore_cache ? [] : $context);
                $new_timestamp = time();
                // Get leads updated since updates were started.
                $since = date('Y-m-d H:i:s', $context['sharpspring_start_updates'] - static::KEYVALUE_UPDATE_OVERLAP);
                $leads = $sharpspring->getLeadsDateRange($since, '', 'update', 5000);
                // If our local cache was updated too, then increment the timestamp so
                // that we don't need to get these leads next time.
                if ($sharpspring instanceof LocalLeadCache) {
                    $this->setLastLeadCacheUpdateTime($new_timestamp);
                }
                // Subtract all leads we get back here, from all 'sent' leads.
                // Doing it by Sharpspring ID rather than source ID seems a bit
                // more precise, since we're only interested in updates (for
                // which we have them).
                foreach ($leads as $lead) {
                    if (isset($context['updated_values'][$lead['id']]['emailAddress'])
                        && $context['updated_values'][$lead['id']]['emailAddress'] !== $lead['emailAddress']) {
                        // We've been hit by the updateLeads bug that does not
                        // actually update things. Convert lead to object just
                        // for logging - it is strange but oh well...
                        $source_email = $context['updated_values'][$lead['id']]['emailAddress'];
                        $lead_obj = new LeadWithSourceId($lead);
                        if ($sharpspring instanceof LocalLeadCache) {
                            $this->logAndStore("Lead @id/@lead has e-mail address @email in the source system, but could not be updated in Sharpspring, probably because another lead with the same e-mail exists. This might get fixed on the next process run; if it does not, it will need manual action.",
                                ['@id' => $lead['id'], '@lead' => $this->getLeadDescription($lead_obj), '@email' => $source_email], WATCHDOG_ERROR);
                            // Apparently the lead with the target e-mail is not
                            // in our cache (and apparently that is because it's
                            // disabled), so get this lead in our cache and see
                            // if the comparison in start() will yield different
                            // results next time.
                            $sharpspring->getLeadsByEmail($source_email);
                        } else {
                            // We'll log a more detailed message for this but
                            // in the end we don't know how to fix this
                            // automatically so fall through to the below.
                            $this->logAndStore("Lead @id/@lead has e-mail address @email in the source system, but could not be updated in Sharpspring, probably because another lead with the same e-mail exists (which is probably disabled and therefore invisible in Sharpspring). This will need manual action (or reconfiguration of the job).",
                                ['@id' => $lead['id'], '@lead' => $this->getLeadDescription($lead_obj), '@email' => $source_email], WATCHDOG_ERROR);
                        }
                    }
                    unset($sent_ss_ids[$lead['id']]);
                }
                if ($sent_ss_ids) {
                    // getLeadsDateRange has suddenly started excluding inactive
                    // leads so we cannot check these anymore, (So the whole
                    // checking code from here on down is wonky. Still, we don't
                    // want to completely delete it - it might warn os of
                    // changes in the API like these...)
                    foreach (array_keys($sent_ss_ids) as $ss_id) {
                        // If we _know_ this contact is inactive, don't warn.
                        // (It's not water tight if the 'active' property was\
                        // not always set... though maybe start() can't even
                        // work with that.)
                        if (isset($context['updated_values'][$ss_id]['active']) && empty($context['updated_values'][$ss_id]['active'])) {
                            unset($sent_ss_ids[$ss_id]);
                        }
                    }
                }
                // Any keys now left in $sent_ss_ids have apparently not been
                // updated. (They could be creates rather than updates, but that
                // should not matter - we should also have gotten those back so
                // we're hoping at least all creates were removed from
                // $sent_ss_ids by now. If there are any 'small' numbers among
                // the keys, we should recheck our code because at least in the
                // 'sent' case we expect to have only valid Sharpspring IDs.
                if ($sent_ss_ids) {
                    // Create a list of source + sharpspring IDs.
                    $ids = [];
                    foreach ($sent_ss_ids as $ss_id => $src_id) {
                        $ids[] = "$src_id/$ss_id";
                    }
                    $this->logAndStore('@count out of @total updates that were apparently sent to Sharpspring, are still not found / updated in Sharpspring; this should be investigated! List of source / Sharpspring IDs: @list',
                        ['@count' => count($ids), '@total' => count($context['sent']), '@list' => implode(',', $ids)], WATCHDOG_ERROR);
                    // @todo if we really wanted, then rather than giving up we could
                    //   redo the whole process. If e-mails / source IDs were changed,
                    //   the checks in start() may have a slightly different outcome - and
                    //   updates which are already done will get compared 'successfully'
                    //   and not re-done. If we get back here and the amount of errors
                    //   is not lower, we can continue and do the error summary.
                    // (This has not been necessary yet; we have not encountered
                    // the situation where $sent_ss_ids is nonempty.)
                }
            }
        }

        // Run the parent/fetcher finish(). This will save fetcher_timestamp.
        parent::finish($context);

        // Not sure yet whether we want to make this configurable in other ways.
        // For now, include some lists of IDs only for incremental runs. (This
        // means we do display them if we're running from cached items.)
        $include_ids_sent = $include_ids_not_sent = !$this->hasFullDataset($context);

        $message = format_plural(count($context['sent']), '1 contact sent to Sharpspring', '@count contacts sent to Sharpspring');
        if (count($context['sent'])) {
            $log_message = 'Synced to Sharpspring: @count' . ($include_ids_sent ? ' (@items)' : '');
            // Always include the @items parameter. If inquisitive people want
            // to search the log backend, they can.
            $this->log($log_message, ['@count' => count($context['sent']), '@items' => implode(', ', $context['sent'])], WATCHDOG_DEBUG);
        }
        if ($context['remove']) {
            $message .= ', ' . count($context['remove']) . ' of which were deactivated because apparently removed from the source system';
            $log_message = 'Deactivated because apparently removed from the source system: @count (@items). (These items are also logged as "synced", unless they are logged as "error encountered".)';
            $this->log($log_message, ['@count' => count($context['remove']), '@items' => implode(', ', $context['remove'])], WATCHDOG_DEBUG);
        }
        if ($context['skipped']) {
            $message .= '; ' . count($context['skipped']) . ' not sent because errors / update clashes seen beforehand';
            $this->log('Not sent to Sharpspring because errors / update clashes seen beforehand: @count (@items). Details may have been logged earlier (dependent on job settings).', ['@count' => count($context['skipped']), '@items' => implode(', ', $context['skipped'])], WATCHDOG_ERROR);
        }
        if ($context['error']) {
            $message .= '; ' . format_plural(count($context['error']), '1 error encountered during sending', '@count errors encountered during sending');
            $this->logAndStore('Summary of earlier detailed logs: errors encountered during sending: @count (@items).', ['@count' => count($context['error']), '@items' => implode(', ', $context['error'])], WATCHDOG_ERROR);
        }
        if ($context['drunkins_exception_count']) {
            $message .= '; ' . format_plural($context['drunkins_exception_count'], '1 exceptions thrown during sending', '@count exceptions thrown during sending');
            $this->logAndStore('Summary of earlier detailed logs: exceptions thrown during sending: @count.', ['@count' => count($context['drunkins_exception_count'])], WATCHDOG_ERROR);
        }
        if ($context['equal']) {
            $message .= '; ' . count($context['equal']) . ' not sent because seemingly equal';
            $log_message = 'Not sent to Sharpspring because seemingly equal: @count' . ($include_ids_not_sent ? ' (@items)' : '');
            $this->log($log_message, ['@count' => count($context['equal']), '@items' => implode(', ', $context['equal'])], WATCHDOG_DEBUG);
        }
        if ($context['inactive']) {
            $message .= '; ' . count($context['inactive']) . ' not sent because inactive';
            $log_message = 'Not sent to Sharpspring because inactive: @count' . ($include_ids_not_sent ? ' (@items)' : '');
            $this->log($log_message, ['@count' => count($context['inactive']), '@items' => implode(', ', $context['inactive'])], WATCHDOG_DEBUG);
        }
        if ($context['dupes_ignored']) {
            $message .= '; ' . count($context['dupes_ignored']) . ' duplicate (inactive) contacts ignored';
            $this->log('Inactive duplicates of other contacts, ignored: @count.', ['@count' => count($context['dupes_ignored']), '@items' => implode(', ', $context['dupes_ignored'])], WATCHDOG_DEBUG);
        }

        return $message . '.';
    }

    /**
     * Gets the time to refresh our local leads cache, as a string expression.
     *
     * @param bool $for_display
     *   (optional) if true, changes the rules of deriving a bit and return in
     *   local time. If false / not provided: return in a format that the
     *   LocalLeadConstructor class can take.
     *
     * @return string
     *   If $for_display is false: a time, '' (for full refresh) or '-' (for no
     *   refresh). If $for_display is true / not provided: same but subject to
     *   different rules of deriving.
     *
     * @throws \RuntimeException
     *   If settings are invalid and $for_display is false.
     */
    protected function getLastLeadCacheUpdateTimeString($for_display = false)
    {
        $timestamp = $this->getLastLeadCacheUpdateTime($for_display);
        if ($timestamp) {
            if ($timestamp === -1) {
                $date = '-';
            } elseif ($for_display) {
                $date = date('Y-m-d\TH:i:s', $timestamp);
            } else {
                $date = date('Y-m-d H:i:s', $timestamp);
            }
        } else {
            $date = '';
        }

        return $date;
    }

    /**
     * Derives the time from which our local leads cache should be refreshed.
     *
     * @param bool $for_display
     *   (optional) if true, changes the rules of deriving a bit: no exceptions
     *   are thrown and the 'ignore' setting / cron check is ignored (since that
     *   is displayed separately).
     *
     * @return int
     *   The timestamp of latest update, possibly influenced by an 'overlap'
     *   constant and by settings which are usually set from the UI only. 0 if
     *   the cache should be considered fully outdated. -1 to completely
     *   ignore the time, and take the cache as it is now.
     *
     * @throws \RuntimeException
     *   If settings are invalid and $for_display is false.
     */
    protected function getLastLeadCacheUpdateTime($for_display = false)
    {
        if (!$for_display && !empty($this->settings['ss_leads_timestamp_ignore'])) {
            $timestamp = 0;
        } elseif (!empty($this->settings['ss_leads_timestamp_from_date'])) {
            if ($this->settings['ss_leads_timestamp_from_date'] === '-') {
                $timestamp = -1;
            } elseif ($this->settings['ss_leads_timestamp_from_date'] !== '--') {
                // Note that we don't subtract the overlap here. If this setting
                // has a value, it's supposed to have the overlap subtracted
                // already.
                $timestamp = strtotime($this->settings['ss_leads_timestamp_from_date']);
                if (!$timestamp) {
                    if (!$for_display) {
                        throw new RuntimeException("Invalid 'ss_leads_timestamp_from_date' setting specified; not a parseable date expression.");
                    }
                    $timestamp = 0;
                }
            } else {
                if (!$for_display) {
                    // This is too dangerous (and could be a typo when '-' was
                    // meant);
                    throw new RuntimeException("'ss_leads_timestamp_from_date' setting must not be '--'.");
                }
                // We don't support '--'. Interpret as '-'.
                $timestamp = -1;
            }
        } else {
            // For the variable names we assume that the job IDs include the
            // module name and will therefore be namespaced alreaady.
            $timestamp = variable_get($this->settings['job_id'] . '_ts_kvupdate', 0);
            if ($timestamp) {
                $timestamp -= static::KEYVALUE_UPDATE_OVERLAP;
                // Check the cron schedule to see if a full refresh is due.
                // @todo We should refresh from cron too, not only UI. But this
                //    is held up by Sharpspring API issues noted in start().
                if (!$for_display && $this->isStartedFromUI()) {
                    $calculator = new DrunkinsCronTab(static::KEYVALUE_REFRESH_SCHEDULE);
                    $next = $calculator->nextTime($timestamp);
                    if ($next <= time()) {
                        $timestamp = 0;
                    }
                }
            }
        }

        return $timestamp;
    }

    /**
     * Sets the time from which our local leads cache should be refreshed next.
     *
     * @param bool $timestamp
     *   The timestamp from which our local leads cache should be refreshed next
     *   time (disregarding the 'overlap' which will still be applied to it).
     */
    protected function setLastLeadCacheUpdateTime($timestamp)
    {
        variable_set($this->settings['job_id'] . '_ts_kvupdate', $timestamp);
    }

    /**
     * Returns the key-value store implementation used for our leads cache.
     */
    protected function getKeyValueStore()
    {
        if (!isset($this->keyValueStore)) {
            $this->keyValueStore = new Drupal7SqlStorage('ict_comm_sharpspring_leads');
        }

        return $this->keyValueStore;
    }

    /**
     * Returns Connection (or cache) configured to create leads in our account.
     *
     * Note this may not actually return a Connection object; it may be a
     * LocalLeadCache object which has the same CRUD methods defined.
     *
     * @param array $context
     *   If this has a 'ss_leads_cache' set inside, return that (and assume it's
     *   ready for use).
     *
     * @return \SharpSpring\RestApi\Connection;
     */
    protected function getSharpSpring($context = [])
    {
        if (isset($context['ss_leads_cache'])) {
            return $context['ss_leads_cache'];
        }

        if (!isset($this->sharpSpringConnection)) {
            // @todo this might change; see @todo at $this->logger.
            $this->logger = psr3_logger(['log_type' => 'sharpspring_sync']);
            $client = new CurlClient([
                'account_id' => $this->settings['sharpspring_api_account_id'],
                'secret_key' => $this->settings['sharpspring_api_secret_key']
            ]);
            $this->sharpSpringConnection = new Connection($client, $this->logger);
            if (!empty($this->settings['sharpspring_lead_custom_properties'])) {
                $this->sharpSpringConnection->setCustomProperties('lead', $this->settings['sharpspring_lead_custom_properties']);
            }
        }

        return $this->sharpSpringConnection;
    }

    /**
     * Create Sharpspring lead from an array of source data.
     *
     * A subclass should override this method, based on the source items
     * received through the configured fetcher class. The below will only
     * return null so the sync will not do anything.
     *
     * This is meant to be run from start(), not processItem(), so it can get
     * compared to existing lead data and equal / invalid items items don't get
     * queued for updating/creating.
     *
     * @param array $source_item
     *   Contact data as returned from our source system/fetcher.
     *
     * @return LeadWithSourceId|null
     *   Sharpspring data, or null for data error (in which case we've logged).
     */
    protected function convertToLead(array $source_item)
    {
        $lead = new LeadWithSourceId();
        $lead->sourceId = $source_item['id'];
        $lead->emailAddress = $source_item['mail'];
        $lead->active = 0;
        $lead->firstName = '';
        $lead->lastName = '';
        return null;
    }

    /**
     * Returns a human readable 'unique enough' description for this lead.
     *
     * @param \SharpSpring\RestApi\examples\LeadWithSourceId $lead
     *
     * @return string
     */
    public function getLeadDescription(LeadWithSourceId $lead)
    {
        $name = !empty($lead->firstName) ? $lead->firstName . ' ' : '';
        // : ( !empty($item['initials']) ? $item['initials'] . ' ' : '');
        //$name .= !empty($item['infix']) ? $item['infix'] . ' ' : '';
        $name .= !empty($lead->lastName) ? $lead->lastName . ' ' : '';
        if (!$name) {
            // This is never true
            $name = '? ';
        }
        if (!empty($lead->companyName)) {
            // This is always true
            $name .= '/ ' . $lead->companyName . ' ';
        }
        // We want the e-mail address to be part of it because it's going to be
        // used for comparisons between leads - the e-mail is important to see
        // what's exactly going on.
        if (!empty($lead->emailAddress)) {
            // This is always true
            $name .= '(' . $lead->emailAddress . ')';
        }
        return rtrim($name);
    }
}
