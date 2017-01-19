<?php

namespace SharpSpring\RestApi;

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
 * store. (This is not
 *
 * Please be aware that this class does not and cannot give guarantees that
 * the caches are actually complete after this point. Reasons include (but may
 * not be limited to) the fact that new updates can come into the source
 * system in the meantime, the fact that inactive leads are not retrieved, and
 * the fact that items are deleted directly in Sharpspring (rather than having
 * 'active' set to 0) will not be noticed until our key-value store is fully
 * refreshed. The user is invited to make their own determination on how safe it
 * is to use this cache, and how long to trust periodic updates only (meaning:
 * instantiating this class with the $refresh_cache_since parameter).
 */
class LocalLeadCache {

  /**
   * The maximum number of leads to retrieve in one Sharpspring API call.
   *
   * Maximum 500 (this is the hard cap by Sharpspring, apparently)
   */
  const LEADS_GET_LIMIT = 500;

  /**
   * SharpSpring REST Client object.
   *
   * @var \SharpSpring\RestApi\SharpSpringRestClient
   */
  protected $sharpSpringClient;

  /**
   * The key-value store.
   *
   * Type name is an indicator / for IDE help. There is no formal interface spec
   * yet so the type name may change (without changing behavior).
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
   * The values are very often single-value arrays (numerically indexed), but
   * can theoretically be multi-value.
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
   * This is the actual field name in Sharpspring, not the name of the property
   * on a Lead object.
   *
   * @var string
   */
  protected $foreignKey;

  /**
   * Constructor.
   *
   * The constructor is expensive: it will read and cache all active leads
   * locally before returning. (There does not seem to be a better place to do
   * this, though this might change later.)
   *
   * @param \SharpSpring\RestApi\SharpSpringRestClient $rest_client
   *   The SharpSpring REST Client.
   * @param object $key_value_store
   *   The key-value store. (Not type hinted because we have no formal spec.)
   * @param string $refresh_cache_since
   *   If empty, this method will clear the local cache and read / cache all
   *   (active) leads from the Sharpspring account. If set to a time (string
   *   representation in format Y-m-d H:i:s), only the updates since then will
   *   be read and cached on top of the current contents of the cache. The time
   *   has no timezone specification but is in UTC (tested on API v1.117,
   *   20170127). If '-', refreshing the cache will be skipped.
   * @param string $foreign_key
   *   (optional) The name of the foreign key field / source ID field. Leads can
   *   be retrieved by this field, though there is no guarantee that inactive
   *   leads are found. This needs to be the actual field name in Sharpspring,
   *   not the  name of the property on a PHP Lead object.
   * @param array $cached_properties
   *   (optional) Properties to index by in-memory cache. This means
   *   getPropertyValue will not need to query the key-value store, for these
   *   properties (Note e-mail address and the foreign key are not automatically
   *   part of this; they are only used to populate reverse lookup indices to
   *   get the Sharpspring ID, by default.)
   * @param \Psr\Log\LoggerInterface $logger
   *   (optional) A logger.
   */
  public function __construct(SharpSpringRestClient $rest_client, $key_value_store, $refresh_cache_since, $foreign_key = NULL, array $cached_properties = [], LoggerInterface $logger = NULL) {
    // @todo generalize foreignKey so we can have more than one reverse-lookup
    // cache (besides e-mail)?
    $this->foreignKey = $foreign_key;
    $this->cachedProperties = $cached_properties;
    $this->sharpSpringClient = $rest_client;
    $this->keyValueStore = $key_value_store;
    $this->logger = $logger;

    if (!empty($refresh_cache_since)) {
      // Populate in-memory cache. (We would be able to 'lazy-load' this; it
      // would be a bit of effort to make sure that every method call will still
      // work with an up to date cache. Not sure if that is worth the effort yet
      // / if this class is ever going to be instantiated without needing to
      // have a full cache or while needing the constructor to be inexpensive.)
      $this->propertyCache = $this->sharpspringIdsByForeignKey = $this->sharpspringIdsByEmail = [];
      $offset = 0;
      do {
        $leads = $this->keyValueStore->getAllBatched(1024, $offset);
        foreach ($leads as $lead_array) {
          $this->updateMemoryCaches($lead_array);
        }
        $offset = count($leads) == 1024 ? $offset + 1024 : 0;
      } while ($offset);
    }

    if ($refresh_cache_since !== '-') {
      // Populate or complete the cache.
      $this->cacheAllLeads($refresh_cache_since);
    }
  }

  /**
   * Get and cache all active leads from Sharpspring.
   *
   * NOTE: The updateTimestamp value differs if we fetch it using getLeads vs.
   * getLeadsDateRange, so we should not trust it! (It's expressed in local
   * timezone vs. UTC, respectively. We could convert it, but it's not exactly
   * clear how "local timezone" is defined.)
   *
   * @param string $since
   *   If empty, this method will clear the local cache and read / cache all
   *   (active) leads from the Sharpspring account. If set to a time (string
   *   representation in format Y-m-d H:i:s), only the updates since then will
   *   be read and cached on top of the current contents of the cache. The time
   *   has no timezone specification but is in UTC (tested on API v1.117,
   *   20170127).
   */
  public function cacheAllLeads($since) {
    if (!$since) {
      $this->keyValueStore->deleteAll();
      $this->propertyCache = $this->sharpspringIdsByForeignKey = $this->sharpspringIdsByEmail = [];
    }

    $offset = 0;
    do {
      // Apparently getLeadsDateRange has no limit / offset. We may have to
      // build some kind of fallback if we just don't get any data returned (or
      // get an error), but we don't know the detailed behavior of the API yet.
      $leads = $since ? $this->sharpSpringClient->getLeadsDateRange($since) :
        $this->sharpSpringClient->getLeads([], static::LEADS_GET_LIMIT, $offset);
      foreach ($leads as $lead_array) {
        $this->cacheLead($lead_array);
      }
      if (!$since) {
        $offset = count($leads) == static::LEADS_GET_LIMIT ? $offset + static::LEADS_GET_LIMIT : 0;
      }
    } while ($offset);
  }

  /**
   * Retrieves a lead (array, not object), from the key-value store or remotely.
   *
   * If fetched remotely, the lead is cached in the key-value store and the
   * in-memory cache is updated. This can be important for fetching inactive
   * leads which cacheAllLeads() does not do.
   *
   * NOTE: The updateTimestamp value is unreliable; it may be expressed in local
   * time or UTC! See cacheAllLeads().
   *
   * @param string $sharpspring_id
   *   The ID in Sharpspring.
   * @param bool $check_remotely
   *   (optional) If FALSE, assume our local cache is already complete and do
   *   not make a call to the Sharpspring REST API to doublecheck. Default TRUE.
   *   (because if the caller has a Sharpspring ID, it probably knows what it's
   *   doing and we'll assume that if we don't have it cached here, that may be
   *   because it's inactive. Or worse, the key-value cache is out of date).
   *
   * @return array
   *   A lead structure; empty array means this ID does not exist.
   */
  public function getLead($sharpspring_id, $check_remotely = TRUE) {
    $lead = $this->keyValueStore->get($sharpspring_id, array());
    // @todo doublecheck whether this is in the in-memory cache, if it exists?
    //       (and log/populate if not.) Or is that too much?

    if (!$lead && $check_remotely) {
      $leads = $this->getRemoteLeads($sharpspring_id);
      $lead = $leads ? reset($leads) : array();
    }

    return $lead;
  }

  /**
   * Retrieves lead(s) (arrays, not objects) by e-mail address.
   *
   * If fetched remotely, the lead is cached in the key-value store and the
   * in-memory cache is updated. This can be important for fetching inactive
   * leads which cacheAllLeads() does not do.
   *
   * NOTE: The updateTimestamp value is unreliable; it may be expressed in local
   * time or UTC! See cacheAllLeads().
   *
   * @param string $email
   *   The e-mail address.
   * @param bool $check_remotely
   *   (optional) If FALSE, assume our local cache is already complete and do
   *   not make a call to the Sharpspring REST API to doublecheck.
   *
   * @return array
   *   Zero or more lead structures. (It really should be maximum 1 because we
   *   cannot update a second lead to the same e-mail address. But who knows
   *   what Sharpspring is capable of - the documentation does not specify it
   *   and it accepts bogus e-mail addresses - so we won't make guarantees
   *   about the API result.)
   */
  public function getLeadsByEmail($email, $check_remotely = TRUE) {
    $ids = !empty($this->sharpspringIdsByEmail[$email]) ? $this->sharpspringIdsByEmail[$email] : [];

    $leads = array();
    if ($ids) {
      foreach ($ids as $id) {
        // This really should not do a remote call because our in-memory lookup
        // cache should be in sync with our data.
        $lead = $this->getLead($id);
        if ($lead) {
          $leads[] = $lead;
        }
        else {
          $this->log('error', 'Sharpspring object {id} not found, while its id was cached by e-mail {email}; is this possible?', ['id' => $id, 'email' => $email]);
          // @todo Maybe we should un-cache the item. On the other hand, maybe we
          //   got some connection error and should do nothing / retry...
        }
      }
    }
    elseif ($check_remotely) {
      $leads = $this->getRemoteLeads($email, 'emailAddress');
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
  public function getLeadsByForeignKey($foreign_key_id) {
    $ids = !empty($this->sharpspringIdsByForeignKey[$foreign_key_id]) ? $this->sharpspringIdsByForeignKey[$foreign_key_id] : [];
    $leads = array();
    foreach ($ids as $id) {
      $lead = $this->getLead($id);
      if ($lead) {
        $leads[] = $lead;
      }
      else {
        $this->log('error', 'Sharpspring object {id} not found, while its id was cached by foreign key ID {fkey_id}; is this possible?', ['id' => $id, 'fkey_id' => $foreign_key_id]);
        // @todo Maybe we should un-cache the item. On the other hand, maybe we
        //   got some connection error and should do nothing / retry...
      }
    }

    return $leads;
  }

  /**
   * Returns property value for a Sharpspring contact.
   *
   * @param string $property
   *   Property name.
   * @param string $sharpspring_id
   *   Sharpspring ID.
   * @param bool $check_remotely
   *   (optional) If FALSE, assume our local cache is already complete and do
   *   not make a call to the Sharpspring REST API to doublecheck. Default TRUE
   *   (because if the caller has a Sharpspring ID, it probably knows what it's
   *   doing and we'll assume that if we don't have it cached here, that may be
   *   because it's inactive. Or worse, the key-value cache is out of date).
   *
   * @return mixed
   *   The value, or NULL if the property does not exist in the item OR if the
   *   item does not exist.
   */
  public function getPropertyValue($property, $sharpspring_id, $check_remotely = TRUE) {
    $value = NULL;
    $key = array_search($property, $this->cachedProperties, TRUE);
    if ($key !== FALSE) {
      // Get value from in-memory cache; if it isn't there, then get remotely.
      if (!isset($this->propertyCache[$sharpspring_id]) && $check_remotely) {
        // This call will first check the key-value store again, which is
        // unnecessary because we assume it & the in-memory cache are in sync...
        // But it doesn't hurt.
        $this->getLead($sharpspring_id, $check_remotely);
        if (!isset($this->propertyCache[$sharpspring_id])) {
          // Do negative caching.
          $this->propertyCache[$sharpspring_id] = [];
        }
      }

      return isset($this->propertyCache[$sharpspring_id][$key]) ? $this->propertyCache[$sharpspring_id][$key] : NULL;
    }

    // Get value from key-value store, or remotely.
    $lead = $this->getLead($sharpspring_id, $check_remotely);
    return isset($lead[$key]) ? $lead[$key] : NULL;
  }

  /**
   * Compare a lead object against data in Sharpspring.
   *
   * This can be called to see if lead data from an external system (which has
   * been converted to a lead object somehow) should be updated in Sharpspring;
   * this is the main reason for this class existing. See the class description.
   *
   * @param \SharpSpring\RestApi\Lead|array $external_lead
   *   The lead to compare, which must have at least the id, the foreign key
   *   field or the e-mail address set - because that's how they are matched.
   *   (Sharpspring cannot query by other properties so would always return an
   *   empty array - if there no Exception was thrown.) If an array key is not
   *   present (or a non-nullable class property is NULL) in the input, this
   *   will not be used in comparing (just like it would not be sent in update /
   *   create calls), so a value that is only set in the corresponding
   *   Sharpspring data will not cause the comparison to fail. If it is desired
   *   to explicitly compare an empty field in a Lead input object, it should be
   *   explicitly set to an empty string.
   * @param bool $check_remotely
   *   (optional) If FALSE, assume our local cache is already complete and do
   *   not make a call to the Sharpspring REST API to doublecheck. In practice
   *   this can get expensive if the leads to compare have no ID and there could
   *   be a lot of e-mail addresses/foreign keys IDs that are not present in our
   *   Sharpspring cache (yet) - so the caller may want to pass FALSE if it's
   *   sure there are no inactive leads in Sharpspring (or if it doesn't care
   *   about them).
   *
   * @return array
   *   If the object in Sharpspring is different: that whole object. If it is
   *   the same: a one-element array containing only 'id'. If no objects at all
   *   could be matched (by id if provided, and otherwise by either foreign key
   *   or by e-mail address in that order), an empty array. Therefore, same-ness
   *   can be checked by the count() of the return value being 1. (It is
   *   possible that this returns an empty array when there is a lead in
   *   Sharpspring with the same data... if the input lead has no ID and the
   *   lead in Sharpspring is inactive.)
   *
   * @throws \InvalidArgumentException
   *   If no IDs/email properties are set in the lead object.
   */
  public function compareLead($external_lead, $check_remotely = TRUE) {
    $external_lead = $this->sharpSpringClient->toArray('lead', $external_lead);

    // Matching to the Sharpspring lead object is done on Sharpspring ID if the
    // 'id' field is populated (though we don't expect it to be), otherwise on
    // foreign key (which is what this class is really meant for), and if that
    // is empty, on e-mail address.
    $leads = [];
    if (!empty($external_lead['id'])) {
      $leads = array($this->getLead($external_lead['id'], $check_remotely));
    }
    else {
      if (isset($this->foreignKey) && !empty($external_lead[$this->foreignKey])) {
        $leads = $this->getLeadsByForeignKey($external_lead[$this->foreignKey]);
      }
      if (!$leads && !empty($external_lead['emailAddress'])) {
        $leads = $this->getLeadsByEmail($external_lead['emailAddress'], $check_remotely);
      }
      elseif (!isset($this->foreignKey) || empty($external_lead[$this->foreignKey])) {
        throw new \InvalidArgumentException('The provided Lead object has no ID / e-mail values.');
      }
    }

    // Ignore one field in the compared lead: updateTimestamp. (It is not
    // clear why anyone would populate it, but we are not going to care.)
    unset($external_lead['updateTimestamp']);

    // Theoretically there can be multiple leads (though in practice that would
    // only happen if you'd pass a lead with a foreign key and no ID / e-mail).
    // Comparison is OK if one of the leads in Sharpspring compares against the
    // given lead object.
    $return = [];
    foreach ($leads as $sharpspring_lead) {
      // If the key exists in the 'external' lead, then we want to compare it
      // against the Sharpspring lead (also if its value is NULL). If the
      // corresponding key does not exist in Sharpspring, we just return FALSE.
      // If the key does not exist in the 'external' lead, we don't care what
      // its value is in the Sharpspring lead.
      if (array_diff_key($external_lead, $sharpspring_lead)) {
        // In the theoretical case of two returned -and non matching- leads,
        // keep the first one.
        if (!$return) {
          $return = $sharpspring_lead;
        }
        continue;
      }
      // We can't do strict comparison of the arrays because the Sharpspring
      // lead will in practice contain strings, also for integer values.
      // (Because that's how the REST API returns its JSON. We don't want to
      // depend on this staying this way forever, though.) We also can't do
      // non-strict comparison because we want to see differences between
      // '' and 0. So compare value by value.
      foreach ($external_lead as $key => $value) {
        if (isset($value) ? (string) $sharpspring_lead[$key] !== (string) $value : isset($sharpspring_lead[$key])) {
          if (!$return) {
            $return = $sharpspring_lead;
          }
          continue 2;
        }
      }
      // Everything compares OK.
      $return = array('id' => $sharpspring_lead['id']);
      break;
    }
    return $return;
  }

  /**
   * Gets and caches leads' data.
   *
   * @param string $id_value
   *   Value of the ID field
   * @param string $id_field
   *   (optional) Name of the ID field. This can only be 'emailAddress' / empty.
   *
   * @return array
   *   Zero or more leads. If $id_field is empty, it's guaranteed maximum one.
   *   (Actually it will always be maximum one; see getLeadsByEmail().)
   *
   */
  protected function getRemoteLeads($id_value, $id_field = '') {
    if ($id_field) {
      $leads = $this->sharpSpringClient->getLeads([$id_field => $id_value]);
    }
    else {
      $lead = $this->sharpSpringClient->getLead($id_value);
      $leads = array($lead);
    }
    foreach ($leads as $lead) {
      // Protect against API getLead bug; ignore leads without ID.
      if (isset($lead['id'])) {
        $this->cacheLead($lead);
      }
      //@todo check for activeness, log/warn if it's active because that's strange
      // @todo hmmm maybe it's time for a logging config, with all these messages that you might not necessarily want...
    }

    return $leads;
  }

  /**
   * Caches a lead's data in the in-memory cache and key-value store.
   *
   * @param array $lead_array
   *   A lead as retrieved from Sharpspring, in array format (not Lead object).
   *
   * @throws \InvalidArgumentException
   *   If essential data is not defined. (It should never be.)
   */
  protected function cacheLead(array $lead_array) {
    $this->updateMemoryCaches($lead_array);
    $this->keyValueStore->set($lead_array['id'], $lead_array);
  }

  /**
   * Updates a lead's data in the in-memory cache.
   *
   * @param array $lead_array
   *   A lead as retrieved from Sharpspring, in array format (not Lead object).
   *
   * @throws \InvalidArgumentException
   *   If essential data is not defined. (It should never be.)
   */
  protected function updateMemoryCaches(array $lead_array) {
    if ($this->cachedProperties) {
      $cache_object = [];
      foreach ($this->cachedProperties as $property) {
        if (isset($lead_array[$property])) {
          $cache_object[] = $lead_array[$property];
        }
        else {
          $this->log('error', 'Sharpspring object {id} contains no {property} property; is this possible?', ['id' => $lead_array['id'], 'property' => $property]);
          // There is no use in throwing an exception. We'll just not cache it.
          $cache_object[] = NULL;
        }
      }
      $this->propertyCache[$lead_array['id']] = $cache_object;
    }

    if (isset($this->foreignKey) && !empty($lead_array[$this->foreignKey])) {
      $fkey_id = $lead_array[$this->foreignKey];
      // In principle there can be multiple contacts with the same foreign key,
      // though it would be unlikely. Maybe this is some kind of bug in custom
      // code's logic, so log a warning for some minimum visibility.
      if (isset($this->sharpspringIdsByForeignKey[$fkey_id])) {
        $this->log('warning', 'Duplicate leads for foreign key id {fkey_id}? First is {first}, now adding {new},', [
          'fkey_id' => $fkey_id,
          'first' => reset($this->sharpspringIdsByForeignKey[$fkey_id]),
          'new' => $lead_array['id'],
        ]);
      }
      else {
        $this->sharpspringIdsByForeignKey[$fkey_id] = [];
      }
      $this->sharpspringIdsByForeignKey[$fkey_id][] = $lead_array['id'];
    }

    $email = $lead_array['emailAddress'];
    if (isset($this->sharpspringIdsByEmail[$email])) {
      $this->log('warning', 'Duplicate leads for e-mail {email}? First is {first}, now adding {new},', [
        'email' => $email,
        'first' => reset($this->sharpspringIdsByEmail[$email]),
        'new' => $lead_array['id'],
      ]);
    }
    else {
      $this->sharpspringIdsByEmail[$email] = [];
    }
    $this->sharpspringIdsByEmail[$email][] = $lead_array['id'];
  }

  /**
   * Log a message; ignore it if no logger was set.
   *
   * @param mixed $level
   *   A string representation of a level. (No idea why PSR-3 defines "mixed".)
   * @param string $message
   *   The message.
   * @param array $context
   *   The log context. See PSR-3.
   */
  protected function log($level, $message, array $context = array()) {
    if (isset($this->logger)) {
      $this->logger->log($level, $message, $context);
    }
  }
}
