<?php

namespace SharpSpring\RestApi;

use InvalidArgumentException;
use UnexpectedValueException;
use Psr\Log\LoggerInterface;

/**
 * A cache to locally store/get Lead data retrieved from Sharpspring.
 *
 * The reason for this class is that during a data synchronization process from
 * contacts in a 'source' system into Sharpspring (which runs regularly),
 * - We want to compare all Leads that were updated in the source system against
 *   those present in Sharpspring before sending in updates, because we want to
 *   prevent doing superfluous updates. (The main reason is that the update
 *   function of the REST API has been unreliable; it often responds with a
 *   "system temporarily unavailable" error message. We obviously want to
 *   minimize this happening. Also, because doing a 'no-op' update results in
 *   an object-level error 302 "No table rows affected".)
 * - For that, we would like to retrieve Lead objects by the source system's ID
 *   but the REST API has no way of doing that - so we need to retrieve them all
 *   and locally index them by source ID.
 * - This obviously does not scale in terms of memory usage.
 *
 * So what we'll do is:
 * - Retrieve all leads and store them in some key-value store backend (which
 *   is swappable so we might still store them in memory if ever possible);
 * - While doing this, build an index by source ID and keep this in memory
 *   (inside this class), along with some other often-needed properties.
 *
 * This has the effect that we don't actually need to get all leads, every time
 * we do a synchronization process; the rest API has a getLeadsDateRange method
 * which we can use to get and refresh just the latest changes in our key-value
 * store.
 *
 * This incremental updating only works, however, if the cache is kept up to
 * date from the caller's side, by performing all create/update/delete
 * operations through 'proxy' methods instead of calling the REST API directly.
 * (Preferrably 'retrieve' operations too.)
 *
 * Please be aware that this class does not and cannot give guarantees that its
 * cache is actually complete even when doing this. Reasons include (but may
 * not be limited to) the fact that new updates can come into the source system
 * from other sources, the fact that inactive leads are not retrieved, and the
 * fact that items which are deleted directly in Sharpspring (rather than having
 * 'active' set to 0) will not be noticed until our key-value store is fully
 * refreshed. The user is invited to make their own determination on how safe it
 * is to use this cache, and how long to trust doing incremental updates
 * (meaning: instantiating this class with the $refresh_cache_since parameter).
 */
class LocalLeadCache
{

    /**
     * The maximum number of leads to retrieve in one Sharpspring API call.
     *
     * Maximum 500 (this is the hard cap by Sharpspring, apparently)
     */
    const LEADS_GET_LIMIT = 500;

    /**
     * SharpSpring REST Client object.
     *
     * @var \SharpSpring\RestApi\Connection
     */
    protected $sharpSpringConnection;

    /**
     * The key-value store.
     *
     * Type name is an indicator / for IDE help. There is no formal interface
     * spec yet so the type name may change (without changing behavior).
     *
     * @var \SharpSpring\RestApi\examples\Drupal7SqlStorage
     */
    protected $keyValueStore;

    /**
     * PSR-3 compatible logger object.
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * Sharpspring IDs, indexed by e-mail address. (Reverse lookup cache #1.)
     *
     * The keys are e-mail addresses, always in lower case. (Sharpspring may
     * have an e-mail address stored with uppercase letters; its lookup through
     * e.g. getLeads() is case insensitive and creating multiple e-mail
     * addresses differing case yields error 301 "Entry already exists", as it
     * should.) Values are single-value arrays (numerically indexed). They can
     * theoretically be multi-value but that doesn't happen in practice because
     * Sharpspring properly prevents that.
     *
     * @var array
     */
    protected $sharpspringIdsByEmail;

    /**
     * Sharpspring IDs, indexed by foreign key. (Reverse lookup cache #2.)
     *
     * The values are very often single-value arrays (numerically indexed), but
     * can theoretically be multi-value.
     *
     * @var array
     */
    protected $sharpspringIdsByForeignKey;

    /**
     * An in-memory cache of often-needed properties, indexed by primary key.
     *
     * This is meant to be kept in memory (and is constructed from the key-value
     * store before using), so we do not have to query the key value store
     * repeatedly for these properties.
     *
     * For space reasons (at least when serializing this object), the properties
     * are numerically indexed.
     *
     * @var array
     */
    protected $propertyCache = [];

    /**
     * The property names corresponding to the data in the in-memory cache.
     *
     * @var array
     */
    protected $cachedProperties = [];

    /**
     * The name of the foreign key (a.k.a. source ID).
     *
     * This is the actual field name in Sharpspring, not the name of the
     * property on a Lead object.
     *
     * @var string
     */
    protected $foreignKey;

