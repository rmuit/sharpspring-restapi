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
   * It's numeric, but not an int (12 digits/chars) so we document it as string.
   *
   * (tested on API v1.117, 20161205:) Is ignored on createLead calls. Is
   * required on updateLead calls, except when updating a lead with a known-
   * existing emailAddress that won't change during the update; then this may be
   * left empty. (Do not change its value; you'll end up updating a different
   * lead - or nothing at all depending on the e-mail address; see comments at
   * updateLead().)
   *
   * @var string
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
   * Possible values: 'unqualified', 'open', 'qualified', 'contact'. Leaving
   * this empty when creating a contact will set it to 'unqualified'. Other
   * values will have the REST API return an error.
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

/**
 * Base class for Sharpspring value objects (Leads, etc).
 *
 * A value object represents e.g. a lead with all its properties predefined.
 * This is especially useful over using arrays because the Sharpspring API
 * objects' property names are case sensitive, heightening the chance of
 * exceptions thrown because of misspelled property names.
 *
 * Objects are converted to an array before e.g. JSON-encoding them for REST API
 * communication; this should be done using the toArray() method rather than
 * casting to an array (so all unwanted properties get cleared).
 *
 * A subclass can add custom properties that are not necessarily equal to the
 * system names of custom Sharpspring fields; see $_customProperties.
 *
 * Setting most properties to NULL will cause them to be excluded from the
 * toArray() return value, which means they won't be sent in REST API create /
 * update calls. (These usually cause the REST API to return an object-level
 * error 205 "Invalid parameters", when trying to update them to NULL.)
 * There are however 'nullable' properties; see $_nullableProperties.
 *
 * There are also non-nullable properties which still have NULL as the initial
 * value (upon retrieving an object from the REST API after having created it).
 * This probably is the case for every non-custom string field. This seems like
 * a design flaw in the REST API; we have not explicitly marked these and just
 * recommend any callers to treat NULL and empty string as equal for these
 * fields.
 */
class ValueObject {
  /**
   * All property names in the object that are nullable.
   *
   * Most defined properties in a new object start out as unset === NULL. We
   * don't want to send NULL for all those property values, so toArray() unsets
   * all NULL properties. The problem with that is, some properties have to be
   * able to be set explicitly to NULL in e.g. updateLead calls.
   *
   * The properties specified by name here should be kept if they are NULL - and
   * are unset only if they contain "\0" instead. These properties typically are
   * defined with:
   *   public $propertyName = "\0";
   * They should only be NULL / undefined by default if you intend for toArray()
   * to keep them by default.
   *
   * @var array
   */
  protected $_nullableProperties = [];

  /**
   * All custom defined property names used by a subclass. (Not required here.)
   *
   * Custom fields can just be set in an object (like e.g.
   *   $lead->custom_id_nr_56dff55bed3f4 = 1;
   * ), but since Sharpspring custom field system names are tedious and may
   * change when code is used on different Sharpspring accounts / environments,
   * it may be better to define your own custom property names in your own
   * subclass of a value object, and map those to the actual custom field system
   * names. (Defineing your own custom property names gets you IDE
   * autocompletion.)
   *
   * The mapping can be handled in different ways, depending on your use case:
   * define this variable statically in your subclass, or set it in the
   * constructor, or call setCustomProperties() on a Connection object. (The
   * latter is more suitable in general code where field names are not always
   * the same - but then all toArray() calls will have to pass the mapping.)
   *
   * Array keys are the custom property names and values are the actual
   * Sharpspring custom field names. Though it is possible to define those
   * in a subclass, this means that the class is tied to one specific
   * Sharpspring account / environment, so it may be better to set this mapping
   * in the client instead, using SharpSpringRestClient::
   */
  protected $_customProperties = [];

  /**
   * Constructs an object, converting custom system fields.
   *
   * @param array $values
   *   Values to initialize in the object. We assume custom field values are set
   *   with a Sharpspring 'field system name' key; the corresponding property
   *   will be set to this value.
   * @param array $custom_properties
   *   The custom property name to Sharpspring field system name mapping, which
   *   should be used. Any fields not specified here will be taken from
   *   $this->_customProperties if they are defined there.
   */
  public function __construct(array $values = [], array $custom_properties = []) {
    $custom_properties += $this->_customProperties;
    // We assume no duplicate properties are set to the same field system name.
    // If so, it is unclear which property will be filled.
    $custom_fields = array_flip($custom_properties);
    foreach ($values as $name => $value) {
      if (isset($custom_fields[$name])) {
        $name = $custom_fields[$name];
      }
      $this->$name = $value;
    }
  }

  /**
   * Converts our object to an array, converting custom properties.
   *
   * @param array $custom_properties
   *   The custom property name to Sharpspring field system name mapping, which
   *   should be used. Any fields not specified here will be taken from
   *   $this->_customProperties if they are defined there.
   *
   * @return array
   *   The array value for this object.
   */
  public function toArray(array $custom_properties = []) {
    $array = [];
    $custom_properties += $this->_customProperties;

    // All defined properties should be set in the array except NULL properties
    // (because otherwise the REST service will return "invalid value" errors).
    // The exception: 'nullable' properties; in their case the 'skip' value is
    // "\0".
    $nullable = array_flip($this->_nullableProperties);
    foreach ($this as $name => $value) {
      if (strpos($name, '_') !== 0 && $value !== (isset($nullable[$name]) ? "\0" : NULL)) {
        // Set the value. But where? If this is a custom property name,
        // translate it to the field system name. (We are assuming that no
        // property named after the field system name is ever set in the
        // object, and that no duplicate properties are mapped to the same field
        // system name. If that happens, values can get lost in the array.)
        if (isset($custom_properties[$name])) {
          $name = $custom_properties[$name];
        }
        $array[$name] = $value;
      }
    }

    return $array;
  }

}

/**
 * Exception that wraps error data in responses from the Sharpspring REST API.
 */
class SharpSpringRestApiException extends \RuntimeException {
  /**
   * @var bool
   */
  protected $objectLevel;

  /**
   * @var array
   */
  protected $errorData;

  public function __construct($message = '', $code = 0, $data = [], $object_level = FALSE, \Exception $previous = null) {
    parent::__construct($message, $code, $previous);
    $this->errorData = $data;
    $this->objectLevel = $object_level;
  }

  /**
   * Signifies whether the exception was thrown for a single object-level error.
   *
   * Sharpspring\Rest\Connection uses this as follows: Default/false is an API-
   * level error, which indicates an error was encountered during processing of
   * the request. An object-level error means the request as a whole succeeded
   * but handling at least one object (of possibly several in the same request)
   * failed. In this case a non-zero code is a code as returned by the REST API
   * for one specific object; code 0 indicates more than one object-level error
   * may have been encountered and getData() has the actual details.
   *
   * @return bool
   *   If TRUE, an object-level (as opposed to API-level) error was encountered.
   */
  public function isObjectLevel() {
    return $this->objectLevel;
  }

