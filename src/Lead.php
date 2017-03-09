<?php

namespace SharpSpring\RestApi;

/**
 * The Lead table consists of prospects who are possibly interested in your
 * product. As a lead progresses through your pipeline, their status changes
 * from unqualified to qualified. A lead can be converted into a contact,
 * opportunity, or account.
 *
 * Different parts of the REST API seem to have a different understanding about
 * what are valid fields (tested on API v1.117, 20161205). We have:
 * - the return value from the getFields() call; this includes 'crmID' and
 *   custom fields but not 'accountID' and 'active'.
 * - the fieldnames returned by a getLead() call, which apparently returns all
 *   fields also when they are empty/null; this includes 'accountID', 'active'
 *   and custom fields but not 'crmID';
 * - the accepted fieldnames (as far we can tell this is the same as previous)
 * - the list of valid parameters we get back as part of an error message,
 *   when we try to set an invalid parameter/field; this includes 'accountID'
 *   and 'active'(?) but not crmID or any custom fields.
 *
 * The following fields are also (like crmID) part of the getFields() output but
 * not among properties returned for a lead. I haven't checked points 3 and 4
 * for them, but so far assume they are invalid just like crmID... Then again,
 * they could be read-only fields that only become visible after some action
 * happens inside Sharpspring.
 *   numBounces - int - Number of Bounces
 *   hardBounced - int - Hard Bounced Email Address
 *   hasOpportunity - bit - Has an Opportunity
 *   isQualified - bit - Is Qualified
 *   isContact - bit - Is Contact
 * These fields are therefore (like crmID) not defined below.
 */
class Lead extends ValueObject {
  // Note I don't know why isUnsubscribed and active are nullable. It probebly
  // does not make sense to set them to NULL. They *can* be (re)set to NULL
  // though, unlike string fields. (It's probably an API fail.) 'active' has
  // default value 1, whereas the others default to NULL.
  protected $_nullableProperties = ['accountID', 'ownerID', 'isUnsubscribed', 'active'];

  /**
   * Indicator whether this is an active lead. Must be 0 or 1.
   *
   * This is not among the fields returned by getFields(); it's a special
   * property to deactivate leads (make them invisible and not be part of the
   * return values in a getLeads() call unless the lead is requested by its
   * specific id/emailAddress.
   *
   * 'bool' means the only valid values are strings '0' and '1'. It is
   * automatically set to '1' for new objects. It is nullable though. (No idea
   * if this is a mistake or what Sharpspring does with active=NULL.)
   *
   * @var bool
   */
  public $active = "\0";

  /**
   * Is Unsubscribed?
   *
   * 'bool' means the only valid values are strings '0' and '1'. It's also
   * nullable (and starts as null for new objects).
   *
   * @var bool
   */
  public $isUnsubscribed = "\0";

  /**
   * SharpSpring ID.
   *
   * This is one of the two possible 'identifier properties' for a lead.
   *
   * It's apparently 12 digits, which fits into an int in most systems.
   *
   * (tested on API v1.117, 20161205:) Is ignored on createLead calls. Is
   * required on updateLead calls, except when updating a lead with a known-
   * existing emailAddress that won't change during the update; then this may be
   * left empty. (Do not change its value; you'll end up updating a different
   * lead - or nothing at all depending on the e-mail address; see comments at
   * updateLead().)
   *
   * @var int
   */
  public $id;

  /**
   * Email
   *
   * This is one of the two possible 'identifier properties' for a lead.
   *
   * WARNING: see comments at updateLead() for gotchas on updating existing
   * leads. In summary: if you're sure that you are not changing this value,
   * you're fine; otherwise you must doublecheck whether an update succeeds.
   *
   * In Sharpspring this is data type 'email'. The REST API however does NOT
   * validate the contents; it is possible to insert a bogus string.
   *
   * @var string
   */
  public $emailAddress;