    /**
     * Constructor.
     *
     * The constructor is expensive: it will read and cache all active leads
     * locally before returning. (There does not seem to be a better place to
     * do this, though this might change later.)
     *
     * @param \SharpSpring\RestApi\Connection $connection
     *   The SharpSpring REST Client.
     * @param object $key_value_store
     *   The key-value store. (Not type hinted because we have no formal spec.)
     * @param string $refresh_cache_since
     *   If empty, this method will clear the local cache and read / cache all
     *   (active) leads from the Sharpspring account. If set to a time
     *   representation, only the updates since then will be read and cached on
     *   top of the current contents of the cache. (The format is the same as
     *   expected by the getLeadsDateRange, which has unfortunately changed once
     *   in the past without the API version 1.117 changing with it! See
     *   Connection::getLeadsDateRange().) If '-', refreshing the cache will be
     *   skipped. If '--', populating the in-memory caches will also be skipped,
     *   which means that the class will be unusable until the caller re-fetches
     *   leads using cacheAllLeads() or related functionality.
     * @param string $foreign_key
     *   (optional) The name of the foreign key field / source ID field. Leads
     *   can be retrieved by this field, though there is no guarantee that
     *   inactive leads are found. This needs to be the actual field name in
     *   Sharpspring, not the  name of the property on a PHP Lead object.
     * @param array $cached_properties
     *   (optional) Properties to index by in-memory cache. This means
     *   getPropertyValue will not need to query the key-value store, for these
     *   properties (Note e-mail address and the foreign key are not
     *   automatically part of this; they are only used to populate reverse
     *   lookup indices to get the Sharpspring ID, by default.)
     * @param \Psr\Log\LoggerInterface $logger
     *   (optional) A logger.
     *
     * @throws \UnexpectedValueException
     *   If the key-value store somehow got corrupted and leads are not arrays.
     *
     * @see Connection::getLeadsDateRange()
     */
    public function __construct(Connection $connection, $key_value_store, $refresh_cache_since, $foreign_key = null, array $cached_properties = [], LoggerInterface $logger = null)
    {
        // @todo generalize foreignKey so we can have more than one reverse-lookup
        // cache (besides e-mail)?
        $this->foreignKey = $foreign_key;
        $this->cachedProperties = $cached_properties;
        $this->sharpSpringConnection = $connection;
        $this->keyValueStore = $key_value_store;
        $this->logger = $logger;

        if (!empty($refresh_cache_since) && $refresh_cache_since !== '--') {
            // Populate in-memory cache. (We would be able to 'lazy-load' this;
            // it would be a bit of effort to make sure that every method call
            // will still work with an up to date cache. Not sure if that is
            // worth the effort yet / if this class is ever going to be
            // instantiated without needing to have a full cache or while
            // needing the constructor to be inexpensive.)
            $this->propertyCache = $this->sharpspringIdsByForeignKey = $this->sharpspringIdsByEmail = [];
            $offset = 0;
            do {
                $leads = $this->keyValueStore->getAllBatched(1024, $offset);
                foreach ($leads as $sharpspring_id => $lead_array) {
                    if (!is_array($lead_array)) {
                        throw new UnexpectedValueException("Lead value $sharpspring_id in key-value store is not an array: " . json_encode($lead_array));
                    }
                    $this->updateMemoryCaches($lead_array);
                }
                $offset = count($leads) == 1024 ? $offset + 1024 : 0;
            } while ($offset);
        }

        if ($refresh_cache_since !== '-' && $refresh_cache_since !== '--') {
            // Populate or complete the cache.
            $this->cacheAllLeads($refresh_cache_since);
        }
    }

