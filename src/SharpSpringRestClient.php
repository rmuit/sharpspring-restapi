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
  /**
   * {@inheritdoc}
   *
   * @var array
   */
  protected $_nullableProperties = array('accountID', 'ownerID');

  /**
   * Indicator whether this is an active lead. Must be 0 or 1.
   *
   * This is not among the fields returned by getFields(); it's a special
   * property to deactivate leads (make them invisible and not be part of the
   * return values in a getLeads() call unless the lead is requested by its
   * specific id/emailAddress.
   *
   * @var int
   */
  public $active;

  /**
   * SharpSpring ID.
   *
   * This is one of the two possible 'identifier properties' for a lead.
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
   * In Sharpspring this is data type 'email'.
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
   * this empty when creating a contact will set it to 'unqualified'.
   * @TODO check whether other values generate error and document here
   *
   * @var string
   */
  public $leadStatus;

  /**
   * Lead Score.
   *
   * Not checked whether this is read only.
   * @var int
   */
  public $leadScore;

  /**
   * Is Unsubscribed?
   *
   * @TODO check whether you can set this. And whether you can unset this afterwards.
   *
   * @var string
   */
  public $isUnsubscribed;

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
   * Last updated time.
   *
   * Date in ISO format, e.g. '2016-12-06 00:52:12'
   *
   * For some reason, this is actually updatable.
   *
   * @var string
   */
  public $updateTimestamp;

  /**
   * Lead constructor.
   *
   * @param mixed $param
   *   A unique itendifier for the lead (ID or e-mail address), or an array of
   *   properties which must be keyed by the property names - except in the case
   *   of custom fields; then they must be keyed by th custom field system name.
   * @param array $custom_properties
   *   The custom property name to Sharpspring field system name mapping, which
   *   should be used. Any fields not specified here will be taken from
   *   $this->_customProperties if they are defined there. Only valid if $param
   *   is an array.
   *
   * @throws \InvalidArgumentException
   *   If the emailAddress provided is invalid.
   *
   * @todo we also allow Leads to be constructed with only an ID because
   *   sometimes we need that. (For e.g. deactivating an item, whose e-mail
   *   address we don't care about.) This means that the validation of the
   *   e-mail address here may be a bit out of place. However we have to have it
   *   somewhere because there is NO validation at all on e-mail address
   *   validity by the REST API. (Despite the fact that 'email' is a separate
   *   field type, it will accept just any value.) Think about this.
   */
  public function __construct($param, array $custom_properties = []) {
    if (is_array($param)) {
      if (!empty($param['emailAddress']) && !filter_var($param['emailAddress'], FILTER_VALIDATE_EMAIL)) {
        throw new \InvalidArgumentException('The provided email address is invalid.');
      }
      parent::__construct($param, $custom_properties);
    }
    elseif (is_numeric($param)) {
      // @todo maybe some validation
      $this->id = $param;
    }
    elseif (filter_var($param, FILTER_VALIDATE_EMAIL)) {
      $this->emailAddress = $param;
    }
    else {
      throw new \InvalidArgumentException('The provided email address is invalid.');
    }
  }

}

/**
 * Base class for Sharpspring value objects (Leads, etc).
 *
 * A value object represents e.g. a lead with all its properties predefined.
 * This is especially useful over using arrays because the Sharpspring API
 * objects' property names are case sensitive, leading to easily misspelling.
 *
 * Objects are converted to an array before e.g. JSON-encoding them for REST API
 * communication; this should be done using the toArray() method rather than
 * casting to an array (so all unwanted properties get cleared).
 *
 * A subclass can be defined to add custom properties that are not necessarily
 * equal to the system names of custom Sharpspring fields; see
 * $_customProperties.
 */
class ValueObject {
  /**
   * All property names in the object that are nullable .
   *
   * Most defined properties in a new object start out as unset === NULL. We
   * don't want to send NULL for all those property values, so toArray() unsets
   * all NULL properties. The problem with that is, some properties have to be
   * able to be set explicitly to NULL.
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
   * names. (Using a subclass with explicitly defined property names gets you
   * IDE autocompletion.)
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
   *   Values to initialize in the lead object. We assume custom field values
   *   are set with a Sharpspring 'field system name' key; the corresponding
   *   property will be set to this value.
   * @param array $custom_properties
   *   The custom property name to Sharpspring field system name mapping, which
   *   should be used. Any fields not specified here will be taken from
   *   $this->_customProperties if they are defined there.
   */
  public function __construct(array $values, array $custom_properties = []) {
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
        // object, and that no duplicate properties are set to the same field
        // system name.  If that happens, values can get lost in the array.)
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
  }

