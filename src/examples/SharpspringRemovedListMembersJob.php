<?php

namespace SharpSpring\RestApi\examples;

use DrunkinsJob;
use Exception;
use UnexpectedValueException;
use SharpSpring\RestApi\Connection;
use SharpSpring\RestApi\CurlClient;

/**
 * Drunkins Job class to disable removed list menbers in whatever source system.
 *
 * Code doing anything in a source system is missing, so this is an abstract
 * class which should have at least its getFieldToUpdate() and doUpdateAction()
 * extended (or its whole processItem() redone).
 *
 * @todo if someone has a heavily used system, they should test whether any
 *   call to the getRemovedListMembers API method is capped at a certain amount,
 *   like getLeads is capped at 500. If so, the code in start() should be
 *   amended to do repeated API calls with offsets. I lack info on how this
 *   should work in practice, so far.
 */
abstract class SharpspringRemovedListMembersJob extends DrunkinsJob
{
    /* Settings in $this->settings, used by this class:
     * - sharpspring_api_account_id (string)
     * - sharpspring_api_secret_key (string)
     * - sharpspring_lead_custom_properties][sourceId (See the sync job.)
     * - list_format
     *
     * - start_min_time_fetching (integer; default 10):
     *   The minimum amount of seconds start() may take fetching items. If the
     *   time from when start() gets called until items are fetched takes
     *   shorter than this, start() will return the full data set in one go. If
     *   not, it may halt execution (and be called again later) for fear of
     *   timeouts. For more info on why: see the code. This is inexact value:
     *   start() may run up to this amount of seconds _plus_ however long it
     *   takes to complete the next API call. Ignored if
     *   'sharpspring_no_time_constraints' is true.
     * - sharpspring_no_time_constraints (boolean):
     *   See just above.
     */

    /**
     * Sharpspring Connection object.
     *
     * @var \SharpSpring\RestApi\Connection
     */
    protected $sharpSpringConnection;

    /**
     * Constructor function. Sets up the necessary settings.
     */
    public function __construct(array $settings = [])
    {
        // Settings required by this job to have a value:
        if (!isset($settings['start_min_time_fetching']) || !is_numeric($settings['start_min_time_fetching'])) {
            $settings['start_min_time_fetching'] = 10;
        }

        parent::__construct($settings);
    }

    /**
     * Returns Sharpspring Connection configured for our account.
     *
     * @return \SharpSpring\RestApi\Connection;
     */
    protected function getSharpSpringConnection()
    {
        if (!isset($this->sharpSpringConnection)) {
            $client = new CurlClient([
                'account_id' => $this->settings['sharpspring_api_account_id'],
                'secret_key' => $this->settings['sharpspring_api_secret_key']
            ]);
            $this->sharpSpringConnection = new Connection($client);
        }

        return $this->sharpSpringConnection;
    }

    /**
     * Determine from a RemovedListMember, which fields we want to update.
     *
     * This is an example method only; the keys in the return values are total
     * guesses so this method should be extended
     *
     * @param array $removed_list_member
     *   The RemovedListMember object from Sharpspring.
     * @param bool $priority
     *   (optional) If true, instead of the values to update, return the
     *   priority of this specific update (so if we have two RemovedListMembers
     *   for the same lead, we can make sure we do the right update. It is at
     *   this moment not known whether this situation even exists, but we
     *   account for it...) 0 if no update should be done.
     *
     * @return array|int
     *   If $priority is empty: A two-element array: Source field name to update
     *   in the destination (non-Sharpsping) contact, and value to update it to.
     */
    protected function getFieldToUpdate(array $removed_list_member, $priority = false)
    {
        // So far, we have observed the following combinations of values:
        // isRemoved isUnsubscribed hardBounced
        //     1            1            1
        //     1            1            0
        //     1          null           0
        // ...which suggests that the below is the appropriate priority order.
        // (We don't know for sure if the above is always true, i.e. whether
        // hardBounced=1 and isUnsubscribed=0 can actually never exist; we have
        // no formal spec.)
        if (!empty($removed_list_member['hardBounced'])) {
            return $priority ? 3 : ['bounced', true];
        } elseif (!empty($removed_list_member['isUnsubscribed'])) {
            return $priority ? 2 : ['newsletter_opt_out', true];
        } elseif (!empty($removed_list_member['isRemoved'])) {
            return $priority ? 1 : ['newsletter_subscribed', false];
        }

        return $priority ? 0 : [];
    }