    /**
     * Compares a lead (array or object) against data in Sharpspring.
     *
     * This can be called to see if lead data from an external system (which has
     * been converted to a lead object somehow) should be updated in
     * Sharpspring; this is the main reason for this class existing. See the
     * class description.
     *
     * @param \SharpSpring\RestApi\Lead|array $external_lead
     *   The lead to compare, which must have at least the id, the foreign key
     *   field or the e-mail address set - because that's how they are matched.
     *   (Sharpspring cannot query by other properties so would always return an
     *   empty array - if no Exception was thrown.) If an array key is not
     *   present (or a non-nullable class property is null) in the input, this
     *   will not be used in comparing (just like it would not be sent in update
     *   / create calls), so a value that is only set in the corresponding
     *   Sharpspring data will not cause the comparison to fail. If it is
     *   desired to explicitly compare an empty field in a Lead input object, it
     *   should be explicitly set to an empty string (or to null, for a nullable
     *   property).
     * @param bool $check_remotely
     *   (optional) If false, assume our local cache is already complete and do
     *   not make a call to the Sharpspring REST API to doublecheck. In practice
     *   this can get expensive if the leads to compare have no ID and there
     *   could be a lot of e-mail addresses/foreign keys IDs that are not
     *   present in our Sharpspring cache (yet) - so the caller may want to pass
     *   false if it's sure there are no inactive leads in Sharpspring (or if it
     *   it doesn't care about them).
     *
     * @return array
     *   The 'id' value of the compared lead plus all the values in the
     *   original object which differ from the compared input value. That is:
     *   - an empty array means no existing leads could be matched (by id if
     *     provided, and otherwise by either foreign key or by e-mail address in
     *     that order)
     *   - an array with one value means all values in the input lead are equal
     *     to the compared existing lead, and the one value is the existing id.
     *   - an array with more values means the input lead differs. (Careful with
     *     using isset(), because a value is null if the existing lead's value
     *     is not set.) Other notes:
     *   - For custom fields, the keys are always the actual field value in
     *     Sharpspring, not the name of the custom property in a lead object.
     *   - It is possible this method returns an empty array when there is a
     *     lead in Sharpspring with the same data... if the input lead has no ID
     *     and the lead in Sharpspring is inactive.
     *
     * @throws \InvalidArgumentException
     *   If no IDs/email properties are set in the lead object.
     */
    public function compareLead($external_lead, $check_remotely = true)
    {
        $external_lead = $this->sharpSpringConnection->toArray('lead', $external_lead);

        // Matching to the Sharpspring lead object is done on Sharpspring ID if
        // the 'id' field is populated (though we don't expect it to be),
        // otherwise on foreign key (which is what this class is really meant
        // for), and if that is empty, on e-mail address.
        $leads = [];
        if (!empty($external_lead['id'])) {
            $lead = $this->getLead($external_lead['id'], $check_remotely);
            if ($lead) {
                $leads = [$lead];
            }
        } else {
            if (!empty($this->foreignKey) && !empty($external_lead[$this->foreignKey])) {
                $leads = $this->getLeadsByForeignKey($external_lead[$this->foreignKey]);
            }
            if (!$leads && !empty($external_lead['emailAddress'])) {
                $leads = $this->getLeadsByEmail($external_lead['emailAddress'], $check_remotely);
            } elseif (empty($this->foreignKey) || empty($external_lead[$this->foreignKey])) {
                throw new InvalidArgumentException('The provided Lead object has no ID / e-mail values.');
            }
        }

        // Ignore one field in the compared lead: updateTimestamp. (It is not
        // clear why anyone would populate it, but we are not going to care.)
        unset($external_lead['updateTimestamp']);

        // Theoretically there can be multiple leads (though in practice that
        // would only happen if you'd pass a lead with a foreign key and no ID /
        // e-mail). Comparison is OK if one of the leads in Sharpspring compares
        // against the given lead object.
        $return = [];
        foreach ($leads as $sharpspring_lead) {
            // If the key exists in the 'external' lead, then we want to compare
            // it against the Sharpspring lead (also if its value is not set).
            // We can't do strict comparison of the arrays because the
            // Sharpspring lead will in practice contain strings, also for
            // integer values. (Because that's how the REST API returns JSON. We
            // don't want to depend on this staying this way forever, though.)
            // We also can't do non-strict comparison because we want to see
            // differences between '' and 0. We compare e-mail case
            // insensitively like Sharpspring does.
            $diff = ['id' => $sharpspring_lead['id']];
            foreach ($external_lead as $key => $value) {
                $external_value_set = isset($value) && $value !== '';
                $sharpspring_value_set = isset($sharpspring_lead[$key]) && $sharpspring_lead[$key] !== '';
                if ($sharpspring_value_set !== $external_value_set
                    || ($key === 'emailAddress' ? strtolower($sharpspring_lead[$key]) !== strtolower($value)
                        : (string)$sharpspring_lead[$key] !== (string)$value)) {
                    $diff[$key] = isset($sharpspring_lead[$key]) ? $sharpspring_lead[$key] : null;
                }
            }
            if (count($diff) == 1) {
                // Everything compares OK.
                $return = $diff;
                break;
            }
            // If there are multiple differing leads, return the first one.
            if (!$return) {
                $return = $diff;
            }
        }
        return $return;
    }

    /**
     * Returns property value for a Sharpspring lead.
     *
     * @param string $property
     *   Property name.
     * @param int $sharpspring_id
     *   The lead's ID in Sharpspring.
     * @param bool $check_remotely
     *   (optional) If false, assume our local cache is already complete and do
     *   not make a call to the Sharpspring REST API to doublecheck. Default
     *   true (because if the caller has a Sharpspring ID, it probably knows
     *   what it's doing and we'll assume that if we don't have it cached here,
     *   that may be because it's inactive. Or worse, the key-value cache is out
     *   of date).
     *
     * @return mixed
     *   The value, or null if the property does not exist in the item OR if the
     *   item does not exist.
     */
    public function getPropertyValue($property, $sharpspring_id, $check_remotely = true)
    {
        if (isset($this->propertyCache[$sharpspring_id])) {
            $key = array_search($property, $this->cachedProperties, true);
            if ($key !== false) {
                // Get value from in-memory cache; if it isn't there, then get
                // remotely.
                return isset($this->propertyCache[$sharpspring_id][$key]) ? $this->propertyCache[$sharpspring_id][$key] : null;
            }
        }

        // Get value from key-value store, or remotely.
        $lead = $this->getLead($sharpspring_id, $check_remotely);
        return isset($lead[$property]) ? $lead[$property] : null;
    }