  /**
   * Signifies whether the exception was thrown for a single object-level error.
   *
   * The default is an API-level error, which indicates an error was encountered
   * during processing of the request. An object-level error means the request
   * did not fail but handling one object (of possibly several in the same
   * request) failed.
   *
   * It's important for at least tweaking the string representation a bit.
   *
   * @return bool
   *   If TRUE, an object-level (as opposed to API-level) error was encountered.
   */
  public function isObjectLevel() {
    return $this->objectLevel;
  }

  /**
   * Gets the data array (if any) returned by the REST API along with the error.
   *
   * For API-level errors, this is the 'error' data from the response; for
   *  object-level errors, this is the response data for all processed objects,
   * containing either 'success' == TRUE, or non-null 'error' data, per object.
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
   * This is not necessary if your custom value class definition already
   * contains that mapping (and you don't mind your code not being portable
   * across different Sharpspring accounts / environments).
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
   * Execute a query against REST API.
   *
   * @param string $method
   *   The REST API method name.
   * @param array $params
   *   The parameters.
   * @param string $single_result_key
   *   (optional) If provided, the API result is expected to hold a one-element
   *   array with this value as a key. In this case, the inner value is returned
   *   if the result format is as expected and an exception is thrown otherwise.
   * @param bool $check_single_object_error
   *   (optional) Internal. If true, then don't throw our default "call returned
   *   at least one object-level error" exception when encountering an object
   *   level error, but throw an exception with the specific error's code and
   *   message instead. (This should only be TRUE if the 'objects' parameter
   *   contains only one object.)
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
  public function exec($method, array $params, $single_result_key = '', $check_single_object_error = FALSE) {
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
        return [];
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

    // Regardless of error or success: if we expect the result to only have one
    // key for this specific method (which the caller must indicate) then
    // validate this.
    if ($single_result_key) {
      if (!is_array($response['result']) || count($response['result']) != 1) {
        throw new \UnexpectedValueException("Sharpspring REST API failure: response result is not a one-element array.'\nResponse: " . json_encode($response), 4);
      }
      if (!isset($response['result'][$single_result_key])) {
        throw new \UnexpectedValueException("Sharpspring REST API failure: response result does not contain key $single_result_key.\nResponse: " . json_encode($response), 5);
      }
    }
    $result = $single_result_key ? $response['result'][$single_result_key] : $response['result'];

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

      // Validate the structure of the result (but not the individual objects in
      // the result) and compare with the number of input parameters.
      if (!isset($params['objects']) || !is_array($params['objects'])) {
        throw new \UnexpectedValueException("Sharpspring REST API interpreter failure while evaluating error: no 'objects' (array) input parameter present for the $method method.\nResponse: " . json_encode($response), 6);
      }
      // If $check_single_object_error == TRUE, this should throw a
      // SharpSpringRestApiException with the specific message / code / data.
      $this->validateResultForObjects($result, $params['objects'], $method, $check_single_object_error, TRUE);

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

      // At this point we know we won't lose any info by returning only $result.
      // We have not validated the structure/content of the object data inside
      // $result - except if $check_single_object_error told us so.
      if ($check_single_object_error) {
        // We should never have ended up here.
        throw new \UnexpectedValueException("Sharpspring REST API interpreter failure: error was set but the result contains no object errors.\nResponse: " . json_encode($response), 13);
      }
      // We can only throw one single exception here, so interpreting individual
      // objects does not make sense and is not our business anyway.
      // Since this is not an API level error, we don't have a single error
      // code: use code 0 (and do not flag this as an 'object-level' exception).
      throw new SharpSpringRestApiException("$method call returned at least one object-level error", 0, $result);
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
   *   moment they are only used to derive a count, but who knows...)
   * @param string $method
   *   The REST API method called. (Used to make the exception message clearer.)
   * @param bool $validate_individual_objects
   *   (optional) If FALSE, only validate the structure of the result. By
   *   default (TRUE), also validate the contents of the individual object
   *   results.
   * @param bool $error_encountered
   *   (optional) TRUE if an 'error' result is being evaluated. This is used to
   *   make the exception message clearer. Should not be necessary for external
   *   code because the API call already validates the structure of 'error'
   *   results.
   *
   * @throws SharpSpringRestApiException
   *   If the result contains an object-level error; only possible for
   *   $validate_individual_objects = TRUE.
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
        $this->validateObjectResult(reset($result));
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
    // - some have 'where' defined as optional (getEmailListing - this may not be
    //    be true however; getActiveLists was wrongly documented too)
    // - some have no 'where' (getEmailJobs).
    // We'll hardcode these here. This serves as documentation ot the same time.
    if ($where || !in_array($method, array('getEmailLists', 'getEmailJobs'), TRUE)) {
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
    return $this->exec($method, $params, $single_result_key);
  }

  /**
   * Abstracts some code shared by createLead(s) / updateLead(s) methods.
   *
   * @param \SharpSpring\RestApi\Lead[] $leads
   *   Lead objects.
   * @param string $method
   *   The REST API method to call.
   * @param bool $check_single_object_error
   *   See exec().
   *
   * @return mixed
   *
   * @throws \InvalidArgumentException
   *   If the input values are not all Leads.
   */
   protected function handleLeads(array $leads, $method, $check_single_object_error = FALSE) {
     $params['objects'] = [];
     foreach ($leads as $lead) {
       if (!(is_object($lead) && $lead instanceof Lead)) {
         throw new \InvalidArgumentException("At least one of the arguments to $method() is not a lead.");
       }
       // Alphanumeric keys can be provided here but don't make any difference.
       $params['objects'][] = $lead->toArray(isset($this->customPropertiesByType['lead']) ? $this->customPropertiesByType['lead'] : []);
     }
     $single_result_key = $method === 'createLeads' ? 'creates' : 'updates';
     return $params['objects'] ? $this->exec($method, $params, $single_result_key, $check_single_object_error) : [];
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
   * @param \SharpSpring\RestApi\Lead $lead
   *
   * @return array
   *    [ 'success': TRUE, 'error': NULL, 'id': <ID OF THE CREATED LEAD> ]
   *
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
  public function createLead(Lead $lead) {
    $result = $this->handleLeads([$lead], 'createLeads', TRUE);
    // The response indicated no error. Then it would be very strange if the
    // contents of the result indicated anything else but success... Check it
    // anyway and throw an exception for unexpected results, so the caller gets
    // predictable results. If this ever does start throwing an exception, we
    // should change the code/docs to reflect current reality.
    $this->validateResultForObjects($result, [$lead], 'createLeads');
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
   * @param \SharpSpring\RestApi\Lead[] $leads
   *
   * @return array
   *   The API call result, which should be an array of sub-arrays for each lead
   *   each structured like [ 'success': TRUE, 'error': NULL, 'id': <NEW ID> ]
   *
   * @throws \InvalidArgumentException
   *   If the input values are not all Leads.
   * @throws SharpSpringRestApiException
   *   If at least one of the leads failed to be created. isObjectLevel() will
   *   always return FALSE; call getCode() to distinguish an API-level error
   *   (which has a non-zero code and a message / error data as returned by
   *   the API) from an exception containing info about one or several
   *   object-level errors (which has code 0; getData() getData() holds the call
   *   result containing sub-arrays for each lead, indicating success or error.
   *   If creation succeeded, the value is as documented above; otherwise the
   *   'error' data has more info.)
   * @throws \UnexpectedValueException
   *   If the REST API response has an unexpected format. (Since documentation
   *   is terse, we do strict checks so that we're sure we do not ignore unknown
   *   data.)
   * @throws \RuntimeException
   *   If the request to the REST API fails.
   */
  public function createLeads(array $leads) {
    $result = $this->handleLeads($leads, 'createLeads');
    // At least validate the result format. (See createLead() for more on that.)
    // We don't validate the contents (to protect against the theoretical case
    // that an object inside the result would contain an error while the 'error'
    // key in the outer response is not set), because that would mean we could
    // end up returning (exception) data for a random single lead and the other
    // leads' data gets lost.
    $this->validateResultForObjects($result, $leads, 'createLeads', FALSE);
    return $result;
  }

  /**
   * Update a lead object.
   *
   * The lead object to be updated can be recognized by its id or emailAddress
   * property. In other words;
   * - if a provided Lead has an existing emailAddress and no id, the lead
   *   corresponding to the e-mail is updated.
   * - if a provided Lead has an existing id and no emailAddress, the lead
   *   corresponding to the id is updated.
   * - if a provided Lead has an existing id and an emailAddress that does not
   *   exist yet, the lead corresponding to the id is updated just like the
   *   previous case. (The e-mail address is changed and the old e-mail address
   *   won't exist in the database anymore.)
   *
   * BEHAVIOR WARNINGS: (tested on API v1.117, 20161205):
   *
   * - if a provided Lead has an existing id and an emailAddress that already
   *   exists in a different lead, nothing will be updated even though the API
   *   call will return success!
   * - if a provided Lead has a nonexistent id (regardless whether the
   *   emailAddress exists): same.
   * - if a provided Lead has no id and no emailAddress: same.
   * - if a provided Lead has no id and an emailAddress that does not yet exist:
   *   same.
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
   * @param \SharpSpring\RestApi\Lead $lead
   *
   * @return array
   *   A fixed value: [ 'success': TRUE, 'error': NULL ]. (The value is not
   *   much use at the moment but is kept like this in case the REST API
   *   extends its functionality, like createLead where it returns extra info.)
   *
   * @throws SharpSpringRestApiException
   * @throws \UnexpectedValueException
   * @throws \RuntimeException
   *
   * @see createLead()
   */
  public function updateLead(Lead $lead) {
//@TODO test this.
    // See createLead() for code comments.
    $result = $this->handleLeads([$lead], 'updateLeads', TRUE);
    $this->validateResultForObjects($result, [$lead], 'updateLeads');
    return reset($result);
  }

  /**
   * Update one or more Lead objects in a single REST API call.
   *
   * Does nothing and returns empty array if an empty array is provided.
   *
   * See updateLead() documentation for caveats.
   *
   * @param \SharpSpring\RestApi\Lead[] $leads
   *
   * @return array
   *   The API call result, which should be an array of fixed values for each
   *   lead: [ 'success': TRUE, 'error': NULL ]
   *
   * @throws SharpSpringRestApiException
   * @throws \UnexpectedValueException
   * @throws \RuntimeException
   *   See createLeads().
   *
   * @see createLeads()
   * @see updateLead()
   */
  public function updateLeads(array $leads) {
    $result = $this->handleLeads($leads, 'updateLeads');
    // See createLeads() for code comments.
    $this->validateResultForObjects($result, $leads, 'updateLeads', FALSE);
    return $result;
//@TODO check. Also: reinstate any array keys that would have been passed in? <--- for error data in the exception, that is.
  }

  /**
   * Delete a single lead identified by id.
   *
   * WARNING: (tested on API v1.117, 20161205:) the lead will not be completely
   * gone from the system! After deletion, a getLead() with the same ID will
   * return an object wiht the custom fields and leadStatus still intact (but no
   * id)!
   *
   * @param int $id
   *
   * @return true
   *
   * @TODO document throws here? in deleteLeads() too?
   */
  public function deleteLead($id) {
    $params['objects'] = [];
    $params['objects'][] = ['id' => $id];
    $result = $this->exec('deleteLeads', $params, 'deletes', TRUE);
    $this->validateResultForObjects($result, array($id), 'deleteLeads');
    return reset($result);
//@TODO test. And what does this return exactly? Is this an array now?
  }

  /**
   * Delete a single lead identified by email address.
   *
   * @TODO we know the e-mail is unique so change this function's output?
   *
   * @param string $email
   *
   * @return array
   */
  public function deleteLeadByEmail($email) {
    $leads = $this->getLeads(['emailAddress' => $email]);
    $result = [];
    if (!empty($leads)) {
      foreach ($leads as $lead) {
        $result[] = $this->deleteLeads([$lead['id']]);
      }
    }

    return $result;
  }

  /**
   * Delete mulitple leads identified by id.
   *
   * Does nothing and returns empty array if an empty array is provided.
   *
   * @param int[] $ids
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
    return $this->exec('deleteLeads', $params, 'deletes');
//@TODO also do the below here?
    // See createLeads() for code comments.
    $this->validateResultForObjects($result, $params['objects'], 'deleteLeads', FALSE);
    return $result;
  }

  /**
   * Retrieve a single Lead by its ID.
   *
   * @param int $id
   *
   * @return mixed
   */
  public function getLead($id) {
    $params['id'] = $id;
    return $this->exec('getLead', $params, 'lead');
  }

  /**
   * Get all leads within limit.
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
   *
   * @return array
   *   An array of Lead structures. (Note: no 'hasMore' indicator.)
   */
  public function getLeads($where = [], $limit = NULL, $offset = NULL) {
    return $this->execLimitedQuery('getLeads', 'lead', $where, $limit, $offset);
  }

  /**
   * Retrieve a list of Leads that have been either created or updated
   * between two timestamps.
   * Timestamps must be specified in Y-m-d H:i:s format
   *
   * @param string $start_date
   *   Start of date range; format Y-m-d H:i:s (timezone info is not given).
   * @param string $end_date
   *   End of date range; format Y-m-d H:i:s (timezone info is not given).
   * @param $timestamp_type
   *   The field to filter for dates: 'create' or 'update'.
   *
   * @return array
   *   An array of Lead structures.
   */
  public function getLeadsDateRange($start_date, $end_date, $timestamp_type) {
    $params['startDate'] = $start_date;
    $params['endDate'] = $end_date;
    $params['timestamp'] = $timestamp_type;
    return $this->exec('getLeadsDateRange', $params, 'lead');
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
    return $this->exec('getAccount', $params, 'account');
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
   * Retrieve a list of Accounts that have been either created or updated
   * between two timestamps.
   * Timestamps must be specified in Y-m-d H:i:s format
   *
   * @param string $start_date
   *   Start of date range; format Y-m-d H:i:s (timezone info is not given).
   * @param string $end_date
   *   End of date range; format Y-m-d H:i:s (timezone info is not given).
   * @param $timestamp_type
   *   The field to filter for dates: 'create' or 'update'.
   *
   * @return array
   *   An array of Account structures.
   */
  public function getAccountsDateRange($start_date, $end_date, $timestamp_type) {
    $params['startDate'] = $start_date;
    $params['endDate'] = $end_date;
    $params['timestamp'] = $timestamp_type;
    return $this->exec('getAccountsDateRange', $params, 'account');
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
    return $this->exec('getCampaign', $params, 'campaign');
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
   * Retrieve a list of Campaigns that have been either created or updated
   * between two timestamps.
   * Timestamps must be specified in Y-m-d H:i:s format
   *
   * @param string $start_date
   *   Start of date range; format Y-m-d H:i:s (timezone info is not given).
   * @param string $end_date
   *   End of date range; format Y-m-d H:i:s (timezone info is not given).
   * @param $timestamp_type
   *   The field to filter for dates: 'create' or 'update'.
   *
   * @return array
   *   An array of Campaign structures. (Note: no 'hasMore' indicator.)
   */
  public function getCampaignsDateRange($start_date, $end_date, $timestamp_type) {
    $params['startDate'] = $start_date;
    $params['endDate'] = $end_date;
    $params['timestamp'] = $timestamp_type;
    return $this->exec('getCampaignsDateRange', $params, 'campaign');
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
    $result = $this->exec('getDealStage', $params, 'dealStage');
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
   * Retrieve a list of DealStages that have been either created or updated
   * between two timestamps.
   * Timestamps must be specified in Y-m-d H:i:s format
   *
   * @param string $start_date
   *   Start of date range; format Y-m-d H:i:s (timezone info is not given).
   * @param string $end_date
   *   End of date range; format Y-m-d H:i:s (timezone info is not given).
   * @param $timestamp_type
   *   The field to filter for dates: 'create' or 'update'.
   *
   * @return array
   *   An array of DealStage structures.
   */
  public function getDealStagesDateRange($start_date, $end_date, $timestamp_type) {
    $params['startDate'] = $start_date;
    $params['endDate'] = $end_date;
    $params['timestamp'] = $timestamp_type;
    return $this->exec('getDealStagesDateRange', $params, 'dealStage');
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
   */
  public function getUnsubscribeCategories() {
    return $this->exec('getUnsubscribeCategories', []);
  }

}