  /**
   * Returns a data array (if any) containing more details about the error.
   *
   * Sharpspring\Rest\Connection uses this as follows: For API-level errors,
   * this returns the data that was returned inside the 'error' section of the
   * response (except for code 0, which is theoretical enough not to explain).
   * For object-level errors with a non-zero code, this returns the data that
   * was returned with the specific object error; for code 0 it returns the
   * whole 'result' section of the response, which contains a numerically
   * indexed sub-array for each object processed containing key/value pairs
   * 'success' (bool) and 'error'. Each failed object (of which there must be at
   * least one) has a non-null 'error' section being an array containing
   * 'message' (string), 'code' (numeric) and 'data' (array) values.
   *
   * The 'data' could be anything / its exact structure is not explored yet.
   *
   * @return array
   */
  public function getData() {
    return $this->errorData;
  }

  /**
   * {@inheritdoc}
   *
   * Modeled after the original exception string but with data inserted before
   * the stack trace. (Pretty-printed JSON, because the stack trace is already
   * adding lots of data anyway.)
   */
  public function __toString() {
    return sprintf("Sharpspring REST API%s error '%s' with code %d / message '%s' in %s:%d\nData:\n%s\nStack trace:\n%s", $this->isObjectLevel() ? ' object-level' : '', get_class($this), $this->getCode(), $this->getMessage(), $this->getFile(), $this->getLine(), json_encode($this->getData(), JSON_PRETTY_PRINT), $this->getTraceAsString());
  }
}

/**
 * A Sharpspring REST API client.
 */
class SharpSpringRestClient {
  // REST endpoint without trailing slash.
  const SHARPSPRING_BASE_URL = 'https://api.sharpspring.com/pubapi/v1';
  /**
   * @var string
   */
  protected $accountId;

  /**
   * @var string
   */
  protected $secretKey;

  /**
   * The property to field name mappings for custom Sharpspring fields.
   *
   * This is a two-dimensional array, the outer array has maximum 3 keys for 3
   * mappings: 'lead', 'opportunity' and 'account'.
   *
   * @var array
   */
  protected $customPropertiesByType;

  /**
   * SharpSpringRestClient constructor.
   *
   * @param string $account_id
   *   The account ID to use for API calls.
   * @param string $secret_key
   *   The 'secret key' to use for API calls.
   */
  public function __construct($account_id, $secret_key) {
    $this->setAccountId($account_id);
    $this->setSecretKey($secret_key);
  }

  /**
   * Getter of accountId property.
   *
   * @return string
   */
  public function getAccountId() {
    return $this->accountId;
  }

  /**
   * Setter of accountId property.
   *
   * @param string $account_id
   *
   * @return mixed
   */
  public function setAccountId($account_id) {
    $this->accountId = $account_id;

    return $account_id;
  }

  /**
   * Getter of secretKey property.
   *
   * @return string
   */
  public function getSecretKey() {
    return $this->secretKey;
  }

  /**
   * Setter of secretKey property.
   *
   * @param string $secret_key
   *
   * @return mixed
   */
  public function setSecretKey($secret_key) {
    $this->secretKey = $secret_key;

    return $secret_key;
  }

  /**
   * Set a custom property to field name mapping for custom Sharpspring fields.
   *
   * You need to set this if you use custom fields in Sharpspring, and
   * - either you use arrays as the input to create* / update* functions, having
   *   keys for those custom fields which do not correspond to the field system
   *   names (because these system names are long and differ per account)
   * - or you use ValueObject classes as input, with custom properties that do
   *   not correspond to the field system names - and those ValueObject classes
   *   do not set a property to custom field system name themselves.
   * Any input array/object will have the properties in this mapping converted
   * before they are used in any REST API calls.
   *
   * (This class, in its function documentation, implicitly assumes that it does
   * not need to care whether the input arrays'/objects' field names are already
   * field system names. This is true in practice because the system names
   * always end in an underscore + 13 char semi random hex string. So as long
   * as you don't define your own custom properties like that, you should be
   * safe.)
   *
   * @param string $object_type
   *   The type of object to set mapping for: 'lead', 'opportunity', 'account'.
   * @param array $mapping
   *   The mapping from our custom property names (array keys) to Sharpspring
   *   custom field system names (array values).
   *
   * @see ValueObject::$_customProperties
   */
  public function setCustomProperties($object_type, array $mapping) {
    $this->customPropertiesByType[$object_type] = $mapping;
  }

  /**
   * Converts an external input object/array to something the REST API can use.
   *
   * @param string $object_type
   *   The type of object to set mapping for: 'lead', 'opportunity', 'account'.
   * @param \SharpSpring\RestApi\ValueObject|array $object
   *   An input object/array
   *
   * @return array
   *   An array representing a Sharpspring 'object', that can be used in e.g.
   *   a create/update REST API call. If the input argument is an array, this
   *   will be the same except the custom properties/fields are converted to
   *   their field system names, if the mapping is set in this class.
   */
  public function toArray($object_type, $object) {
    $custom_properties = isset($this->customPropertiesByType[$object_type]) ? $this->customPropertiesByType['lead'] : [];
    if (is_object($object) && method_exists($object, 'toArray')) {
      return $object->toArray($custom_properties);
    }

    // Basically a simpler version of ValueObject::toArray(). This can handle
    // any object as long as it's an iterable and (unlike a ValueObject) the
    // iterator yields only field names/values.
    $array = [];
    foreach ($object as $name => $value) {
      // Set the value. But where? If this is a custom property name, translate
      // it to the field system name. (We are assuming that no property named
      // after the field system name is ever set in the object, and that no
      // duplicate properties are mapped to the same field system name. If that
      // happens, values can get lost in the array.)
      if (isset($custom_properties[$name])) {
        $name = $custom_properties[$name];
      }
      $array[$name] = $value;
    }

    return $array;
  }