    /**
     * Retrieves lead(s) (arrays, not objects) by e-mail address.
     *
     * If fetched remotely, the lead is cached in the key-value store and the
     * in-memory cache is updated. This can be important for fetching inactive
     * leads which cacheAllLeads() does not do.
     *
     * @param string $email
     *   The e-mail address.
     * @param bool $check_remotely
     *   (optional) If false, assume our local cache is already complete and do
     *   not make a call to the Sharpspring REST API to doublecheck.
     *
     * @return array
     *   Zero or more lead structures. (It really should be maximum 1 because we
     *   cannot update a second lead to the same e-mail address. But who knows
     *   what Sharpspring is capable of - the documentation does not specify it
     *   and it accepts bogus e-mail addresses - so we won't make guarantees
     *   about the API result.)
     */
    public function getLeadsByEmail($email, $check_remotely = true)
    {
        $email = strtolower($email);
        $ids = !empty($this->sharpspringIdsByEmail[$email]) ? $this->sharpspringIdsByEmail[$email] : [];

        $leads = [];
        if ($ids) {
            foreach ($ids as $id) {
                // We assume this never does a remote call because our in-memory
                // lookup caches are in sync with our key-value store data.
                $lead = $this->getLead($id);
                if ($lead) {
                    $leads[] = $lead;
                } else {
                    $this->log('error', 'LocalLeadCache internal error: Sharpspring object {id} not found, while its id was cached by e-mail {email}.', ['id' => $id, 'email' => $email]);
                }
            }
        } elseif ($check_remotely) {
            $leads = $this->getLeads(['emailAddress' => $email]);
            foreach ($leads as $lead) {
                if (!empty($lead['active'])) {
                    $this->log('notice', 'Sharpspring object with e-mail {email} ({id}) was just retrieved remotely and is active. Apparently the local cache is out of date.', ['id' => $lead['id'], 'email' => $email]);
                }
            }
        }

        return $leads;
    }

    /**
     * Retrieves lead(s) (arrays, not objects) by foreign key ID.
     *
     * Only retrieves leads which are known in the in-memory cache. Therefore,
     * does not necessarily return inactive leads.
     *
     * @param string $foreign_key_id
     *   The 'foreign key ID' from a remote system.
     *
     * @return array
     *   Zero or more lead structures. (Hopefully either 0 or 1.)
     */
    public function getLeadsByForeignKey($foreign_key_id)
    {
        $ids = !empty($this->sharpspringIdsByForeignKey[$foreign_key_id]) ? $this->sharpspringIdsByForeignKey[$foreign_key_id] : [];
        $leads = [];
        foreach ($ids as $id) {
            $lead = $this->getLead($id);
            if ($lead) {
                $leads[] = $lead;
            } else {
                $this->log('error', 'LocalLeadCache internal error: Sharpspring object {id} not found, while its id was cached by foreign key ID {fkey_id}.', ['id' => $id, 'fkey_id' => $foreign_key_id]);
            }
        }

        return $leads;
    }

    /**
     * Retrieves a lead (array not object) from the key-value store or remotely.
     *
     * If fetched remotely, the lead is cached in the key-value store and the
     * in-memory cache is updated. This can be important for fetching inactive
     * leads which cacheAllLeads() does not do.
     *
     * @param int $sharpspring_id
     *   The lead's ID in Sharpspring.
     * @param bool $check_remotely
     *   (optional) If false, assume our local cache is already complete and do
     *   not make a call to the Sharpspring REST API to doublecheck. Default
     *   true (because if the caller has a Sharpspring ID, it probably knows
     *   what it's doing and we'll assume that if we don't have it cached here,
     *   that may be because it's inactive. Or worse, the key-value cache is out
     *   of date).
     *
     * @return array
     *   A lead structure; empty array means this ID does not exist.
     *
     * @throws \UnexpectedValueException
     *   If the key-value store somehow got corrupted and leads are not arrays.
     */
    public function getLead($sharpspring_id, $check_remotely = true)
    {
        $lead = $this->keyValueStore->get($sharpspring_id, []);
        if (!is_array($lead)) {
            throw new UnexpectedValueException("Lead value $sharpspring_id in key-value store is not an array: " . json_encode($lead));
        }

        if (!$lead && $check_remotely) {
            $lead = $this->getLeadRemote($sharpspring_id);
            if (!empty($lead['active'])) {
                $this->log('notice', 'Sharpspring object {id} was just retrieved remotely and is active. Apparently the local cache is out of date.', ['id' => $sharpspring_id]);
            }
        }

        return $lead;
    }

    // REST API 'proxy' CRUD methods which update the local cache too.

    /**
     * Retrieves a single Lead from the REST API and refreshes the local cache.
     *
     * Use this only if you want to bypass the local cache. This should not be
     * necessary often, since this class is explicitly created to lessen the
     * need for getLead calls.
     *
     * @param int $sharpspring_id
     *   The lead's ID in Sharpspring.
     *
     * @return array
     *   A lead structure (in array format as returned from the REST API; not as
     *   a Lead object). Empty array if not found.
     */
    public function getLeadRemote($sharpspring_id)
    {
        $lead = $this->sharpSpringConnection->getLead($sharpspring_id);
        if ($lead) {
            $this->cacheLead($lead);
        } else {
            $this->uncacheLead(['id' => $sharpspring_id]);
        }

        return $lead;
    }