  /**
   * Owner ID.
   *
   * @var int|null
   */
  public $ownerID = "\0";

  /**
   * Account ID.
   *
   * @var int|null
   */
  public $accountID = "\0";

  /**
   * Lead Status.
   *
   * Possible values (as currently known):
   * - unqualified
   * - open
   * - qualified
   * - contact
   * - contactWithOpp: all we know about this value so far is that in the UI,
   *   - for 'regular' contacts, it cannot be set
   *   - if it is somehow set, a different type cannot be set.
   *   This probably goes for the REST API too. So far we have seen that
   *   updating a contactWithOpp to contact is impossible; the REST API will
   *   return success but the field is not updated.
   * Leaving this empty when creating a contact will set it to 'unqualified'.
   * Other values will have the REST API return an error.
   *
   * @var string
   */
  public $leadStatus;

  /**
   * Lead Score.
   *
   * This value can be upated; getLead API calls will return the updated score
   * but this update won't be reflected in the Lead Score that shows for a user
   * in the UI. *shrug*
   *
   * @var int
   */
  public $leadScore;

  /**
   * First Name
   *
   * @var string
   */
  public $firstName;

  /**
   * Last Name
   *
   * @var string
   */
  public $lastName;

  /**
   * Title.
   *
   * @var string
   */
  public $title;

  /**
   * Company Name.
   *
   * @var string
   */
  public $companyName;

  /**
   * Industry.
   *
   * @var string
   */
  public $industry;

  /**
   * Website.
   *
   * @var string
   */
  public $website;

  /**
   * Street.
   *
   * @var string
   */
  public $street;

  /**
   * City.
   *
   * @var string
   */
  public $city;

  /**
   * Country.
   *
   * @var string
   */
  public $country;

  /**
   * State.
   *
   * @var string
   */
  public $state;

  /**
   * Zip.
   *
   * @var string
   */
  public $zipcode;

  /**
   * Phone Number.
   *
   * @var string
   */
  public $phoneNumber;

  /**
   * Extension.
   *
   * @var string
   */
  public $phoneNumberExtension;

  /**
   * Office Phone Number.
   *
   * @var string
   */
  public $officePhoneNumber;

  /**
   * Mobile Phone.
   *
   * @var string
   */
  public $mobilePhoneNumber;

  /**
   * Fax.
   *
   * @var string
   */
  public $faxNumber;

  /**
   * Description.
   *
   * @var string
   */
  public $description;

  /**
   * Last updated date/time.
   *
   * Date in ISO format, e.g. '2016-12-06 00:52:12'. This stretches the
   * definition of 'timestamp' a bit, because there is no timezone information.
   * There is also no API documentation on what the timestamp means. What we
   * know so far is:
   * - the getLeadsDateRange call returns Lead objects with updateTimestamp
   *   values expressed in UTC.
   * - the getLead and getLeads calls return Lead objects with updateTimestamp
   *   values expressed in your local timezone(!!! However that may be derived.)
   * - if you change the timezone of your Web UI account, do updates, and then
   *   change the timezome again... this has this has no effect on the
   *   updateTimestamp that is ultimately received through API calls.
   * So the working theory is
   * - The date is actually stored on the server in a 'proper timestamp' format;
   * - The date for getLead(s) calls is always converted to "your" timezone,
   *   probably using timezone information that is somehow connected to your
   *   API key. (Unless it's IP based.)
   *
   * Let's assume this updateTimestamp is not updatable to specified values -
   * which is the only sane situation.
   * The author tested updating this value in november/december 2016 (with api
   * v1.117) and worryingly, it was. Then tested this again in january 2017, and
   * it was not. (This can mean several things: 1) the author is braindead; 2)
   * Sharpspring fixed this part of their API; 3) the updateTimestamp is only
   * updatable until the lead is 'locked' because it's in use. Let's assume 1.)
   *
   * @var string
   */
  public $updateTimestamp;

}