  /**
   * Execute a query against REST API.
   *
   * @param string $method
   *   The REST API method name.
   * @param array $params
   *   The parameters.
   * @param array $response_checks
   *   (optional) various ways in which the response value from the API (which
   *   will contain 'result' and 'error' sections) should be checked and/or
   *   modified. Keys / values:
   *   - single_result_key (string): The result is expected to contain a
   *     one-element array with this value as a key; an exception is thrown
   *     otherwise. Only the inner value of this array is returned.
   *   - validate_result_with_objects (bool): Validate that the result is a
   *     structure containing individual objects; throw exception if it isn't.
   *     Also throw exception if errors are seen in the object results. (This
   *     option only influences behavior if the result does not indicate 'error'
   *     globally.)
   *   - throw_for_individual_object (bool): Validate the object(s) inside the
   *     result and throw an exception with an individual object's error code /
   *     message instead of the generic "call returned at least one object-level
   *     error" exception. (This option only influences behavior if the result
   *     indicates 'error' globally. It should only be set if the 'objects'
   *     input parameter can contain only one object; otherwise, error data for
   *     other objects can get lost.)
   *
   * @return array
   *   A JSON structure.
   *
   * @throws SharpSpringRestApiException
   *   If the REST API response indicates an error encountered while executing
   *   the method.
   * @throws \UnexpectedValueException
   *   If the REST API response has an unexpected format. (Since documentation
   *   is terse, we do strict checks so that we're sure we do not ignore unknown
   *   data.)
   * @throws \RuntimeException
   *   If the request to the REST API fails.
   */
  public function exec($method, array $params, array $response_checks = array()) {
    $request_id = session_id();
    $data = json_encode([
      'method' => $method,
      'params' => $params,
      'id' => $request_id,
    ]);
    
    $url = $this->createUrl([]);
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
      'Content-Type: application/json',
      'Content-Length: ' . strlen($data)
    ]);

    $response = curl_exec($curl);
    if (!$response) {
      $http_response = curl_getinfo($curl, CURLINFO_HTTP_CODE);
      $body = curl_error($curl);
      curl_close($curl);

      //The server successfully processed the request, but is not returning any content.
      if ($http_response == 204) {
        return []; // @todo this has no bearing on us, right? What call can use empty return value?
      }
      $error = 'CURL Error (' . get_class($this) . ")\n
        url:$url\n
        body: $body";
      throw new \RuntimeException($error);
    }
    else {
      // If request was ok, parsing http response code.
      $http_response = curl_getinfo($curl, CURLINFO_HTTP_CODE);
      curl_close($curl);

      // don't check 301, 302 because setting CURLOPT_FOLLOWLOCATION
      if ($http_response != 200 && $http_response != 201) {
        $error = "CURL HTTP Request Failed: Status Code :
          $http_response, URL: $url
          \nError Message : $response";
        throw new \RuntimeException($error);
      }
    }

    $response = json_decode($response, TRUE);
    // In circumstances that should never happen according to the API docs, we
    // throw exceptions without doing anything else. It will be up to the caller
    // to safely halt the execution and alert a human to investigate this.
    if (!isset($response['id'])) {
      throw new \UnexpectedValueException("Sharpspring REST API systemic error: no id found in JSON response from SharpspringAPI endpoint. This should never happen.\nResponse: " . json_encode($response), 1);
    }
    if ($response['id'] != $request_id) {
      throw new \UnexpectedValueException("Sharpspring REST API systemic error: unexpected id value found in JSON response from SharpspringAPI endpoint. This should never happen.\nRequest ID: $request_id\nResponse: " . json_encode($response), 2);
    }
    if (empty($response['error']) && !isset($response['result'])) {
      throw new \UnexpectedValueException("Sharpspring REST API systemic error: response contains neither error nor result.\nResponse: " . json_encode($response), 3);
    }

    // There are (we hope not more than) two kinds of error structures:
    // 1) An API-level error.
    if (isset($response['error']['message']) && isset($response['error']['code'])) {
      // We're going to trust the API to always return the three documented
      // subkeys (and nothing more). Also, we're not going to check whether
      // $response['result'] contained anything.
      throw new SharpSpringRestApiException($response['error']['message'], $response['error']['code'], $response['error']['data']);
    }

    // Regardless of error or success: if we expect the result to have only one
    // key for this specific method then validate this.
    if (!empty($response_checks['single_result_key'])) {
      if (!is_array($response['result']) || count($response['result']) != 1) {
        throw new \UnexpectedValueException("Sharpspring REST API failure: response result is not a one-element array.'\nResponse: " . json_encode($response), 4);
      }
      if (!isset($response['result'][$response_checks['single_result_key']])) {
        throw new \UnexpectedValueException("Sharpspring REST API failure: response result does not contain key $response_checks[single_result_key].\nResponse: " . json_encode($response), 5);
      }
    }
    $result = !empty($response_checks['single_result_key']) ? $response['result'][$response_checks['single_result_key']] : $response['result'];

    if (!empty($response['error'])) {
      // 2) The (hopefully only) other error structure: object level errors for
      // a call that took action on a list of objects in an 'objects' parameter.
      // In this case we have:
      // - a 0-based array of object result arrays (in the order corresponding
      //   to the 'objects' input parameter) in $result, each having at least 2
      //   keys: 'success' and 'error'. (There may be more, e.g.
      //   'result][creates' also has an 'id' key, only for the succeeded ones.)
      // - a 0-based array of object results in 'error', whose contents are
      //   exact copies of the 'error' subkeys in the result arrays.
      // Meaning: the second 'error' part is useless; since the indexes are
      // 0-based / renumbered and the structure contains no identifier, we can't
      // deduce which index corresponds to which original object (unless _all_
      // objects happened to fail).

      // Validate the result (structure, and optionally the individual objects
      // inside) and compare with the number of input parameters.
      if (!isset($params['objects']) || !is_array($params['objects'])) {
        throw new \UnexpectedValueException("Sharpspring REST API interpreter failure while evaluating error: no 'objects' (array) input parameter present for the $method method.\nResponse: " . json_encode($response), 6);
      }
      // If 'throw_for_individual_object' is set, this can throw a
      // SharpSpringRestApiException with the specific message / code / data; we
      // don't check the whole result before throwing the generic one below.
      $this->validateResultForObjects($result, $params['objects'], $method, !empty($response_checks['throw_for_individual_object']), TRUE);

      // Validate the result array against the 'error' array (which is largely
      // duplicate).
      $nr_objects_with_error = count(array_filter($result, function ($o) { return !empty($o['error']);}));
      if (!is_array($response['error']) || count($response['error']) != $nr_objects_with_error) {
        throw new \UnexpectedValueException('Sharpspring REST API interpreter failure: number of errors reported (' . count($response['error']) . ") is different from the number of objects reported to have an error ($nr_objects_with_error).\nResponse: " . json_encode($response), 9);
      }
      $error_index = 0;
      foreach ($result as $i => $object_result) {
        if (!empty($object_result['error'])) {
          if (!isset($response['error'][$error_index])) {
            throw new \UnexpectedValueException("Sharpspring REST API interpreter failure: error in result #$i was expected to correspond to error #$error_index but that error index does not exist.\nResponse: " . json_encode($response), 11);
          }
          if ($response['error'][$error_index] !== $object_result['error']) {
            throw new \UnexpectedValueException("Sharpspring REST API interpreter failure: error in result #$i was expected to be equal to error #$error_index.\nResponse: " . json_encode($response), 12);
          }
          $error_index++;
        }
      }

      // At this point we know we won't lose any info by returning only $result
      // (through throwing a custom exception), because everything inside
      // $response['error'] is also inside $result. We have not validated the
      // structure/content of the object data inside $result - except if
      // 'throw_for_individual_object' told us so.
      if (!empty($response_checks['throw_for_individual_object'])) {
        // We should never have ended up here; earlier validation should have
        // thrown an exception.
        throw new \UnexpectedValueException("Sharpspring REST API interpreter failure: error was set but the result contains no object errors.\nResponse: " . json_encode($response), 13);
      }
      // We can only throw one single exception here, so interpreting individual
      // objects does not make sense and is not our business anyway. We set code
      // 0 and return the whole result as data, so the caller can check which
      // (properly numbered) objects succeeded/failed.
      throw new SharpSpringRestApiException("$method call returned at least one object-level error", 0, $result, TRUE);
    }
    elseif (!empty($response_checks['validate_result_with_objects'])) {
      // The response indicated no error. Then it would be very strange if the
      // contents of the result indicated anything else but success... Check it
      // anyway and throw an exception for unexpected results (so the caller can
      // trust a result that gets returned by this function). If this ever does
      // start throwing an exception, we should change the code/docs to reflect
      // current reality.
      try {
        $this->validateResultForObjects($result, $params['objects'], $method);
      }
      catch (\Exception $e) {
        // Just throw a SharpSpringRestApiException (api level, code 0) always,
        // so we can wrap both the result and the exception.
        throw new SharpSpringRestApiException("$method call indicated no error, but its result structure is unexpected or does contain an individual object error. The wrapped result data / previous exception hold more info.", 0, $result, TRUE, $e);
      }
    }

    return $result;
  }

  /**
   * Checks format/contents of an API result, which contains info about objects.
   *
   * This can be called for e.g. the result of a createLeads() call, by code
   * which is not happy enough with the fact that no exception was thrown and
   * wants to verify the structure of the response value before doing things
   * with it.
   *
   * The result format is an array with zero-based numeric keys, and a value for
   * each handled object that was initially passed into the API call, being an
   * array with at least a 'success' and 'error' value.
   *
   * @param mixed $result
   *   The result returned from the (successful) REST API call that took action
   *   on objects (e.g. createLeads, updateLeads, deleteLeads). This should
   *   really be an array (and anything else will throw an exception).
   * @param array $objects
   *   The objects that were provided as input for the REST API call. (At the
   *   moment they are only used to derive keys / count, but who knows...)
   * @param string $method
   *   The REST API method called. (Used to make the exception message clearer.)
   * @param bool $validate_individual_objects
   *   (optional) If FALSE, only validate the structure of the result. By
   *   default (TRUE), also validate the contents of the individual object
   *   results. This means if one object is found to be invalid, an exception
   *   will be thrown for that one and further objects will not be checked.
   * @param bool $error_encountered
   *   (optional) TRUE if an 'error' result is being evaluated. This is used to
   *   make the exception message clearer. Should not be necessary for external
   *   code because the API call already validates the structure of 'error'
   *   results.
   *
   * @throws SharpSpringRestApiException
   *   If the result contains an object-level error; the error for one of the
   *   objects only. Only possible for $validate_individual_objects = TRUE.
   * @throws \UnexpectedValueException
   *   If the result has an unexpected format.
   */
  protected function validateResultForObjects($result, array $objects, $method, $validate_individual_objects = TRUE, $error_encountered = FALSE) {
    $return = NULL;
    $extra = $error_encountered ? ' while evaluating object-level error(s)' : '';
    if (!is_array($result)) {
      throw new \UnexpectedValueException("Sharpspring REST API interpreter failure$extra: the 'result' part of the response is not an array.\nResponse result for $method call: " . json_encode($result), 101);
    }
    if (count($objects) != count($result)) {
      throw new \UnexpectedValueException("Sharpspring REST API interpreter failure$extra: the number of objects provided to the call (" . count($objects) . ") is different from the number of objects returned in the 'result' part of the response (" . count($result) . ").\nResponse result for $method call: " . json_encode($result), 102);
    }
    $index = 0;
    foreach ($result as $i => $object_result) {
      // We could live without the following if needed, but we need to clearly
      // document how these indexes work then, to prevent bugs in other code.
      if ($i !== $index) {
        throw new \UnexpectedValueException("Sharpspring REST API interpreter failure$extra: result object was expected to have index $index; $i was found.\nResponse result for $method call: " . json_encode($result), 103);
      }
      if ($validate_individual_objects) {
        $this->validateObjectResult($object_result);
      }
      $index++;
    }
  }

  /**
   * Checks the format of an API result provided for a single object.
   *
   * create/update/delete method calls which operate on multiple objects, return
   * an array of object results which may contain error or success. This
   * function can be called with a single object result as argument, and will
   * throw an exception if the result contains an error or cannot be validated.
   *
   * @param $object_result
   *   The result of the operation on a single object. (This should be an array
   *   containing at least 2 keys 'success' and 'error', which this function
   *   will validate.)
   *
   * @return true
   *   The value of the 'success' key. (We are not checking it but according to
   *   the API docs it should always be TRUE.)
   *
   * @throws SharpSpringRestApiException
   *   If the result indicates an object-level error.
   * @throws \UnexpectedValueException
   *   If the result has an unexpected format.
   */
   public function validateObjectResult($object_result) {
     // Valid results:
     // - [ 'success' => TRUE, 'error' => NULL, (more things like 'id' for creates...) ]
     // - [ 'success' => FALSE, 'error' => anything ]
     if (!isset($object_result['success']) || !array_key_exists('error', $object_result) ) {
       throw new \UnexpectedValueException('Sharpspring REST API failure: result that should reflect status of a single object does not contain both error and success keys: ' . json_encode($object_result), 111);
     }
     if (empty($object_result['error']) && !isset($object_result['success'])) {
       throw new \UnexpectedValueException('Sharpspring REST API failure: result that should reflect status of a single object contains neither error nor success: ' . json_encode($object_result), 112);
     }
     if (!empty($object_result['error'])) {
       // An object-level error was returned. We're going to trust the API to
       // always return the 3 documented subkeys (and nothing more). Also, we
       // won't check whether $object_result['success'] actually is FALSE (If it
       // didn't, we couldn't throw two exceptions at the same time anyway...)
       throw new SharpSpringRestApiException($object_result['error']['message'], $object_result['error']['code'], $object_result['error']['data'], TRUE);
     }

     return $object_result['success'];
   }

  /**
   * Helper function to create a url containing the mandatory accountId and
   * secretKey including the base url of REST API.
   *
   * @param array $query
   *   Possible parameters for the url.
   *
   * @return string
   */
  private function createUrl(array $query = []) {
    $base_query = [
      'accountID' => $this->accountId,
      'secretKey' => $this->secretKey
    ];
    if (!empty($query) && is_array($query)) {
      $query = array_merge($base_query, $query);
    }
    else {
      $query = $base_query;
    }
    $url = self::SHARPSPRING_BASE_URL;
    if (!empty($query)) {
      $url .= '?' . http_build_query($query);
    }

    return $url;
  }

  /**
   * Executes a query with 'where', 'limit' and 'offset' parameters.
   *
   * @param string $method
   *   The REST API method name.
   * @param string $single_result_key
   *   The API result is expected to hold a one-element array with this value as
   *   a key. The inner value is returned if the result format is as expected
   *   and an exception is thrown otherwise.
   * @param array $where
   *   Sub-parameters for the 'where' parameter of the method.
   * @param null $limit
   *   A limit to the number of objects returned.
   * @param null $offset
   *   The index in the full list of objects, of the first object to return.
   *   Zero-based. (To reiterate: this number is 'object based', not 'batch/page
   *   based.)
   *
   * @return array
   *   The substructure we expected in the JSON array.
   *
   * @see $this->exec() for throws.
   */
  protected function execLimitedQuery($method, $single_result_key, array $where, $limit = NULL, $offset = NULL) {
    // API method definitions are inconsistent (according to the docs):
    // - most have 'where' defined as required, even when empty
    // - some have 'where' defined as optional (getEmailListing - this may not
    //    be true however; getActiveLists was wrongly documented too)
    // - some have no 'where' (getEmailJobs).
    // We'll hardcode these here. This serves as documentation ot the same time.
    if ($where || !in_array($method, ['getEmailLists', 'getEmailJobs'], TRUE)) {
      $params['where'] = $where;
    }
    else {
      $params = [];
    }
    if (isset($limit)) {
      $params['limit'] = $limit;
    };
    if (isset($offset)) {
      $params['offset'] = $offset;
    }
    return $this->exec($method, $params, ['single_result_key' => $single_result_key]);
  }

  /**
   * Abstracts some code shared by createLead(s) / updateLead(s) methods.
   *
   * @param array $leads
   *   Leads. (Both actual Lead objects and arrays are accepted.)
   * @param string $method
   *   The REST API method to call.
   * @param bool $throw_for_individual_object
   *   The 'throw_for_individual_object' key for $response_checks; see exec().
   *
   * @return mixed
   *
   * @throws \InvalidArgumentException
   *   If the input values are not all Leads.
   */