    /**
     * Retrieves a number of lead objects from the REST API and refreshes cache.
     *
     * Use this only if you want to bypass the local cache. This should not be
     * necessary often, since this class is explicitly created to lessen the
     * need for getLeads calls.
     *
     * @param array $where
     *   A key-value array containing ONE item only, with key being either 'id'
     *   or 'emailAddress' - because that is all the REST API supports. The
     *   return value will be one lead only, and will also return the
     *   corresponding lead if it is inactive. If this parameter is not
     *   provided, only active leads are returned.
     * @param int $limit
     *   (optional) A limit to the number of objects returned. A higher number
     *   than 500 does not have effect; the number of objects returned will be
     *   500 maximum.
     * @param int $offset
     *   (optional) The index in the full list of objects, of the first object
     *   to return. Zero-based. (To reiterate: this number is 'object based',
     *   not 'batch/page based'.)
     *
     * @return array
     *   An array of lead structures (in array format as returned from the REST
     *   API; not as Lead objects).
     */
    public function getLeads($where = [], $limit = null, $offset = null)
    {
        $leads = $this->sharpSpringConnection->getLeads($where, $limit, $offset);
        if ($leads) {
            foreach ($leads as $lead) {
                $this->cacheLead($lead);
            }
        } elseif ($where) {
            $this->uncacheLead($where);
        }

        return $leads;
    }

    /**
     * Retrieves Leads in a given time frame from the REST API; refreshes cache.
     *
     * Two things were changed around 2017-07-26, without announcement or
     * increase in the API version (1.117):
     * - until then, if a lead was updated to be inactive, it would still be
     *   part of the 'update' dataset returned by this call. From then on,
     *   inactive leads are not part of the returned dataset anymore.
     * - until then, both the format of startDate and endDate call parameters
     *   and the format of the value returned in the updateTimestamp fields was
     *   UTC. From then on, the format was the 'local timezone'. (However this
     *   may be determined; see Lead::$updateTimestamp for comments.)
     *
     * Warning: the number of leads returned is capped at 500 by default. (At
     * least: it was around december 2016 - february 2017). Luckily this call
     * also has (undocumented) 'limit' and 'offset' parameters.
     *
     * @param string $start_date
     *   Start of date range; format Y-m-d H:i:s.
     * @param string $end_date
     *   (optional) End of date range; format Y-m-d H:i:s. Defaults to 'now'.
     * @param $time_type
     *   (optional) The field to filter for dates: update (default) or create.
     *   (For completeness: leads which have been created once and never
     *   updated afterwards, are also returned in the 'update' list. This is
     *   obviously the logical thing to do; it's just being noted here because
     *   at least one other competitor's REST API does _not_ do this...)
     * @param int $limit
     *   (optional) A limit to the number of objects returned. The default is
     *   set to 500, but (unlike with getLeads()) it can be raised beyond 500.
     * @param int $offset
     *   (optional) The index in the full list of objects, of the first object
     *   to return. Zero-based.
     *
     * @return array
     *   An array of Lead structures.
     *
     * @see Connection::getLeadsDateRange()
     * @see Lead::$updateTimestamp
     */
    public function getLeadsDateRange($start_date, $end_date = '', $time_type = 'update', $limit = null, $offset = null)
    {
        $leads = $this->sharpSpringConnection->getLeadsDateRange($start_date, $end_date, $time_type, $limit, $offset);
        foreach ($leads as $lead) {
            $this->cacheLead($lead);
        }
        return $leads;
    }

    /**
     * Creates a Lead object and refreshes the local cache.
     *
     * @param \SharpSpring\RestApi\Lead|array $lead
     *   A lead. (Both actual Lead objects and arrays are accepted.)
     *
     * @return array
     *    [ 'success': true, 'error': null, 'id': <ID OF THE CREATED LEAD> ]
     */
    public function createLead($lead)
    {
        $result = $this->sharpSpringConnection->createLead($lead);
        $lead_array = $this->sharpSpringConnection->toArray('lead', $lead);
        $lead_array['id'] = $result['id'];
        $this->cacheLead($lead_array);
        return $result;
    }