    /**
     * Do the update of a contact field in a remote system.
     *
     * @param array $lead
     *   The lead.
     * @param array $update_action
     *   The field to update, see getFieldToUpdate() return value.
     * @param array $removed_list_member
     *   The list member data of the lead, which prompted the update.
     * @param array $context
     *   The process context.
     *
     * @return bool
     *   True on successful update. False if update was skipped. (In other
     *   circumstances where 'skipped' does not really apply, an exception is
     *   probably thrown.)
     */
    protected function doUpdateAction(array $lead, array $update_action, array $removed_list_member, array &$context)
    {
        // DO HERE: Update the destination contact and return true (in which
        // case $context['update_cache'] is set by the parent). OR: decide that
        // contact update should be skipped and return false; in this case,
        // $context['update_cache'] is not set by the parent, so do it yourself
        // if applicable. OR, in case of real error instead of just "skip":
        // throw an exception.
        // $lead['emailAddress'] === $removed_list_member['emailaddress'] and
        // $lead['id'] == $removed_list_member[leadID] (unless overridden in
        // subclass?)
        return false;
    }

    /**
     * Loads cache from somewhere, containing data about processed list members.
     *
     * @return array
     *   A cache of listmember/lead data which can be used to determine that the
     *   data should not be updated in the remote system anymore, i.e. start()
     *   does not need to queue these items. Array keys: the 'item keys' as they
     *   are available in start() from the getRemovedListMembers API call:
     *   <leadID:emailaddress>. Value: either false (which signifies the data
     *   cannot be updated for some reason so queueing/processing the item would
     *   be useless) or an array of at least one "field name => value" that was
     *   previously updated (with the fieldnames/values coming from
     *   getFieldToUpdate()).
     */
    protected function loadUpdateCache()
    {
        return [];
    }

    /**
     * Saves the update cache somewhere.
     *
     * @param array $cache
     *   The cache to save.
     * @param array $context
     *   The process context, for whatever reason it may be useful.
     */
    protected function saveUpdateCache(array $cache, array $context)
    {
    }