//@todo also handle arrays, not only Lead objects? Say so in all callers.
  protected function handleLeads(array $leads, $method, $throw_for_individual_object = FALSE) {
    $params['objects'] = [];
    foreach ($leads as $lead) {
      if (!is_array($lead) && !(is_object($lead) && $lead instanceof Lead)) {
        throw new \InvalidArgumentException("At least one of the arguments to $method() is not a lead.");
      }
      $lead = $this->toArray('lead', $lead);

      if (isset($lead['emailAddress'])) {
        if (!filter_var($lead['emailAddress'], FILTER_VALIDATE_EMAIL)) {
          // The REST API will happily insert bogus e-mails but we won't let
          // that happen.
          throw new \InvalidArgumentException("Lead has invalid e-mail address $lead[emailAddress]");
        }
      }
      elseif ($method === 'createLeads') {
        throw new \InvalidArgumentException("Lead has no e-mail address.");
      }
      elseif (empty($lead['id'])) {
        // The REST API would not catch this and return success without
        // updating anything. See updateLead() for comments.
        throw new \InvalidArgumentException("Lead has no e-mail address and no ID; updating it won't work.");
      }

      // Just a note: alphanumeric keys could be provided to the REST API
      // methods but don't make any difference in how the call is processed or
      // its returned results. It might have been convenient do preserve input
      // keys for the updateLeads call, but in the end that's only really useful
      // in case errors are encountered, and it's a bit too much trouble.
      $params['objects'][] = $lead;
    }
    $response_checks = [
      'single_result_key' => $method === 'createLeads' ? 'creates' : 'updates',
      'validate_result_with_objects' => TRUE,
      'throw_for_individual_object' => $throw_for_individual_object,
    ];
    return $params['objects'] ? $this->exec($method, $params, $response_checks) : [];
  }

  /**
   * Create a Lead object.
   *
   * About behavior: if a lead with the same e-mail address already exists, an
   * error (code 301, message "Entry already exists") will be returned. (Which
   * will cause an object-level SharpSpringRestApiException to be thrown.)
   *
   * The 'id' value will be ignored; a new object is always created (given no
   * other errors).
   *
   * If an object-level error is encountered, the SharpSpringRestApiException's
   * message / code will be that from the REST API (whereas a createLeads() call
   * for one object will throw a generic SharpSpringRestApiException with code
   * 0 that wraps the actual error).
   *
   * @param \SharpSpring\RestApi\Lead|array $lead
   *   A lead. (Both actual Lead objects and arrays are accepted.)
   *
   * @return array
   *    [ 'success': TRUE, 'error': NULL, 'id': <ID OF THE CREATED LEAD> ]
   *
   * @throws \InvalidArgumentException
   *   If the e-mail address is invalid.
   * @throws SharpSpringRestApiException
   *   If the REST API indicated that the lead failed to be created.
   *   isObjectLevel() tells whether it's an API-level or object-level error.
   * @throws \UnexpectedValueException
   *   If the REST API response has an unexpected format. (Since documentation
   *   is terse, this library does strict checks so that we're sure we do not
   *   ignore unknown data or return inconsistent structures.)
   * @throws \RuntimeException
   *   If the request to the REST API fails.
   */