    /**
     * Creates one or more Lead objects and refreshes the local cache.
     *
     * @param array $leads
     *   Leads. (Both actual Lead objects and arrays are accepted).
     *
     * @return array
     *   The (somewhat shortened) API call result, which should be an array with
     *   as many values as there are leads in the input argument, each being an
     *   array structured like [ 'success': true, 'error': null, 'id': <NEW ID>]
     */
    public function createLeads(array $leads)
    {
        try {
            $result = $this->sharpSpringConnection->createLeads($leads);
            // All leads have been successfully created.
            foreach (array_values($leads) as $i => $lead) {
                $lead_array = $this->sharpSpringConnection->toArray('lead', $lead);
                $lead_array['id'] = $result[$i]['id'];
                $this->cacheLead($lead_array);
            }
        } catch (SharpSpringRestApiException $e) {
            if ($e->isObjectLevel()) {
                // At least one object-level error was encountered but some
                // leads may have been updated successfully. Cache those. Reset
                // keys of the input argument first, just to make sure.
                $leads = array_values($leads);
                foreach ($e->getData() as $i => $object_result) {
                    if (!empty($object_result['success'])) {
                        $leads[$i]->id = $object_result['id'];
                        $this->cacheLead($this->sharpSpringConnection->toArray('lead', $leads[$i]));
                    }
                }
            }
            throw $e;
        }

        return $result;
    }

    /**
     * Updates a lead object and refreshes the local cache.
     *
     * @param \SharpSpring\RestApi\Lead|array $lead
     *   A lead. (Both actual Lead objects and arrays are accepted.)
     *
     * @return array
     *   A fixed value: [ 'success': true, 'error': null ]. (The value is not
     *   much use at the moment but is kept like this in case the REST API
     *   extends its functionality, like createLead which returns extra info.)
     */
    public function updateLead($lead)
    {
        $result = $this->sharpSpringConnection->updateLead($lead);
        // Because the lead does not necessarily have all properties, merge it
        // into the originally cached one. The lead has either an e-mail address
        // or a Sharpspring ID; otherwise it would have thrown an exception.
        $lead = $this->sharpSpringConnection->toArray('lead', $lead);
        $orig_lead = !empty($lead['id']) ? $this->getLead($lead['id'], false) : $this->getLeadsByEmail($lead['emailAddress'], false);
        $lead = array_merge($orig_lead, $lead);
        $this->cacheLead($lead);

        return $result;
    }

    /**
     * Updates one or more Lead objects and refreshes the local cache.
     *
     * @param array $leads
     *   Leads. (Both actual Lead objects and arrays are accepted).
     *
     * @return array
     *   The (somewhat shortened) API call result, which should be an array with
     *   as many values as there are leads in the input argument, each being a
     *   fixed array value: [ 'success': true, 'error': null ]
     *
     * @throws \SharpSpring\RestApi\SharpSpringRestApiException
     * @throws \UnexpectedValueException
     * @throws \RuntimeException
     *   See createLeads().
     *
     * @see LocalLeadCache::createLeads()
     * @see LocalLeadCache::updateLead()
     */
    public function updateLeads(array $leads)
    {
        try {
            $result = $this->sharpSpringConnection->updateLeads($leads);
            // All leads have been successfully updated.
            foreach ($leads as $lead) {
                // Because the lead does not necessarily have all properties,
                // merge it into the originally cached one. The lead has either
                // an e-mail address or a Sharpspring ID; otherwise it would
                // have thrown an exception.
                $lead = $this->sharpSpringConnection->toArray('lead', $lead);
                $orig_lead = !empty($lead['id']) ? $this->getLead($lead['id'], false) : $this->getLeadsByEmail($lead['emailAddress'], false);
                $lead = array_merge($orig_lead, $lead);
                $this->cacheLead($lead);
            }
        } catch (SharpSpringRestApiException $e) {
            if ($e->isObjectLevel()) {
                // At least one object-level error was encountered but some
                // leads may have been updated successfully. Cache those. Reset
                // keys of the input argument first, just to make sure.
                $leads = array_values($leads);
                foreach ($e->getData() as $i => $object_result) {
                    if (!empty($object_result['success'])) {
                        $lead = $this->sharpSpringConnection->toArray('lead', $leads[$i]);
                        $orig_lead = !empty($lead['id']) ? $this->getLead($lead['id'], false) : $this->getLeadsByEmail($lead['emailAddress'], false);
                        $lead = array_merge($orig_lead, $lead);
                        $this->cacheLead($lead);
                    }
                }
            }
            throw $e;
        }

        return $result;
    }

    /**
     * Deletes a single lead and removes it from the local cache.
     *
     * @param int $id
     *   The ID of the lead.
     *
     * @return array
     *   A fixed value: [ 'success': true, 'error': null ]. (The value is not
     *   much use at the moment but is kept like this in case the REST API
     *   extends its functionality, like createLead which returns extra info.)
     */
    public function deleteLead($id)
    {
        $result = $this->sharpSpringConnection->deleteLead($id);
        $this->keyValueStore->delete($id);
        return $result;
    }