    /**
     * Check settings.
     *
     * @param array $context
     *   Context as passed to start() / processItem().
     *
     * @throws \Exception
     *   If not all settings are as expected.
     */
    protected function checkSettings(array $context)
    {
        foreach (['sharpspring_api_account_id', 'sharpspring_api_secret_key'] as $setting) {
            if (empty($this->settings[$setting])) {
                throw new Exception("$setting setting not configured!");
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function start(array &$context)
    {
        // This is the last place where we can do checks on strangeness that
        // will make processItem() not do anything. (Better throw once here than
        // <items> times later.)
        $this->checkSettings($context);

        $context += [
            // Processed.
            'disabled' => [],
            // Skipped in processItem() because already disabled. (To be exact:
            // because the specific disable-field value we wanted to change,
            // already has the value we wanted to set.)
            'skipped' => [],
            // Error in processItem().
            'error' => [],
            // Error in start(). Unlike the 3 above, this does not add up to the
            // number of items queued.
            'error_not_queued' => [],
            // Skipped in start(), because apparently we know already that the
            // contact was disabled.
            'skipped_not_queued' => [],
        ];

        $connection = $this->getSharpSpringConnection();

        // Gather the destination contact IDs that should be disabled, as items.
        // Take into account that we might have looped back from a previous
        // call to start(), with 'fetched_items' already in context.
        $start_time = time();
        $items = isset($context['fetched_items']) ? $context['fetched_items'] : [];
        // This may prevent unneeded memory usage when $items gets changed and
        // will also prevent $context['fetched_items'] from containing unneeded
        // data at the end of fetching:
        $context['fetched_items'] = [];
        if (!isset($context['sharpspring_start_api_calls'])) {
            $context['sharpspring_start_api_calls'] = $this->apiCallsForStart();
        }
        while ($args = array_shift($context['sharpspring_start_api_calls'])) {
            $removed_type = $args[1];
            $members = $connection->getRemovedListMembers($args[0], $removed_type);
            $i = 0;
            foreach ($members as $member) {
                // $member has:
                // - a listID, listMemberID, and leadID
                // - emailaddress, firstName and lastName
                // - isRemoved, isUnsubscribed and hardBounced ("0" or "1")
                // We will let processItem() deal with checking whether a
                // removed lead still has the same e-mail address (and not
                // process it if it doesn't). We collect and deduplicate
                // RemovedListMembers from all lists here; that means we will
                // dedupe by a combination of leadID + emailaddress.
                if (empty($member['leadID']) || empty($member['emailaddress'])) {
                    $this->log('RemovedListMember object has empty leadID/email: @list_member', ['@list_member' => json_encode($member)], WATCHDOG_ERROR);
                    // The actual below value is not so important...
                    $context['error_not_queued'][] = (isset($member['listID']) ? $member['listID'] : '?') . ":$removed_type:" . (isset($member['listMemberID']) ? $member['listMemberID'] : $i);
                } else {
                    $member_key = "$member[listID]:$removed_type:$member[listMemberID]";
                    $item_key = "$member[leadID]:$member[emailaddress]";

                    $update_priority = $this->getFieldToUpdate($member, true);
                    if (!$update_priority) {
                        $this->log('RemovedListMember object @key does not result in an update operation (does the job code need to be changed?): @list_member', ['@key' => $member_key, '@list_member' => json_encode($member)], WATCHDOG_ERROR);
                        $context['error_not_queued'][] = $member_key;
                    } elseif (!isset($items[$item_key]) || $update_priority > $items[$item_key]) {
                        $items[$item_key] = $member;
                    }
                    // Duplicate RemovedListMembers are silently discarded.
                }
                $i++;
            }

            // If we need to make more calls, check if we are not running too
            // long already, to prevent timeouts.
            if (!empty($context['sharpspring_start_api_calls'])
                && empty($this->settings['sharpspring_no_time_constraints'])
                && time() - $start_time > $this->settings['start_min_time_fetching']) {
                $context['drunkins_process_more'] = TRUE;
                // Since we may change existing items later, keep them in
                // context and return them all at the end.
                $context['fetched_items'] = $items;
                $items = [];
                break;
            }
        }

        // Skip items if the 'update cache' already contains a value for items
        // that is either false, or signifies that the field to update already
        // has this value.
        if ($items) {
            if (!isset($context['update_cache'])) {
                $context += [
                    'update_cache' => $this->loadUpdateCache(),
                    'update_cache_time' => time(),
                ];
            }
            if ($context['update_cache']) {
                foreach ($items as $key => $member) {
                    $reset = false;
                    if (isset($context['update_cache'][$key])) {
                        if ($context['update_cache'][$key] === false) {
                            // processItem() has somehow signified that this
                            // item cannot be updated in the destination system.
                            $reset = true;
                        } else {
                            $update_action = $this->getFieldToUpdate($member);
                            if (isset($context['update_cache'][$key][$update_action[0]])
                                && $context['update_cache'][$key][$update_action[0]] === $update_action[1]
                            ) {
                                // Destination item already has this value.
                                $reset = true;
                            }
                        }
                    }
                    if ($reset) {
                        if (!empty($this->settings['list_format'])) {
                            $items[$key]['*action'] = 'skip';
                        } else {
                            unset($items[$key]);
                        }
                    }
                }
            }
        }

        return $items;
    }


    /**
     * Gets a list of API calls to make during start().
     *
     * The logic in this function contains assumptions about several API
     * calls' undocumented return values.
     *
     * @return array
     *   A list of two-element arrays: arguments to getRemovedListMembers API
     *   calls to make. (List ID and flag.)
     *
     * @todo As mentioned above: maybe this should be extended with limit/offset
     *       (or maybe that should be implemented in another way), eventually.
     */
    protected function apiCallsForStart() {
        // The lists are static (ish), but it probably does not hurt to get them
        // every time. We need to do per-list calls for the list members anyway.
        $lists = $this->getSharpSpringConnection()->getActiveLists();
        $api_calls = [];
        foreach ($lists as $list) {
            // Two assumptions / notes:
            // 1) Given
            // - the fact that a list has only a 'removedCount' property and not
            //   e.g. a 'unsubscribedCount' / 'hardbouncedCount',
            // - the field values (combination of flags) observed in the return
            //   value of getRemovedListMembers calls,
            // we assume removedCount is non-zero if there are *any* bounces
            // or unsubscriptions, so we don't need to check all flags. We check
            // either only 'removed', or nothing.
            //
            // 2) There are cases where 'removedCount' is zero but still
            // getRemovedListMembers(ID, 'removed') returns ListMembers. So far
            // we're assuming that these are 'old' members (for whatever
            // undocumented definition of "old") which would have been reset
            // already - so we won't care about them.
            if (!empty($list['removedCount'])) {
                $api_calls[] = [$list['id'], 'removed'];
            }
        }

        return $api_calls;
    }

    /**
     * {@inheritdoc}
     */
    public function processItem($item, array &$context)
    {
        $this->checkSettings($context);

//    try {
        $lead = $this->getLeadForMember($item, $context);
        if (!$lead) {
            // A fatal error would have thrown an exception; this non-fatal one
            // also implies that we don't need to try the same next time. So,
            // cache this. (It also implies that the 'item key' can be derived.)
            $context['update_cache']["$item[leadID]:$item[emailaddress]"] = false;
            // We already logged.
            return;
        }
// No need for logging if the runner already does that. If not, uncomment this:
//    }
//    catch (Exception $e) {
//      $context['error'][] = $item_key;
//      $this->log('Failed retrieving Lead for RemovedListMember @key: @message', ['@key' => $item_key, '@message' => $e->getMessage()], WATCHDOG_ERROR);
//      // We don't know if this error is temporary or permanent; anyway it is
//      // system related, not data related. Rethrow the exception instead of
//      // returning, and let the runner deal with it (and log/account for it) as
//      // it wants.
//      throw $e;
//    }

        $item_key = "$item[leadID]:$item[emailaddress]";
        $update_action = $this->getFieldToUpdate($item);
        if (!$update_action) {
            // This is 'impossible'; members without updates are not queued.
            throw new UnexpectedValueException("RemovedListMember object $item_key does not result in an update operation despite being queued; this should never happen: " . json_encode($item), 2);
        }

        if ($this->doUpdateAction($lead, $update_action, $item, $context)) {
            $context['disabled'][] = $item_key;
            $context['update_cache'][$item_key][$update_action[0]] = $update_action[1];
        } else {
            // We don't know whether/what we should do to update_cache, so we
            // leave setting this to the subclass' doUpdateAction() if False.
            $context['skipped'][] = $item_key;
        }
    }

    /**
     * Gets a lead object that should be updated, for a RemovedListMember.
     *
     * Adds an error to the context for conditions that yield no updatable lead;
     * throws an exception for 'real' errors (including failed getLead calls).
     *
     * @param array $member
     *   A RemovedListMember object.
     * @param array $context
     *   The Drunkins context.
     *
     * @return array
     *   The Lead, or empty array for some conditions that don't yield a lead
     *   that should be updated.
     *
     * @throws \UnexpectedValueException
     *   If the RemovedListMember has an unrecognized structure or empty
     *   required fields.
     */
    protected function getLeadForMember(array $member, array &$context)
    {
        // Check for some more conditions which are 'impossible' unless the
        // queue has been polluted, because these items should not be queued:
        if (empty($member['leadID']) || empty($member['emailaddress'])) {
            $context['error'][] = json_encode($member);
            // Since this is something really unexpected (as opposed to the
            // below data 'errors' that lead to us returning an empty array), we
            // throw an exception here. The parent, or the drunkins runner, can
            // decide to do with it.
            throw new UnexpectedValueException('RemovedListMember object has empty leadID/email; this should never happen: ' . json_encode($member), 1);
        }
        $item_key = "$member[leadID]:$member[emailaddress]";

        // Get Lead from Sharpspring, to see if it still has the same e-mail
        // address and optionally get its source ID.
        $lead = $this->getSharpSpringConnection()->getLead($member['leadID']);
        if (!$lead) {
            $this->log('No Lead found for RemovedListMember $item_key.', [], WATCHDOG_NOTICE);
            $context['error'][] = $item_key;
            return [];
        }
        if ($lead['emailAddress'] !== $member['emailaddress']) {
            $this->log('Lead for RemovedListMember $item_key has changed e-mail address in the meantime (from @before to @after; skipping.', ['@before' => $member['emailaddress'], '@after' => $lead['emailAddress']], WATCHDOG_INFO);
            $context['skipped'][] = $item_key;
            return [];
        }
        if (!empty($this->settings['sharpspring_lead_custom_properties']['sourceId'])
            && empty($lead[$this->settings['sharpspring_lead_custom_properties']['sourceId']])
        ) {
            // We're going to mark this 'not an error', since nothing says
            // Sharpspring contacts _must_ be present in the source system.
            $this->log("No 'source ID' field value found in Lead for RemovedListMember @key.", ['@key' => $item_key], WATCHDOG_NOTICE);
            $context['skipped'][] = $item_key;
            return [];
        }

        return $lead;
    }

    /**
     * {@inheritdoc}
     */
    public function finish(array &$context)
    {
        $message = parent::finish($context);

        // We only use counts here; not the values in the arrays. (The only use
        // for these values so far is manual inspection of stored context data.)

        $message .= format_plural(count($context['disabled']), 'Disabled 1 contact', 'Disabled @count contacts');
        if ($context['skipped'] || $context['skipped_not_queued']) {
            $message .= '; ' . (count($context['skipped']) + count($context['skipped_not_queued'])) . ' skipped because disabling is unnecessary/obsolete';
        }
        if ($context['error_not_queued']) {
            $message .= '; ' . format_plural(count($context['error_not_queued']), '1 error encountered before queueing items', '@count errors encountered before queueing items');
        }
        if ($context['error']) {
            $message .= '; ' . format_plural(count($context['error']), '1 error encountered during processing', '@count errors encountered during processing');
        }
        if ($context['drunkins_exception_count']) {
            $message .= '; ' . format_plural(count($context['drunkins_exception_count']), '1 exceptions thrown during processing', '@count exceptions thrown during processing');
        }

        $this->saveUpdateCache($context['update_cache'], $context);

        return $message . '.';
    }
}