// @TODO check whether this works and can throw an object-level error.
  public function createLead($lead) {
    $result = $this->handleLeads([$lead], 'createLeads', TRUE);
    // Now we know $result is a single-element array. Return the single object
    // result inside.
    return reset($result);
  }

  /**
   * Create one or more Lead objects in a single REST API call.
   *
   * Does nothing and returns empty array if an empty array is provided.
   *
   * About behavior: if a lead with the same e-mail address already exists, an
   * error (code 301, message "Entry already exists") will be returned. (Which
   * will cause a SharpSpringRestApiException to be thrown with code 0, and the
   * 301 error inside the object-level data.)
   *
   * 'id' values in a lead object are ignored; a new object is always created
   * (given no other errors).
   *
   * @param array $leads
   *   Leads. (Both actual Lead objects and arrays are accepted).
   *
   * @return array
   *   The API call result, which should be an array of sub-arrays for each lead
   *   each structured like [ 'success': TRUE, 'error': NULL, 'id': <NEW ID> ]
   *
   * @throws \InvalidArgumentException
   *   If the input values are not all Leads or one of them has an invalid
   *   e-mail address.
   * @throws SharpSpringRestApiException
   *   If the REST API indicated that the lead failed to be created.
   *   isObjectLevel() tells whether it's an API-level or object-level error; if
   *   it's true, the code will be 0 and getData() will return data for each
   *   processed object, containing success or the actual error code / message
   *   / data. See getData() for more details.
   * @throws \UnexpectedValueException
   *   If the REST API response has an unexpected format. (Since documentation
   *   is terse, we do strict checks so that we're sure we do not ignore unknown
   *   data.)
   * @throws \RuntimeException
   *   If the request to the REST API fails.
   */
  public function createLeads(array $leads) {
    return $this->handleLeads($leads, 'createLeads');
  }

  /**
   * Update a lead object.
   *
   * The lead object to be updated can be recognized by its id or emailAddress
   * property. In other words;
   * - If a provided Lead has an existing emailAddress and no id, the lead
   *   corresponding to the e-mail is updated.
   * - If a provided Lead has an existing id and no emailAddress, the lead
   *   corresponding to the id is updated.
   * - If a provided Lead has an existing id and an emailAddress that does not
   *   exist yet, the lead corresponding to the id is updated just like the
   *   previous case. (The e-mail address is changed and the old e-mail address
   *   won't exist in the database anymore.)
   *
   * BEHAVIOR WARNINGS: (tested on API v1.117, 20161205):
   *
   * - If a provided Lead has an existing id and an emailAddress that already
   *   exists in a different lead, nothing will be updated even though the API
   *   call will return success!
   * - If a provided Lead has a nonexistent id (regardless whether the
   *   emailAddress exists): same.
   * - If a provided Lead has no id and no emailAddress: same.
   * - If a provided Lead has no id and an emailAddress that does not yet exist:
   *   same.
   *
   * - If an update does not actually change anything, the REST API will return
   *   an object-level error 302 "No table rows affected".
   *
   * While cases 2 and 3 are obvious (and it's understandable though unfortunate
   * that 4 does not create a new object), case 1 is a real issue. This means
   * that unless you *know* that the e-mail address in your updated lead does
   * not exist yet elsewhere in the Sharpspring database, you cannot trust your
   * updates. (So if you know you are not changing the e-mail address with an
   * update: you're fine. Otherwise: you're not, unless you are sure it does not
   * exist yet in another lead.) In this case you *must* doublecheck whether the
   * update succeeded by doing a getLead() on the id you are updating, and
   * seeing if the e-mail is actually the value you expect. If not, you should
   * assume the update silently failed.
   *
   * If an object-level error is encountered, the SharpSpringRestApiException's
   * message / code will be that from the REST API (whereas a createLeads() call
   * for one object will throw a generic SharpSpringRestApiException with code
   * 0 that wraps the actual error).
   *
   * @param \SharpSpring\RestApi\Lead|array $lead
   *   A lead. (Both actual Lead objects and arrays are accepted.)
   *
   * @return array
   *   A fixed value: [ 'success': TRUE, 'error': NULL ]. (The value is not
   *   much use at the moment but is kept like this in case the REST API
   *   extends its functionality, like createLead where it returns extra info.)
   *
   * @throws \InvalidArgumentException
   *   If the lead has an invalid e-mail address or no address/id.
   * @throws SharpSpringRestApiException
   * @throws \UnexpectedValueException
   * @throws \RuntimeException
   *
   * @see createLead()
   *
   * @todo implement some 'fix' option (in 2nd array-argument) to fix the 302 ?
   * @todo check what updateLeads() does with one updatable item and one no-op.
   */
  public function updateLead($lead) {
    // See createLead() for code comments.
    $result = $this->handleLeads([$lead], 'updateLeads', TRUE);
    return reset($result);
  }

  /**
   * Update one or more Lead objects in a single REST API call.
   *
   * Does nothing and returns empty array if an empty array is provided.
   *
   * See updateLead() documentation for caveats.
   *
   * @param array $leads
   *   Leads. (Both actual Lead objects and arrays are accepted).
   *
   * @return array
   *   The API call result, which should be an array of fixed values for each
   *   lead: [ 'success': TRUE, 'error': NULL ]
   *
   * @throws \InvalidArgumentException
   *   If the input values are not all Leads or one of them has an invalid
   *   e-mail address or no address/id.
   * @throws SharpSpringRestApiException
   * @throws \UnexpectedValueException
   * @throws \RuntimeException
   *   See createLeads().
   *
   * @see createLeads()
   * @see updateLead()
   */
  public function updateLeads(array $leads) {
    return $this->handleLeads($leads, 'updateLeads');
//@TODO check.
  }

  /**
   * Delete a single lead identified by id.
   *
   * @param string $id
   *
   * @return true
   *
   * @TODO document throws here? in deleteLeads() too?
   */
  public function deleteLead($id) {
    $params['objects'] = [];
    $params['objects'][] = ['id' => $id];
    $result = $this->exec('deleteLeads', $params, [
      'single_result_key' => 'deletes',
      'validate_result_with_objects' => TRUE,
      'throw_for_individual_object' => TRUE,
    ]);
    return reset($result);
//@TODO test. And what does this return exactly? Is this an array now?
  }

  /**
   * Delete multiple leads identified by id.
   *
   * Does nothing and returns empty array if an empty array is provided.
   *
   * @param string[] $ids
   *
   * @return array
   *   An array of results per object; each result has two keys: 'success'
   *   (boolean) and 'error' (NULL or an array containing error specification).
   *
@TODO TEST: one  good delete, one bad one. Check if the new validation works.
   *
   */
  public function deleteLeads(array $ids) {
    if (!$ids) {
      return [];
    }
    // The 'objects' parameter is actually a list of objects, but it does not
    // support e.g. 'emailAddress' as key. So each individual object is just a
    // one-element array that must be the ID, keyed by "id".
    $params['objects'] = [];
    foreach ($ids as $id) {
      $params['objects'][] = ['id' => $id];
    }
    return $this->exec('deleteLeads', $params, [
      'single_result_key' => 'deletes',
      'validate_result_with_objects' => TRUE,
    ]);
  }

  /**
   * Retrieve a single Lead by its ID.
   *
   * Some standard string fields returned from the API (e.g. title, street)
   * contain NULL values by default (unlike the custom fields which contain an
   * empty string by default - this goes for string as well as bit fields). It's
   * recommended that this NULL value is treated the same as an empty string**
   * because these fields are not nullable. Once they contain a value, they can
   * only be emptied out by updating them to an empty string; trying to update
   * them to NULL will return an object-level error 205 "Invalid parameters".)
   *
   * ** (Detail: the REST API itself seems to think these values are different
   *    internally, because updating a NULL value to '' won't return an
   *    object-level error 302 "No table rows affected".)
   *
   * @param string $id
   *   The lead's ID. (It's actually a numeric string but 12 digits, so not an
   *   int.)
   * @param array $options
   *   (optional) One option key is recognized so far: 'fix_empty_leads'. If set
   *   to a 'true' value, then return an empty array if the lead returned from
   *   the REST API contains no 'id' value. Reason: (as of API v1.117, 20170127)
   *   queries for a nonexistent lead will return an array with values
   *   leadStatus = open, and all custom fields with an empty value; no other
   *   values. This class chooses to not alter return values by default
   *   (because who knows what hidden problems that could cause), in the hope
   *   that the REST API will be fixed). This means that until then, you have a
   *   choice between passing [ 'fix_empty_leads' => TRUE ] into this method, or
   *   assuming that a non-empty return value does not actually mean that a lead
   *   exists...
   *
   * @return array
   *   A lead structure (in array format as returned from the REST API; not as
   *   a Lead object).
   *
   * @todo here and in getLeads, do a 'fix' property to convert nulls to empty
   *   strings for non-nullable values?
   */
  public function getLead($id, $options = []) {
    $params['id'] = $id;
    $leads = $this->exec('getLead', $params, ['single_result_key' => 'lead']);
    // For some reason getLead returns an array of exactly one leads. Not sure
    // why that is useful. We'll just return one lead - but then we need to
    // first validate that we have exactly one.
    if (count($leads) > 1) {
      throw new \UnexpectedValueException("Sharpspring REST API failure: response result 'lead' value contains more than one object.'\nResponse: " . json_encode($leads), 16);
    }
    $lead = reset($leads);
    if (!empty($options['fix_empty_leads']) && !isset($lead['id'])) {
      $lead = [];
    }
    return $lead;
  }

  /**
   * Gets a number of lead objects.
   *
   * @param array $where
   *   A key-value array containing ONE item only, with key being either 'id' or
   *  'emailAddress' - because that is all the REST API supports. The return
   *  value will be one lead only, and will also return the corresponding lead
   *  if it is inactive. If this parameter is not provided, only active leads
   *  are returned.
   * @param null $limit
   *   A limit to the number of objects returned.
   * @param null $offset
   *   The index in the full list of objects, of the first object to return.
   *   Zero-based. (To reiterate: this number is 'object based', not 'batch/page
   *   based.)
   * @param array $options
   *   (optional) One option key is recognized so far: 'fix_empty_leads'. See
   *   getLead().
   *
   * @return array
   *   An array of lead structures (in array format as returned from the REST
   *   API; not as Lead objects). The response does not wrap the leads inside
   *   another array that also has a 'hasMore' indicator, like with some other
   *   calls. See getLead() for comment on 'null string values'.
   */
  public function getLeads($where = [], $limit = NULL, $offset = NULL, $options = []) {
    $leads = $this->execLimitedQuery('getLeads', 'lead', $where, $limit, $offset);
    if (!empty($options['fix_empty_leads'])) {
      foreach ($leads as $key => $lead) {
        if (!isset($lead['id'])) {
          unset($leads[$key]);
        }
      }
      // Rehash keys just to be sure the caller won't get into trouble if it
      // expects consecutive zero-based keys.
      $leads = array_values($leads);
    }
    return $leads;
  }

  /**
   * Retrieve a list of Leads that were created or updated in a timeframe.
   *
   * Please note the returned updateTimestamp value for the leads is expressed
   * in UTC, while the getLead() / getLeads() calls it is in the local timezone.
   *
   * If a lead was updated to be inactive, it is still part of the 'update'
   * dataset retrieved by this call.
   *
   * @param string $start_date
   *   Start of date range; format Y-m-d H:i:s, assuming UTC.
   * @param string $end_date
   *   (optional) End of date range; format Y-m-d H:i:s, assuming UTC. Defaults
   *   to 'now'.
   * @param $time_type
   *   (optional) The field to filter for dates: 'update' (default) or 'create'.
   *
   * @return array
   *   An array of Lead structures.
   *
   * @see Lead::$updateTimestamp
   */
  public function getLeadsDateRange($start_date, $end_date = '', $time_type = 'update') {
    $params['startDate'] = $start_date;
    $params['endDate'] = $end_date ? $end_date : gmdate('Y-m-d H:i:s');
    $params['timestamp'] = $time_type;
// @todo do this better and implement it. (Also: this can go to >3500 so is there really a 500 limit to getLeads? Re-test, and doc.)
    $params['limit'] = 5000;
//    $params['offset'] = 600;
    return $this->exec('getLeadsDateRange', $params, ['single_result_key' => 'lead']);
  }

  /**
   * Get a list of fields.
   *
   * @param int $limit
   * @param int  $offset
   *
   * @return array
   *   An array of Field structures. (Note: no 'hasMore' indicator.)
   */
  public function getFields($limit = NULL, $offset = NULL) {
    return $this->execLimitedQuery('getFields', 'field', [], $limit, $offset);
  }

  /**
   * Retrieve a single Account by its ID.
   *
   * @param int $id
   *
   * @return array
   *   An Account structure.
   */
  public function getAccount($id) {
    $params['id'] = $id;
    return $this->exec('getAccount', $params, ['single_result_key' => 'account']);
  }

  /**
   * Retrieve a list of Accounts given a WHERE clause,
   * or retrieve all Accounts if WHERE clause is empty.
   *
   * @param array $where
   *   Conditions
   *   - id
   *   - ownerID
   * @param int $limit
   *   Limit.
   * @param int $offset
   *   Offset.
   *
   * @return mixed
   *   An array of Account structures. (Note: no 'hasMore' indicator.)
   */
  public function getAccounts(array $where = [], $limit = NULL, $offset = NULL) {
    return $this->execLimitedQuery('getAccounts', 'account', $where, $limit, $offset);
  }

  /**
   * Retrieve a list of Accounts that were created or updated in a timeframe.
   *
   * @param string $start_date
   *   Start of date range; format Y-m-d H:i:s, assuming UTC (not tested).
   * @param string $end_date
   *   (optional) End of date range; format Y-m-d H:i:s, assuming UTC (not
   *   tested). Defaults to 'now'.
   * @param $time_type
   *   (optional) The field to filter for dates: 'update' (default) or 'create'.
   *
   * @return array
   *   An array of Account structures.
   *
   * @see Lead::$updateTimestamp
   */
  public function getAccountsDateRange($start_date, $end_date = '', $time_type = 'update') {
    $params['startDate'] = $start_date;
    $params['endDate'] = $end_date ? $end_date : gmdate('Y-m-d H:i:s');
    $params['timestamp'] = $time_type;
    return $this->exec('getAccountsDateRange', $params, ['single_result_key' => 'account']);
  }

  /**
   * Retrieve a single Campaign by its ID.
   *
   * @param int $id
   *   The campaign ID.
   * @return array
   *   A Campaign structure.
   */
  public function getCampaign($id) {
    $params['id'] = $id;
    return $this->exec('getCampaign', $params, ['single_result_key' => 'campaign']);
  }

  /**
   * Retrieve a list of Campaigns given a WHERE clause,
   * or retrieve all Campaigns if WHERE clause is empty.
   *
   * @param array $where
   *   Conditions
   *   - id
   *   - ownerID
   * @param int $limit
   *   Limit.
   * @param int $offset
   *   Offset.
   *
   * @return array
   *   An array of Campaign structures. (Note: no 'hasMore' indicator.)
   */
  public function getCampaigns(array $where = [], $limit = NULL, $offset = NULL) {
    return $this->execLimitedQuery('getCampaigns', 'campaign', $where, $limit, $offset);
  }

  /**
   * Retrieve a list of Campaigns that have been created/updated in a timeframe.
   *
   * @param string $start_date
   *   Start of date range; format Y-m-d H:i:s, assuming UTC (not tested).
   * @param string $end_date
   *   (optional) End of date range; format Y-m-d H:i:s, assuming UTC (not
   *   tested). Defaults to 'now'.
   * @param $time_type
   *   (optional) The field to filter for dates: 'update' (default) or 'create'.
   *
   * @return array
   *   An array of Campaign structures. (Note: no 'hasMore' indicator.)
   *
   * @see Lead::$updateTimestamp
   */
  public function getCampaignsDateRange($start_date, $end_date = '', $time_type = 'update') {
    $params['startDate'] = $start_date;
    $params['endDate'] = $end_date ? $end_date : gmdate('Y-m-d H:i:s');
    $params['timestamp'] = $time_type;
    return $this->exec('getCampaignsDateRange', $params, ['single_result_key' => 'campaign']);
  }

  /**
   * Get a list of all active companies managed by your company.
   *
   * @return array
   * Result with 2 keys:
   * - getAllcompanyProfileManagedBys
   * - hasMore
   *
   * @todo check what to do with hasMore, if this is always there
   * @todo single_result_key?
   */
  public function getClients() {
    return $this->exec('getClients', []);
  }

  /**
   * Retrieve a single DealStage by its ID.
   *
   * @param int $id
   *
   * @return array
   *   A DealStage structure.
   */
  public function getDealStage($id) {
    $params['id'] = $id;
    $result = $this->exec('getDealStage', $params, ['single_result_key' => 'dealStage']);
  }


  /**
   * Retrieve a list of DealStage objects given a WHERE clause,
   * or retrieve all DealStage objects if WHERE clause is empty.
   *
   * @param array $where
   *   Conditions
   *   - id
   *   - ownerID
   * @param int $limit
   *   Limit.
   * @param int $offset
   *   Offset.
   *
   * @return array
   *   An array of DealStage structures.
   */
  public function getDealStages(array $where = [], $limit = NULL, $offset = NULL) {
    return $this->execLimitedQuery('getDealStages', 'dealStage', $where, $limit, $offset);
  }

  /**
   * Retrieve a list of DealStages that were created or updated in a timeframe.
   *
   * @param string $start_date
   *   Start of date range; format Y-m-d H:i:s, assuming UTC (not tested).
   * @param string $end_date
   *   (optional) End of date range; format Y-m-d H:i:s, assuming UTC (not
   *   tested). Defaults to 'now'.
   * @param $time_type
   *   (optional) The field to filter for dates: 'update' (default) or 'create'.
   *
   * @return array
   *   An array of DealStage structures.
   *
   * @see Lead::$updateTimestamp
   */
  public function getDealStagesDateRange($start_date, $end_date = '', $time_type = 'update') {
    $params['startDate'] = $start_date;
    $params['endDate'] = $end_date;
    $params['endDate'] = $end_date ? $end_date : gmdate('Y-m-d H:i:s');
    return $this->exec('getDealStagesDateRange', $params, ['single_result_key' => 'dealStage']);
  }

  /**
   * Returns a list of active Lists.
   *
   * As of v1.1.17, the API documentation says that the 'where' parameter is
   * "optional", whereas in other places it says "required" (even when it may be
   * empty). The documentation is inconsistent; the 'where' parameter is
   * required here too (though it may be empty).
   *
   * @param int $id
   *   (optional) List ID.
   *
   * @return array
   *   An array of List structures.
   */
  public function getActiveLists($id = NULL, $limit = NULL, $offset = NULL) {
    // 'where' is a required parameter but it may be empty.
    $where = [];
    if (isset($id)) {
      $where['id'] = $id;
    }
    return $this->execLimitedQuery('getActiveLists', 'activeList', $where, $limit, $offset);
  }

  /**
   * Returns the active members for a specific list
   *
   * @param int $id
   *   List ID. Unknown values will not return a validation error; they will
   *   just make the method return an empty list.
   * @param null $limit
   *   A limit to the number of objects returned.
   * @param null $offset
   *   The index in the full list of objects, of the first object to return.
   *   Zero-based. (To reiterate: this number is 'object based', not 'batch/page
   *   based.)
   *
   * @return array
   * Result with 2 keys:
   * - getWherelistLeadMembers
   * - hasMore
   *
   * @todo check what to do with hasMore, if this is always there
   */
  public function getListMembers($id, $limit = NULL, $offset = NULL) {
    $where = ['id' => $id];
    return $this->execLimitedQuery('getListMembers', '', $where, $limit, $offset);
  }

  /**
   * Get the members that are removed from a list.
   *
   * @param int $id
   *   List ID. Unknown values will not return a validation error; they will
   *   just make the method return an empty list.
   * @param string $flag
   *   (optional) "removed", "unsubscribed" or "hardbounced". The REST API will
   *   default to returning "removed" members. Unknown values will not return
   *   a validation error; they will just make the method return an empty list.
   * @param null $limit
   *   A limit to the number of objects returned.
   * @param null $offset
   *   The index in the full list of objects, of the first object to return.
   *   Zero-based. (To reiterate: this number is 'object based', not 'batch/page
   *   based.)
   *
   * @return array
   *   Same format as getListMembers.

   * @todo see getListMembers
   */
  public function getRemovedListMembers($id, $flag = NULL, $limit = NULL, $offset = NULL) {
    $where = ['id' => $id];
    if (isset($flag)) {
      $where['flag'] = $flag;
    }
    return $this->execLimitedQuery('getRemovedListMembers', '', $where, $limit, $offset);
  }

  /**
   * UNKNOWN.
   *
   * @return array
   * Result with 2 keys:
   * - getAllunsubscribeCategorys
   * - hasMore
   *
   * @todo check what to do with hasMore, if this is always there
   * @todo single_result_key?
   */
  public function getUnsubscribeCategories() {
    return $this->exec('getUnsubscribeCategories', []);
  }

}