    /**
     * Deletes multiple leads identified by id.
     *
     * Does nothing and returns empty array if an empty array is provided.
     *
     * @param int[] $ids
     *
     * @return array
     *   The (somewhat shortened) API call result, which should be an array with
     *   as many values as there are leads in the input argument, each being a
     *   fixed array value: [ 'success': true, 'error': null ]
     *
     * @throws \SharpSpring\RestApi\SharpSpringRestApiException
     * @throws \UnexpectedValueException
     * @throws \RuntimeException
     *
     * @see LocalLeadCache::createLeads()
     */
    public function deleteLeads(array $ids)
    {
        try {
            $result = $this->sharpSpringConnection->deleteLeads($ids);
            // All leads have been successfully deleted.
            $this->keyValueStore->deleteMultiple($ids);
        } catch (SharpSpringRestApiException $e) {
            if ($e->isObjectLevel()) {
                // At least one object-level error was encountered but some
                // leads may have been updated successfully. Cache those. Reset
                // keys of the input argument first, just to make sure.
                $ids = array_values($ids);
                foreach ($e->getData() as $i => $object_result) {
                    if (!empty($object_result['success'])) {
                        $this->keyValueStore->delete($ids[$i]);
                    }
                }
            }
            throw $e;
        }

        return $result;
    }

    // End REST API 'proxy' CRUD methods which update the local cache too.

    /**
     * Retrieves and caches all active leads from Sharpspring.
     *
     * @param string $since
     *   If empty, this method will clear the local cache and read / cache all
     *   (active) leads from the Sharpspring account. If set to a time
     *   representation, only the updates since then will be read and cached on
     *   top of the current contents of the cache. (The format is the same as
     *   expected by the getLeadsDateRange, which has unfortunately changed once
     *   in the past without the API version 1.117 changing with it! See
     *   Connection::getLeadsDateRange().)
     *
     * @see Connection::getLeadsDateRange()
     */
    public function cacheAllLeads($since)
    {
        if (!$since) {
            $this->keyValueStore->deleteAll();
            $this->propertyCache = $this->sharpspringIdsByForeignKey = $this->sharpspringIdsByEmail = [];
        }

        $offset = 0;
        do {
            $leads = $since
                ? $this->getLeadsDateRange($since, '', 'update', static::LEADS_GET_LIMIT, $offset)
                : $this->getLeads([], static::LEADS_GET_LIMIT, $offset);
            $offset = count($leads) == static::LEADS_GET_LIMIT ? $offset + static::LEADS_GET_LIMIT : 0;
        } while ($offset);
    }

    /**
     * Caches a lead's data in the in-memory cache and key-value store.
     *
     * @param array $lead_array
     *   A lead as retrieved from Sharpspring in array format (not Lead object).
     */
    protected function cacheLead(array $lead_array)
    {
        if (empty($lead_array['id'])) {
            $this->log('critical', "LocalLeadCache internal coding error: Sharpspring object {object} has no 'id' parameter in cacheLead().", ['object' => isset($lead_array['emailAddress']) ? $lead_array['emailAddress'] : json_encode($lead_array)]);
        } else {
            $this->updateMemoryCaches($lead_array);
            $this->keyValueStore->set($lead_array['id'], $lead_array);
        }
    }

    /**
     * Updates a lead's data in the in-memory cache.
     *
     * @param array $lead_array
     *   A lead as retrieved from Sharpspring in array format (not Lead object).
     */
    protected function updateMemoryCaches(array $lead_array)
    {
        $lead_exists = isset($this->propertyCache[$lead_array['id']]);

        // Update the reverse lookup cache for the foreign key.
        if (!empty($this->foreignKey) && !empty($lead_array[$this->foreignKey])) {
            $value_was_changed = $lead_exists;
            $fkey_id = $lead_array[$this->foreignKey];
            if (empty($this->sharpspringIdsByForeignKey[$fkey_id])) {
                $this->sharpspringIdsByForeignKey[$fkey_id] = [$lead_array['id']];
            } elseif ($lead_exists) {
                // If the Sharpspring ID is already there, this means we're
                // updating an existing lead in the cache which hasn't changed
                // foreign key value, and we don't need to do anything.
                $value_was_changed = !in_array($lead_array['id'], $this->sharpspringIdsByForeignKey[$fkey_id]);
                if ($value_was_changed) {
                    $this->sharpspringIdsByForeignKey[$fkey_id][] = $lead_array['id'];
                    // Some other sharpspring ID has the same foreign key. In
                    // principle this can happen, but it would be unlikely.
                    // Maybe this is some kind of bug in custom code's logic, so
                    // log a warning.
                    // @todo make the types of messages which are logged,
                    //       configurable? (There won't be too many of them...)
                    $this->log('warning', 'Duplicate leads found for foreign key id {fkey_id}. First is {first}, now adding {new}.', [
                        'fkey_id' => $fkey_id,
                        'first' => reset($this->sharpspringIdsByForeignKey[$fkey_id]),
                        'new' => $lead_array['id'],
                    ]);
                }
            }
            if ($value_was_changed) {
                // The lead used to have a different foreign key value. Remove
                // it from the reverse lookup cache.
                $previous_value = $this->getPropertyValue($this->foreignKey, $lead_array['id'], false);
                if ($previous_value) {
                    $this->sharpspringIdsByForeignKey[$previous_value] = array_filter($this->sharpspringIdsByForeignKey[$previous_value], function ($v) use ($lead_array) {
                        return $v !== $lead_array['id'];
                    });
                }
            }
        }

        // Update reverse lookup cache for the e-mail address in the same way,
        // except always store it lowercase.
        $value_was_changed = $lead_exists;
        $email = strtolower($lead_array['emailAddress']);
        if (empty($this->sharpspringIdsByEmail[$email])) {
            $this->sharpspringIdsByEmail[$email] = [$lead_array['id']];
        } elseif ($lead_exists) {
            $value_was_changed = !in_array($lead_array['id'], $this->sharpspringIdsByEmail[$email]);
            if ($value_was_changed) {
                $this->sharpspringIdsByEmail[$email][] = $lead_array['id'];
                // E-mail clash: this should never happen / is only here because
                // it's good to doublecheck Sharpspring (and our own code).
                $this->log('error', 'LocalLeadCache internal error, or is our cache outdated? Duplicate leads found for e-mail {email}. First is {first}, now adding {new}.', [
                    'email' => $email,
                    'first' => reset($this->sharpspringIdsByEmail[$email]),
                    'new' => $lead_array['id'],
                ]);
                // @todo should we actually call getLeadRemote() here for the other ID(s), to update things?
            }
        }
        if ($value_was_changed) {
            $previous_value = $this->getPropertyValue('emailAddress', $lead_array['id'], false);
            if ($previous_value) {
                $previous_value = strtolower($previous_value);
                // We 'know' there can only be one e-mail so we might as well
                // unset the value / assign empty array to it. But out of
                // principle, we filter().
                $this->sharpspringIdsByEmail[$previous_value] = array_filter($this->sharpspringIdsByEmail[$previous_value], function ($v) use ($lead_array) {
                    return $v !== $lead_array['id'];
                });
            }
        }

        // Update property cache. If we have no properties, we still need to set
        // it (with an empty array) so that the 'lead exists' check above can be
        // done without a call to the key-value store.
        $cache_object = [];
        foreach ($this->cachedProperties as $property) {
            if (isset($lead_array[$property])) {
                $cache_object[] = $lead_array[$property];
            } else {
                $this->log('error', 'Sharpspring object {id} contains no {property} property; is this possible?', ['id' => $lead_array['id'], 'property' => $property]);
                // There is no use in throwing an exception. We'll just not
                // cache it.
                $cache_object[] = null;
            }
        }
        $this->propertyCache[$lead_array['id']] = $cache_object;
    }

    /**
     * Removes a lead from the in-memory cache and key-value store.
     *
     * @param array $where
     *   A key-value array containing ONE item only, with key being either 'id'
     *   or 'emailAddress' - this is equal to the argument to getLeadsRemote().
     */
    protected function uncacheLead(array $where)
    {
        if (isset($where['emailAddress'])) {
            $email = strtolower($where['emailAddress']);
            if (empty($this->sharpspringIdsByEmail[$email])) {
                $ids = [];
            } else {
                $ids = $this->sharpspringIdsByEmail[$email];
                // Already unset the reverse lookup cache for e-mail.
                $this->sharpspringIdsByEmail[$email] = [];
            }
        } else {
            // No further checks on $where; this is a protected function.
            $ids = [$where['id']];
        }

        foreach ($ids as $id) {
            // From the ID we need to derive (possibly) the e-mail and the
            // foreign key value. We don't know if these are in the property
            // cache. Still, it's probably a bit better to use
            // getPropertyValue() calls than a getLead() call, even though that
            // might cause two requests to the key-value store.

            // Get e-mail address, if we don't have it yet, and unset its cache.
            if (!isset($where['emailAddress'])) {
                $email = $this->getPropertyValue('emailAddress', $id, false);
                if ($email) {
                    $email = strtolower($email);
                    // This should really be a one-element array but we still
                    // filter like it might not be. This code dovetails with
                    // updateMemoryCaches().
                    $this->sharpspringIdsByEmail[$email] = array_filter($this->sharpspringIdsByEmail[$email], function ($v) use ($id) {
                        return $v !== $id;
                    });
                }
            }

            // Get foreign key value and unset its reverse lookup cache.
            if (!empty($this->foreignKey)) {
                $fkey_id = $this->getPropertyValue($this->foreignKey, $id, false);
                if ($fkey_id) {
                    $this->sharpspringIdsByForeignKey[$fkey_id] = array_filter($this->sharpspringIdsByForeignKey[$fkey_id], function ($v) use ($id) {
                        return $v !== $id;
                    });
                }
            }

            // Unset property cache.
            unset($this->propertyCache[$id]);
        }

        // Remove from key-value store.
        $this->keyValueStore->deleteMultiple($ids);
    }

    /**
     * Log a message; ignore it if no logger was set.
     *
     * @param mixed $level
     *   A string representation of a level. (No idea why PSR-3 defines mixed.)
     * @param string $message
     *   The message.
     * @param array $context
     *   The log context. See PSR-3.
     */
    protected function log($level, $message, array $context = [])
    {
        if (isset($this->logger)) {
            $this->logger->log($level, $message, $context);
        }
    }
}
