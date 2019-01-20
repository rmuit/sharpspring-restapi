<?php

namespace SharpSpring\RestApi;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * Sharpspring REST API Connection object designed to make coders' lives easier.
 *
 * This class contains methods corresponding to many API methods, to ease
 * working with the API. As some details of the API calls (parameters and
 * response format) are not documented extensively, the methods in this class
 * take care of abstracting away some tediousness and provide documentation with
 * each method. All methods wrap around a generic call() method; it is allowed
 * but hopefully not necessary to use call(), because wrapper functions exist.
 *
 * For more information on the values contained within the 'table row(s)' that
 * calls return as arrays, see Sharpspring's API ("Schema") documentation.
 *
 * This class performs quite strict validation on the JSON values returned from
 * API calls, especially since the API response can contain duplicate info. If
 * the response has an unexpected format, an exception is thrown. This has the
 * advantage of calling code being able to trust returned values, making its
 * 'flow' easier. The flip side is that it needs to implement good exception
 * handling; if Sharpspring decides to make changes to undocumented parts of the
 * API response, this class will likely start throwing exceptions and will need
 * to be changed.
 *
 * Create/update methods (for leads only, so far) make use of our ValueObject
 * structures (and unfortunately rely on schema information contained in them,
 * to fix bogus API return values). But callers are not forced to use these
 * ValueObjects: the calls also accept arrays as input.
 *
 * Create/update/delete methods which handle multiple leads will throw a
 * SharpSpringRestApiException if at least one of the objects returns failure.
 * The caller is then responsible for checking which objects succeeded/failed,
 * from the exception's getData() return value.
 *
 * This class does not handle the connection/authentication details of making
 * the actual REST call; this responsibility is delegated to a 'client' object
 * that must be injected in the constructor, so that details around networking /
 * credentials can be handled completely separately from this class' logic.
 */
class Connection
{
    /**
     * A REST API client.
     */
    protected $client;

    /**
     * PSR-3 compatible logger.
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

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
     * The response from the last API call.
     *
     * @var array
     */
    protected $lastCallResponse;

    /**
     * Constructor.
     *
     * @param object $client
     *   The client object responsible for making the actual API calls. (Not
     *   typehinted because there is no formal interface; it must have a
     *   call() method with the same signature as CurlClient::call().)
     * @param \Psr\Log\LoggerInterface $logger
     *   (optional) A logger. It's doubtful that anything will ever be logged at
     *   the moment; as a rule, exceptions are thrown for errors.
     */
    public function __construct($client, LoggerInterface $logger = null)
    {
        $this->client = $client;
        $this->logger = $logger;
    }

    /**
     * Log a message; ignore it if no logger was set.
     *
     * @param mixed $level
     *   A string representation of a level. (No idea why PSR-3 defines "mixed")
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

    /**
     * Sets custom property to field name mapping for custom Sharpspring fields.
     *
     * You need to set this if you use custom fields in Sharpspring, and
     * - either you use arrays as the input to create* / update* functions,
     *   having keys for those custom fields which do not correspond to the
     *   field system names (because these system names are long and differ per
     *   account)
     * - or you use ValueObject classes as input, with custom properties that do
     *   not correspond to the field system names - and those ValueObject
     *   classes do not have a property to custom field system name mapping for
     *   themselves.
     * Any input array/object will have the properties in this mapping converted
     * before they are used in any REST API calls.
     *
     * @param string $object_type
     *   The type of object to set mapping for: 'lead', 'opportunity', 'account'
     * @param array $mapping
     *   The mapping from our custom property names (array keys) to Sharpspring
     *   custom field system names (array values). The mapping is assumed to
     *   adhere to some basic rules: see ValueObject::$_customProperties.
     *
     * @see ValueObject::$_customProperties
     */
    public function setCustomProperties($object_type, array $mapping)
    {
        $this->customPropertiesByType[$object_type] = $mapping;
    }

    /**
     * Converts an external object/array to something accepted by the REST API.
     *
     * This is automatically done by create/update calls so calling this method
     * explicitly from outside the library should not be needed.
     *
     * (When passing a ValueObject,) the difference with the toArray() method on
     * the object itself is that this method additionally maps custom properties
     * that are set on this Connection instance.
     *
     * Also, this accepts arrays as input; the differences with objects are:
     * - 'internal' properties are not filtered out (because arrays don't have
     *   these);
     * - null values are not filtered out (because arrays can have properties
     *   removed, so if a value should not be sent then it's expected to not be
     *   in the array).
     * The other 'fix' (i.e. removing empty string values in nullable fields;
     * see comments in ValueObject) is done for arrays too. Because faulty
     * values which the API returns but would reject as input, should still be
     * fixed here; $con->updateLead($con->getLead(ID)) should not throw
     * exceptions; that would put an unreasonable burden on callers that want to
     * e.g. quickly fix values.
     *
     * No 'double fixing' is done, i.e. toArray(INPUT) returns the same as
     * toArray(toArray(INPUT)). This is a necessary feature because this class,
     * in its function documentation, implicitly assumes that it does not need
     * to care whether the input arrays'/objects' field names are already field
     * system names. (This is true in practice when property to system name
     * mappings adhere to some basic rules; see ValueObject::$_customProperties)
     *
     * @param string $object_type
     *   Type of object to set mapping for: 'lead', 'opportunity', 'account'.
     * @param \SharpSpring\RestApi\ValueObject|array $object
     *   An input object/array.
     * @param bool $reverse
     *   DEPRECATED. Rather than passing TRUE here, call convertSystemNames().
     *
     * @return array
     *   An array representing a Sharpspring 'object', that can be used in e.g.
     *   a create/update REST API call. If the input argument is an array, this
     *   return value will be the same except the custom properties/fields are
     *   converted to their field system names, according to the custom property
     *   mapping set on this Connection instance - plus some inconsistent
     *   'empty' values (which the REST API outputs but does not accept) may be
     *   fixed.
     */
    public function toArray($object_type, $object, $reverse = false)
    {
        // Regression since v1.0: don't accept objects without toArray() method.
        // (Noone cares.)
        if (!is_array($object)) {
            if (!is_object($object) || !method_exists($object, 'toArray')) {
                throw new InvalidArgumentException("Invalid input 'object'.", 99);
            }
            if ($reverse) {
                throw new InvalidArgumentException("The input 'object' must be an array.", 99);
            }
        }

        if ($reverse) {
            // After a very short lived time, unfortunately after v1.0 came out,
            // I decided '$reverse' would raise too many questions about
            // behavior, so that was deprecated but we still support it.
            return $this->convertSystemNames($object_type, $object);
        }

        $custom_properties = isset($this->customPropertiesByType[$object_type]) ? $this->customPropertiesByType[$object_type] : [];
        if (is_object($object) && method_exists($object, 'toArray')) {
            return $object->toArray($custom_properties);
        }

        // Assumption: the number of custom fields is considerably lower than
        // the number of fields that don't need converting; often 0. So rather
        // than loop through object values and build a second one with changed
        // values, we change the properties in place. Blindly assume:
        // - object-type to class mapping is simple and getSchemaInfo is static;
        // - $_nullableProperties does not get overridden in custom classes so
        //   the base class' getSchemaInfo() will return the right data;
        // - no duplicate properties are mapped to the same field system name.
        /** @var \SharpSpring\RestApi\ValueObject $class */
        $class = '\\SharpSpring\\RestApi\\' . ucfirst($object_type);
        if (is_callable([$class, 'getSchemaInfo'])) {
            $nullable_properties = $class::getSchemaInfo('nullable');
            if (!empty($nullable_properties) && is_array($nullable_properties)) {
                // Fix 'empty string on nullable property is not accepted'.
                foreach ($nullable_properties as $property_name) {
                    if (isset($object[$property_name]) && $object[$property_name] === '') {
                        unset($object[$property_name]);
                    }
                }
            }
        }
        foreach ($custom_properties as $field_system_name => $property_name) {
            if (isset($object[$property_name])) {
                $object[$field_system_name] = $object[$property_name];
                unset($object[$property_name]);
            }
        }

        return $object;
    }

    /**
     * Converts field system names into custom property names.
     *
     * This method is not used by other library functions; a caller can use it
     * on an array returned from the REST API, to convert to an array containing
     * known properties as per the mapping set in setCustomProperties(). This
     * way, the caller can call setCustomProperties() once and then does not
     * need to remember this mapping elsewhere.
     *
     * @param string $object_type
     *   The type of object mapping to use: 'lead', 'opportunity', 'account'.
     * @param array $api_object
     *   The array as (assumed to be) returned by the REST API.
     *
     * @return array
     */
    public function convertSystemNames($object_type, array $api_object)
    {
        if (isset($this->customPropertiesByType[$object_type])) {
            // Assumption: the number of custom fields is considerably lower
            // than the number of fields that don't need converting; often 0. So
            // rather than loop through object values and build a second one
            // with changed values, we change the properties in place. We
            // blindly assume no duplicate properties are mapped to the same
            // field system name.
            foreach ($this->customPropertiesByType[$object_type] as $field_system_name => $property_name) {
                if (isset($api_object[$field_system_name])) {
                    $api_object[$property_name] = $api_object[$field_system_name];
                    unset($api_object[$field_system_name]);
                }
            }
        }

        return $api_object;
    }

    /**
     * Checks format/contents of API result, which contains info about objects.
     *
     * This can be called for e.g. the result of a createLeads() call, by code
     * which is not happy enough with the fact that no exception was thrown and
     * wants to verify the structure of the response value before doing things
     * with it.
     *
     * The result format is an array with zero-based numeric keys, and a value
     * for each handled object that was initially passed into the API call,
     * being an array with at least a 'success' and 'error' value.
     *
     * @param mixed $result
     *   The result returned from the (successful) REST API call that took
     *   action on objects (e.g. createLeads, updateLeads, deleteLeads). This
     *   should really be an array (and anything else will throw an
     *   exception).
     * @param array $objects
     *   The objects that were provided as input for the REST API call. (At the
     *   moment they are only used to derive keys / count, but who knows...)
     * @param string $method
     *   The REST API method called. (Used to make the exception message
     *   clearer.)
     * @param bool $validate_individual_objects
     *   (optional) If false, only validate the structure of the result. By
     *   default (true), also validate the contents of the individual object
     *   results. This means if one object is found to be invalid, an exception
     *   will be thrown for that one and further objects will not be checked.
     * @param bool $error_encountered
     *   (optional) True if an 'error' result is being evaluated. This is used
     *   to make the exception message clearer. Should not be necessary for
     *   external code because the API call already validates the structure
     *   of 'error' results.
     *
     * @throws \SharpSpring\RestApi\SharpSpringRestApiException
     *   If the result contains an object-level error; the error for one of the
     *   objects only. Only possible for $validate_individual_objects = true.
     * @throws \UnexpectedValueException
     *   If the result has an unexpected format.
     */
    protected function validateResultForObjects($result, array $objects, $method, $validate_individual_objects = true, $error_encountered = false)
    {
        $return = null;
        $extra = $error_encountered ? ' while evaluating object-level error(s)' : '';
        if (!is_array($result)) {
            throw new UnexpectedValueException("Sharpspring REST API interpreter failure$extra: the 'result' part of the response is not an array.\nResponse result for $method call: " . json_encode($result), 101);
        }
        if (count($objects) != count($result)) {
            throw new UnexpectedValueException("Sharpspring REST API interpreter failure$extra: the number of objects provided to the call (" . count($objects) . ") is different from the number of objects returned in the 'result' part of the response (" . count($result) . ").\nResponse result for $method call: " . json_encode($result), 102);
        }
        $index = 0;
        foreach ($result as $i => $object_result) {
            // We could live without the following if needed, but we need to
            // clearly document how these indexes work then, to prevent bugs in
            // other code.
            if ($i !== $index) {
                throw new UnexpectedValueException("Sharpspring REST API interpreter failure$extra: result object was expected to have index $index; $i was found.\nResponse result for $method call: " . json_encode($result), 103);
            }
            if ($validate_individual_objects) {
                $this->validateObjectResult($object_result);
            }
            $index++;
        }
    }

    /**
     * Sets the response from the last API call.
     *
     * @param array $response
     *   The response array received from the last API call.
     * @param string
     *   The method called (for logging purposes).
     *
     * @see Connection::getLastCallResponse
     */
    protected function setLastCallResponse(array $response, $method = '')
    {
        $this->lastCallResponse = $response;
        $unknown = array_diff_key($response, ['id' => 1, 'result' => 1, 'error' => 1, 'hasMore' => 1]);
        if ($unknown) {
            // If this actually starts emitting notices, we should revisit the
            // code to see if we should do anything with the extra property.
            $this->log('notice', "Extra properties found in $method API response from method: " . json_encode($unknown));
        }
    }

    /**
     * Returns the full response from the last API call.
     *
     * Usually the response only contains id, result and error keys and call()
     * returns only the 'result' part. Some calls, however, return additional
     * properties which can only be retrieved by calling this method after
     * calling call().
     *
     * This method is an afterthought and its use is unclear, since it seems
     * that a caller only really needs 'result'. The only additional property
     * we know of is 'hasMore', which is only returned for some calls, and
     * whose exact use is unclear and also seems buggy (because for all these
     * cases, 'hasMore' is both next to and inside the 'result' structure).
     * Still, we did not want to make any properties totally unreachable to
     * calling code. If any properties besides 'hasMore' are ever found, this
     * fact will be logged.
     *
     * @return array
     *   Miscellaneous properties present in the last API call response.
     */
    protected function getLastCallResponse()
    {
        return $this->lastCallResponse;
    }

    /**
     * Call a REST API.method.
     *
     * @param string $method
     *   The REST API method name.
     * @param array $params
     *   The parameters to the REST API method.
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
     *     option only influences behavior if the result does not indicate
     *     'error' globally.)
     *   - throw_for_individual_object (bool): Validate the object(s) inside the
     *     result and throw an exception with an individual object's error code/
     *     message instead of the generic "call returned at least one object-
     *     level error" exception. (This option only influences behavior if the
     *     result indicates 'error' globally. It should only be set if the
     *     'objects' input parameter can contain only one object; otherwise,
     *     error data for other objects can get lost.)
     *
     * @return array
     *   Structure corresponding to the JSON response returned by the API, which
     *   may be modified according to checks / parameters documented earlier.
     *
     * @throws \SharpSpring\RestApi\SharpSpringRestApiException
     *   If the REST API response indicates an error encountered while executing
     *   the method.
     * @throws \UnexpectedValueException
     *   If the REST API response has an unexpected format. (Since documentation
     *   is terse, we do strict checks so that we're sure we do not ignore
     *   unknown data.)
     * @throws \RuntimeException
     *   If the request to the REST API fails.
     */
    public function call($method, array $params, array $response_checks = array())
    {
        $response = $this->client->call($method, $params);
        $this->setLastCallResponse($response);
        // Some methods (get(Removed)ListMembers, getUnsubscribeCategories,
        // getClients) return a 'hasMore' value inside $response AND inside
        // $response['result']. This seems to be a bug. Just unset/ignore the
        // one in 'result', so our 'single_result_key' response check works.
        if (isset($response['result']['hasMore'])) {
            if (!isset($response['hasMore'])) {
                // Now getLastCallResponse() won't return any 'hasMore'. Since
                // this whole 'hasMore' implementation seems incomplete anyway,
                // with unclear use, this will be the only time we'll live with
                // this and only log, instead of throwing an exception.
                $this->log('error', "'hasMore' indicator set in API response result from method $method, but not present on the first level of the response: " . json_encode($response['result']['hasMore']));
            }
            if ($response['hasMore'] !== $response['result']['hasMore']) {
                $this->log('error', "'hasMore' value on the first level of the API response from method $method (" . json_encode($response['hasMore']) . " is different from 'hasMore' value in the response result (" . json_encode($response['result']['hasMore']) . ').');
            }
            unset($response['result']['hasMore']);
        }

        if (empty($response['error']) && !isset($response['result'])) {
            throw new UnexpectedValueException("Sharpspring REST API systemic error: response contains neither error nor result.\nResponse: " . json_encode($response), 3);
        }

        // There are (we hope not more than) two kinds of error structures:
        // 1) An API-level error.
        if (isset($response['error']['message']) && isset($response['error']['code'])) {
            // We're going to trust the API to always return the three
            // documented subkeys (and nothing more). Also, we're not going to
            // check whether $response['result'] contained anything.
            throw new SharpSpringRestApiException($response['error']['message'], $response['error']['code'], $response['error']['data']);
        }

        // Regardless of error or success: if we expect the result to have only
        // one key for this specific method then validate this.
        if (!empty($response_checks['single_result_key'])) {
            if (!is_array($response['result']) || count($response['result']) != 1) {
                throw new UnexpectedValueException("Sharpspring REST API failure: response result is not a one-element array.'\nResponse: " . json_encode($response), 4);
            }
            if (!isset($response['result'][$response_checks['single_result_key']])) {
                throw new UnexpectedValueException("Sharpspring REST API failure: response result does not contain key $response_checks[single_result_key].\nResponse: " . json_encode($response), 5);
            }
        }
        $result = !empty($response_checks['single_result_key']) ? $response['result'][$response_checks['single_result_key']] : $response['result'];

        if (!empty($response['error'])) {
            // 2) The (hopefully only) other error structure: object level
            // errors for a call that took action on a list of objects in an
            // 'objects' parameter. In this case we have:
            // - a 0-based array of object result arrays (in the order
            //   corresponding to the 'objects' input parameter) in $result,
            //   each having at least 2 keys: 'success' and 'error'. (There may
            //   be more, e.g. 'result][creates' also has an 'id' key, only for
            //   the succeeded ones.)
            // - a 0-based array of object results in 'error', whose contents
            //   are exact copies of the 'error' subkeys in the result arrays.
            // Meaning: the second 'error' part is useless; since the indexes
            // are 0-based / renumbered and the structure contains no
            // identifier, we can't deduce which index corresponds to which
            // original object (unless _all_ objects happened to fail).

            // Validate the result (structure, and optionally the individual
            // objects inside) and compare with the number of input parameters.
            if (!isset($params['objects']) || !is_array($params['objects'])) {
                throw new UnexpectedValueException("Sharpspring REST API interpreter failure while evaluating error: no 'objects' (array) input parameter present for the $method method.\nResponse: " . json_encode($response), 6);
            }
            // If 'throw_for_individual_object' is set, this can throw a
            // SharpSpringRestApiException with an object's specific message /
            // code / data, if an 'error' for that object is set. So it provides
            // a 'better' error, at the cost of ignoring any generic message /
            // code, or anomalies in the returned structure, or even checking if
            // there is more than one object error.
            $this->validateResultForObjects($result, $params['objects'], $method, !empty($response_checks['throw_for_individual_object']), true);

            // Validate the result array against the 'error' array (which is
            // largely duplicate).
            $nr_objects_with_error = count(array_filter($result, function ($o) {
                return !empty($o['error']);
            }));
            if (!is_array($response['error']) || count($response['error']) != $nr_objects_with_error) {
                throw new UnexpectedValueException('Sharpspring REST API interpreter failure: number of errors reported (' . count($response['error']) . ") is different from the number of objects reported to have an error ($nr_objects_with_error).\nResponse: " . json_encode($response), 9);
            }
            $error_index = 0;
            foreach ($result as $i => $object_result) {
                if (!empty($object_result['error'])) {
                    if (!isset($response['error'][$error_index])) {
                        throw new UnexpectedValueException("Sharpspring REST API interpreter failure: error in result #$i was expected to correspond to error #$error_index but that error index does not exist.\nResponse: " . json_encode($response), 11);
                    }
                    if ($response['error'][$error_index] !== $object_result['error']) {
                        throw new UnexpectedValueException("Sharpspring REST API interpreter failure: error in result #$i was expected to be equal to error #$error_index.\nResponse: " . json_encode($response), 12);
                    }
                    $error_index++;
                }
            }

            // At this point we know we won't lose any info by returning only
            // $result (through throwing a custom exception), because everything
            // inside $response['error'] is also inside $result. We have not
            // validated the structure / content of the object data inside
            // $result - except if 'throw_for_individual_object' told us so.
            if (!empty($response_checks['throw_for_individual_object'])) {
                // We should never have ended up here; earlier validation should
                // have thrown an exception.
                throw new UnexpectedValueException("Sharpspring REST API interpreter failure: error was set but the result contains no object errors.\nResponse: " . json_encode($response), 13);
            }
            // We can only throw one single exception here, so interpreting
            // individual objects does not make sense and is not our
            // business anyway. We set code 0 and return the whole result as
            // data, so the caller can check which (properly numbered) objects
            // succeeded/failed.
            throw new SharpSpringRestApiException("$method call returned at least one object-level error", 0, $result, true);
        } elseif (!empty($response_checks['validate_result_with_objects'])) {
            // The response indicated no error. Then it would be very strange if
            // the contents of the result indicated anything else but success...
            // Check it anyway and throw an exception for unexpected results (so
            // the caller can trust a result that gets returned by this
            // function). If this ever does start throwing an exception,
            // we should change the code/docs to reflect current reality.
            try {
                $this->validateResultForObjects($result, $params['objects'], $method);
            } catch (\Exception $e) {
                // Just throw a SharpSpringRestApiException (api level, code 0)
                // always, so we can wrap both the result and the exception.
                throw new SharpSpringRestApiException("$method call indicated no error, but its result structure is unexpected or does contain an individual object error. The wrapped result data / previous exception hold more info.", 0, $result, false, $e);
            }
        }

        return $result;
    }

    /**
     * Checks the format of an API result provided for a single object.
     *
     * create/update/delete method calls which operate on multiple objects,
     * return an array of object results which may contain error or success.
     * This function can be called with a single object result as argument, and
     * will throw an exception if the result contains an error or cannot be
     * validated.
     *
     * @param array $object_result
     *   The result of the operation on a single object. (This should be an
     *   array containing at least 2 keys 'success' and 'error', which this
     *   function will validate. The function will accept non-arrays, since
     *   its primary function is to check whatever is thrown at it - and will
     *   throw an UnexpectedValueException for them.)
     *
     * @return true
     *   The value of the 'success' key. (We are not checking it but according
     *   to the API docs it should always be true.)
     *
     * @throws \SharpSpring\RestApi\SharpSpringRestApiException
     *   If the result indicates an object-level error.
     * @throws \UnexpectedValueException
     *   If the result has an unexpected format.
     */
    public function validateObjectResult($object_result)
    {
        // Valid results:
        // - [ 'success' => true, 'error' => null, (more things like 'id'...) ]
        // - [ 'success' => false, 'error' => anything ]
        if (!isset($object_result['success']) || !array_key_exists('error', $object_result)) {
            throw new UnexpectedValueException('Sharpspring REST API failure: result that should reflect status of a single object does not contain both error and success keys: ' . json_encode($object_result), 111);
        }
        if (empty($object_result['error']) && !isset($object_result['success'])) {
            throw new UnexpectedValueException('Sharpspring REST API failure: result that should reflect status of a single object contains neither error nor success: ' . json_encode($object_result), 112);
        }
        if (!empty($object_result['error'])) {
            // An object-level error was returned. We're going to trust the API
            // to always return the 3 documented subkeys (and nothing more).
            // Also, we won't check whether $object_result['success'] actually
            // is false. (If it didn't, we couldn't throw two exceptions at the
            // same time anyway...)
            throw new SharpSpringRestApiException($object_result['error']['message'], $object_result['error']['code'], $object_result['error']['data'], true);
        }

        return $object_result['success'];
    }

    /**
     * Executes a REST API call with 'where', 'limit' and 'offset' parameters.
     *
     * @param string $method
     *   The REST API method name.
     * @param string $single_result_key
     *   The API result is expected to hold a one-element array with this value
     *   as a key. The inner value is returned if the result format is as
     *   expected and an exception is thrown otherwise.
     * @param array $where
     *   Sub-parameters for the 'where' parameter of the method.
     * @param int $limit
     *   (optional) A limit to the number of objects returned.
     * @param int $offset
     *   (optional) The index in the full list of objects, of the first object
     *   to return. Zero-based.
     * @param array $extra_params
     *   Parameters for the REST API call in addition to where/limit/offset.
     *
     * @return array
     *   The substructure we expected in the JSON array.
     *
     * @see Connection::call() for throws.
     */
    protected function callLimited($method, $single_result_key, array $where, $limit = null, $offset = null, $extra_params = [])
    {
        $params = $extra_params;
        // API method definitions are not all consistent, which (is fine; most
        // are correctly documented; it just) means below code cannot treat them
        // all the same:
        // - one has no 'where' (getEmailJobs).
        // - one has 'where' defined as optional (getEmailListing. Note
        //   getActiveLists is wrongly documented; it says the 'where' argument
        //   is optional but it's actually required.)
        // - most have 'where' defined as required, even when the value is empty
        // We'll hardcode these here. This serves as documentation ot the same
        // time. Any method that passes extra parameters, is assumed *not* to
        // have a 'where'. (Like getLeadsDateRange, whose limit/offset
        // parameters are undocumented. We may reorganize this function's
        // arguments later, as more things are discovered.)
        if ($where || (!$extra_params && !in_array($method, ['getEmailListing', 'getEmailJobs'], true))) {
            $params['where'] = $where;
        }
        if (isset($limit)) {
            $params['limit'] = $limit;
        };
        if (isset($offset)) {
            $params['offset'] = $offset;
        }
        return $this->call($method, $params, ['single_result_key' => $single_result_key]);
    }

    /**
     * Abstracts some code shared by createLead(s) / updateLead(s) methods.
     *
     * Where validation is lacking in the REST API, this function will do
     * validation and throw a SharpSpringRestApiException for invalid leads -
     * but only after processing the valid leads. Like call(), it will throw a
     * 'wrapper' exception if $throw_for_individual_object is false; it will
     * combine any invalid leads and REST API errors into the exception, as if
     * all of them were object-level errors from the REST API - except the
     * error codes are low (below 100) and the 'data' entry might not be an
     * array. See this method's code for the meaning of error codes.
     *
     * @param array $leads
     *   Leads. (Both actual Lead objects and arrays are accepted.)
     * @param string $method
     *   The REST API method to call.
     * @param bool $throw_for_individual_object
     *   The 'throw_for_individual_object' key for $response_checks; see
     *   call(). This may only be set if $leads contains exactly one lead.
     *
     * @return mixed
     */
    protected function handleLeads(array $leads, $method, $throw_for_individual_object = false)
    {
        $params['objects'] = [];
        // We'll set apart invalid leads, and make sure they have numeric
        // indices; we'll continue sending an API call with only the valid ones.
        $invalid_leads = [];
        $leads = array_values($leads);
        foreach ($leads as $index => $lead) {
            if (!is_array($lead) && !(is_object($lead) && $lead instanceof Lead)) {
                $invalid_leads[$index] = ['success' => false, 'error' => [
                    'code' => 1, 'data' => $lead, 'message' => 'Invalid argument; not a lead.'
                ]];
                continue;
            }
            $lead = $this->toArray('lead', $lead);

            if (empty($lead['emailAddress'])) {
                if ($method === 'createLeads') {
                    $invalid_leads[$index] = ['success' => false, 'error' => [
                        'code' => 2, 'data' => [], 'message' => 'Missing e-mail address'
                    ]];
                    continue;
                } elseif (empty($lead['id'])) {
                    // The REST API would not catch this and return success without
                    // updating anything. See updateLead() for comments.
                    $invalid_leads[$index] = ['success' => false, 'error' => [
                        'code' => 2, 'data' => [], 'message' => "Missing e-mail address, and no ID. Updating won't work."
                    ]];
                    continue;
                }
            } elseif (!filter_var($lead['emailAddress'], FILTER_VALIDATE_EMAIL)) {
                // The REST API will happily insert bogus e-mails but we won't
                // let that happen.
                $invalid_leads[$index] = ['success' => false, 'error' => [
                    // We won't try to mimic the data structure from a REST API
                    // error here; for invalid parameters it seems to often
                    // return a description of the needed structure, but not the
                    // value that was deemed invalid - which we will do here.
                    // I don't know if it makes sense to do it like this.
                    'code' => 3, 'message' => 'Invalid e-mail address.', 'data' => [
                        'emailAddress' => $lead['emailAddress']
                    ]]];
                continue;
            }

            // Just a note: alphanumeric keys could be provided to the REST API
            // methods but don't make any difference in how the call is
            // processed or its returned results. It might have been convenient
            // to preserve input keys here (and reinsert them in the result
            // returned by the API) for the updateLeads call, but in the end
            // that's only really useful in case errors are encountered, and
            // it's a bit too much trouble.
            $params['objects'][] = $lead;
        }

        $result = [];
        if ($params['objects']) {
            $response_checks = [
                'single_result_key' => $method === 'createLeads' ? 'creates' : 'updates',
                'validate_result_with_objects' => true,
                'throw_for_individual_object' => $throw_for_individual_object,
            ];
            try {
                $result = $this->call($method, $params, $response_checks);
            } catch (SharpSpringRestApiException $e) {
                if ($e->getCode() || !$e->isObjectLevel() || !$invalid_leads) {
                    // For object-level: this concerns one single object; just
                    // re-throw it as-is. (This only happens when we have one
                    // lead, so it's pretty much impossible for $invalid_leads
                    // to be non-empty.) For API-level: it's probably impossible
                    // to combine this exception with any invalid leads in a
                    // sensible way, so just disregard $invalid_leads, just like
                    // in the block below.
                    throw $e;
                }
                // We have a wrapper around (the API result which contains) one
                // or more object-level errors; merge the invalid leads into it.
                $result = $e->getData();
            }
            // Note we don't catch other exceptions. We are making a judgment
            // call and disregarding any invalid leads (that would be in
            // $invalid_leads); the most likely situation here is an exception
            // from the actual REST client, which would be harder to debug if
            // we wrapped it into another exception.
        }
        if ($invalid_leads) {
            // We have invalid leads which we turned into faux object errors;
            // throw an exception for them.
            if ($result) {
                // But first, merge them together with actual object errors
                // returned from the API. Preserve original order, otherwise the
                // caller cannot see the link between input argument and error.
                $index = 0;
                $object_errors = [];
                while ($invalid_leads) {
                    if (isset($invalid_leads[$index])) {
                        $object_errors[] = $invalid_leads[$index];
                        unset($invalid_leads[$index]);
                    } else {
                        // $invalid_leads plus $result should add up to the
                        // number of input arguments, so there should be an
                        // object left in here.
                        $object_errors[] = array_shift($result);
                    }
                    $index++;
                }
                // Any items still remaining in $result are added at the end.
                $result = array_merge($object_errors, $result);
            } else {
                $result = $invalid_leads;
            }
            throw new SharpSpringRestApiException("$method call yielded at least one faux object-level error", 0, $result, true);
        }

        return $result;
    }

    /**
     * Creates a Lead object.
     *
     * The 'id' value will be ignored; a new object is always created (given no
     * other errors).
     *
     * If a lead with the same e-mail address already exists, the API returns an
     * error (code 301, message "Entry already exists"). (Which will cause an
     * object-level SharpSpringRestApiException to be thrown.)
     *
     * If an object-level error is encountered, the behavior is somewhat
     * different than when calling createLeads() with a single lead; the errors
     * in the SharpSpringRestApiException are more directly accessible.
     *
     * @param \SharpSpring\RestApi\Lead|array $lead
     *   A lead. (Both actual Lead objects and arrays are accepted.)
     *
     * @return array
     *    [ 'success': true, 'error': null, 'id': <ID OF THE CREATED LEAD> ]
     *
     * @throws \SharpSpring\RestApi\SharpSpringRestApiException
     *   If the REST API indicated that the lead failed to be created.
     *   isObjectLevel() tells whether it's an API-level or object-level error;
     *   for object-level errors, getCode() and getMessage() return the values
     *   from the API response (unlike with getLeads() which returns a wrapper).
     * @throws \UnexpectedValueException
     *   If the REST API response has an unexpected format. (Since documentation
     *   is terse, this library does strict checks so that we're sure we do not
     *   ignore unknown data or return inconsistent structures.)
     * @throws \RuntimeException
     *   If the request to the REST API fails.
     */
    public function createLead($lead)
    {
        $result = $this->handleLeads([$lead], 'createLeads', true);
        // If execution returns here, we know $result is a single-element array.
        // Return the single object result inside.
        return reset($result);
    }

    /**
     * Creates one or more Lead objects in a single REST API call.
     *
     * Does nothing and returns empty array if an empty array is provided.
     *
     * About behavior: if a lead with the same e-mail address already exists, an
     * object-level error (code 301, message "Entry already exists") will be
     * returned. (See below about how object-level errors are wrapped inside a
     * SharpSpringRestApiException with code 0.)
     *
     * 'id' values in a lead object are ignored; a new object is always created
     * (given no other errors).
     *
     * @param array $leads
     *   Leads. (Both actual Lead objects and arrays are accepted).
     *
     * @return array
     *   The (somewhat shortened) API call result, which should be an array with
     *   as many values as there are leads in the input argument, each being an
     *   array structured like [ 'success': true, 'error': null, 'id': <NEW ID>]
     *
     * @throws \SharpSpring\RestApi\SharpSpringRestApiException
     *   If the REST API indicated that at least one lead failed to be created.
     *   isObjectLevel() tells whether it's an API-level or object-level error;
     *   for object level errors, getCode() is 0 and getData() returns an array
     *   with as many values as there were leads in the input argument,
     *   containing success or the actual error code / message / data. See
     *   SharpSpringRestApiException::getData() for more details.
     * @throws \UnexpectedValueException
     *   If the REST API response has an unexpected format. (Since documentation
     *   is terse, we do strict checks so that we're sure we do not ignore
     *   unknown data.)
     * @throws \RuntimeException
     *   If the request to the REST API fails.
     */
    public function createLeads(array $leads)
    {
        return $this->handleLeads($leads, 'createLeads');
    }

    /**
     * Updates a lead object.
     *
     * The lead object to be updated can be recognized by its id or emailAddress
     * property. In other words;
     * - If a provided Lead has an existing emailAddress and no id, the lead
     *   corresponding to the e-mail is updated.
     * - If a provided Lead has an existing id and no emailAddress, the lead
     *   corresponding to the id is updated.
     * - If a provided Lead has an existing id and an emailAddress that does not
     *   exist yet, the lead corresponding to the id is updated just like the
     *   previous case. (The e-mail address is changed and the old e-mail
     *   address won't exist in the database anymore.)
     *
     * BEHAVIOR WARNINGS: (tested on API v1.117, 20161205):
     *
     * - If a provided Lead has an existing id and an emailAddress that already
     *   exists in a different lead, then ONLY the updateTimeStamp will be
     *   updated and the API call will return success, but NONE of the other
     *   fields will be changed.
     * - If a provided Lead has a nonexistent id (regardless whether the
     *   emailAddress exists) nothing will be updated even though the API call
     *   will return success!
     * - If a provided Lead has no id and no emailAddress: same.
     * - If a provided Lead has no id and an emailAddress that does not yet
     *   exist: same.
     *
     * - If an update does not actually change anything, the REST API will
     *   return an object-level error 302 "No table rows affected".
     *
     * While cases 2 and 3 are obvious (and it's understandable though
     * unfortunate that 4 does not create a new object), case 1 is a real
     * issue. This means that unless you *know* that the e-mail address in your
     * updated lead does not exist yet elsewhere in the Sharpspring database,
     * you cannot trust your updates. (So if you know you are not changing the
     * e-mail address with an update: you're fine. Otherwise: you're not, unless
     * you are sure it does not exist yet in another lead.) In this case you
     * *must* doublecheck whether the update succeeded by doing a getLead() on
     * the id you are updating, and seeing if the e-mail is actually the value
     * you expect. If not, you should assume the update silently failed.
     *
     * If an object-level error is encountered, the behavior is somewhat
     * different than when calling createLeads() with a single lead; the errors
     * in the SharpSpringRestApiException are more directly accessible. See the
     * relevant docs at createLead() vs createLeads().
     *
     * @param \SharpSpring\RestApi\Lead|array $lead
     *   A lead. (Both actual Lead objects and arrays are accepted.)
     *
     * @return array
     *   A fixed value: [ 'success': true, 'error': null ]. (The value is not
     *   much use at the moment but is kept like this in case the REST API
     *   extends its functionality, like createLead where it returns extra
     *     info.)
     *
     * @throws \SharpSpring\RestApi\SharpSpringRestApiException
     * @throws \UnexpectedValueException
     * @throws \RuntimeException
     *
     * @see Connection::createLead()
     *
     * @todo implement some 'fix' option (in 2nd array-argument) to fix the 302?
     */
    public function updateLead($lead)
    {
        $result = $this->handleLeads([$lead], 'updateLeads', true);
        // If execution returns here, we know $result is a single-element array.
        // Return the single object result inside.
        return reset($result);
    }

    /**
     * Updates one or more Lead objects in a single REST API call.
     *
     * Does nothing and returns empty array if an empty array is provided.
     *
     * See updateLead() documentation for caveats. Note: object-level errors 302
     * are returned for each individual lead that is not changed.
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
     * @see Connection::createLeads()
     * @see Connection::updateLead()
     */
    public function updateLeads(array $leads)
    {
        return $this->handleLeads($leads, 'updateLeads');
    }

    /**
     * Deletes a single lead.
     *
     * If an object-level error is encountered, the behavior is somewhat
     * different than when calling deleteLeads() with a single lead; the errors
     * in the SharpSpringRestApiException are more directly accessible. See the
     * relevant docs at createLead() vs createLeads().
     *
     * @param int $id
     *   The ID of the lead.
     *
     * @return array
     *   A fixed value: [ 'success': true, 'error': null ]. (The value is not
     *   much use at the moment but is kept like this in case the REST API
     *   extends its functionality, like createLead which returns extra info.)
     *
     * @throws \SharpSpring\RestApi\SharpSpringRestApiException
     * @throws \UnexpectedValueException
     * @throws \RuntimeException
     *
     * @see Connection::createLead()
     */
    public function deleteLead($id)
    {
        $params['objects'] = [];
        $params['objects'][] = ['id' => $id];
        $result = $this->call('deleteLeads', $params, [
            'single_result_key' => 'deletes',
            'validate_result_with_objects' => true,
            'throw_for_individual_object' => true,
        ]);
        // If execution returns here, we know $result is a single-element array.
        // Return the single object result inside.
        return reset($result);
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
     * @see Connection::createLeads()
     */
    public function deleteLeads(array $ids)
    {
        if (!$ids) {
            return [];
        }
        // The 'objects' parameter is actually a list of objects, but it does
        // not support e.g. 'emailAddress' as key. So each individual object is
        // just a one-element array that must be the ID, keyed by "id".
        $params['objects'] = [];
        foreach ($ids as $id) {
            $params['objects'][] = ['id' => $id];
        }
        return $this->call('deleteLeads', $params, [
            'single_result_key' => 'deletes',
            'validate_result_with_objects' => true,
        ]);
    }

    /**
     * Retrieves a single Lead by its ID.
     *
     * Some standard string fields returned from the API (e.g. title, street)
     * contain null values by default (unlike the custom fields which contain an
     * empty string by default - this goes for string as well as bit fields).
     * It's recommended that this null value is treated the same as an empty
     * string** because these fields are not nullable. Once they contain a value
     * they can only be emptied out by updating them to an empty string; trying
     * to update them to null will return an object-level error 205 "Invalid
     * parameters".)
     *
     * ** (Detail: the REST API itself seems to think these values are different
     *    internally, because updating a null value to '' won't return an
     *    object-level error 302 "No table rows affected".)
     *
     * @param int $id
     *   The lead's ID.
     * @param array $options
     *   (optional) One option key is recognized so far: 'fix_empty_leads'. If
     *   set to a 'true' value, then return an empty array if the lead returned
     *   from the REST API contains no 'id' value. Reason: (as of API v1.117,
     *   2017-01-27) queries for a nonexistent lead *may* return an array with
     *   values leadStatus = open, and all custom fields with an empty value; no
     *   other values. This class chooses to not alter return values by default
     *   (because who knows what hidden problems that could cause), in the hope
     *   that the REST API will be fixed. This means that until then, you have
     *   a choice between passing [ 'fix_empty_leads' => true ] into this method
     *   and assuming that a non-empty return value does not actually mean that
     *   a lead exists...
     *
     * @return array
     *   A lead table row (in array format as returned from the REST API; not as
     *   a Lead object). Empty array if not found.
     *
     * @todo here and in getLeads, do a 'fix' property to convert nulls to empty
     *   strings for non-nullable values?
     */
    public function getLead($id, $options = [])
    {
        $params['id'] = $id;
        $leads = $this->call('getLead', $params, ['single_result_key' => 'lead']);
        // For some reason getLead returns an array of exactly one leads. Not
        // sure why that is useful. We'll just return the lead - but then we
        // need to first validate that we have exactly one.
        if (count($leads) > 1) {
            throw new UnexpectedValueException("Sharpspring REST API failure: response result 'lead' value contains more than one object.'\nResponse: " . json_encode($leads), 16);
        }
        if ($leads) {
            $lead = reset($leads);
            if (!empty($options['fix_empty_leads']) && !isset($lead['id'])) {
                $lead = [];
            }
        } else {
            // If not found *and* the 'custom fields' bug is not encountered, an
            // empty array is returned. In this case we won't 'unwrap' it (to
            // return null or false), but just return an empty array.
            $lead = [];
        }
        return $lead;
    }

    /**
     * Retrieves a list of Lead objects.
     *
     * @param array $where
     *   A key-value array containing ONE item only, with key being either 'id'
     *  or 'emailAddress', because that is all the REST API supports. The return
     *  value will be one lead only, and will also return the corresponding lead
     *  if it is inactive. If this parameter is not provided, only active leads
     *  are returned.
     * @param int $limit
     *   (optional) A limit to the number of objects returned. A higher number
     *   than 500 does not have effect; the number of objects returned will be
     *   500 maximum.
     * @param int $offset
     *   (optional) The index in the full list of objects, of the first object
     *   to return. Zero-based. (To reiterate: this number is 'object based',
     *   not 'batch/page based'.)
     * @param array $options
     *   (optional) One option key is recognized so far: 'fix_empty_leads'. See
     *   getLead().
     *
     * @return array
     *   An array of lead table rows (in array format as returned from the REST
     *   API; not as Lead objects). See getLead() for comment on 'null string
     *   values'.
     */
    public function getLeads($where = [], $limit = null, $offset = null, $options = [])
    {
        $leads = $this->callLimited('getLeads', 'lead', $where, $limit, $offset);
        if (!empty($options['fix_empty_leads'])) {
            foreach ($leads as $key => $lead) {
                if (!isset($lead['id'])) {
                    unset($leads[$key]);
                }
            }
            // Rehash keys just to be sure the caller won't get into trouble if
            // it expects consecutive zero-based keys.
            $leads = array_values($leads);
        }
        return $leads;
    }

    /**
     * Retrieves Leads that were created or updated in a given time frame.
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
     *   (For completeness: leads which have been created once and never updated
     *   afterwards, are also returned in the 'update' list. This is obviously
     *   the logical thing to do; it's just being noted here because at least
     *   one other competitor's REST API does _not_ do this...)
     * @param int $limit
     *   (optional) A limit to the number of objects returned. The default is
     *   set to 500, but (unlike with getLeads()) it can be raised beyond 500.
     * @param int $offset
     *   (optional) The index in the full list of objects, of the first object
     *   to return. Zero-based.
     *
     * @return array
     *   An array of Lead table rows.
     *
     * @see Lead::$updateTimestamp
     */
    public function getLeadsDateRange($start_date, $end_date = '', $time_type = 'update', $limit = null, $offset = null)
    {
        $params['startDate'] = $start_date;
        $params['endDate'] = $end_date ? $end_date : date('Y-m-d H:i:s');
        $params['timestamp'] = $time_type;
        return $this->callLimited('getLeadsDateRange', 'lead', [], $limit, $offset, $params);
    }

    /**
     * Retrieves a list of fields.
     *
     * @param int $limit
     *   (optional) Limit.
     * @param int $offset
     *   (optional) Offset.
     *
     * @return array
     *   An array of Field table rows.
     */
    public function getFields($limit = null, $offset = null)
    {
        return $this->callLimited('getFields', 'field', [], $limit, $offset);
    }

    /**
     * Retrieves a single Account by its ID.
     *
     * @param int $id
     *
     * @return array
     *   An Account table row.
     */
    public function getAccount($id)
    {
        $params['id'] = $id;
        $result = $this->call('getAccount', $params, ['single_result_key' => 'account']);
        // For some reason getAccount returns an array of exactly one accounts.
        if (count($result) > 1) {
            throw new UnexpectedValueException("Sharpspring REST API failure: response result 'account' value contains more than one object.'\nResponse: " . json_encode($result), 16);
        }
        return reset($result);
    }

    /**
     * Retrieves a list of Account objects.
     *
     * @param array $where
     *   (optional) Conditions
     *   - id
     *   - ownerID
     * @param int $limit
     *   (optional) Limit.
     * @param int $offset
     *   (optional) Offset.
     *
     * @return mixed
     *   An array of Account table rows.
     */
    public function getAccounts(array $where = [], $limit = null, $offset = null)
    {
        return $this->callLimited('getAccounts', 'account', $where, $limit, $offset);
    }

    /**
     * Retrieves Accounts that were created or updated in a given time frame.
     *
     * We assume this is local time (for whatever definition of local time that
     * Sharpspring has) but have not tested! Note that around 2017-07-26,
     * getLeadsDateRange switched from UTC to local timezone (both the format it
     * accepts and the format it displays in output) without announcement.
     *
     * @param string $start_date
     *   Start of date range; format Y-m-d H:i:s.
     * @param string $end_date
     *   (optional) End of date range; format Y-m-d H:i:s. Defaults to 'now'.
     * @param $time_type
     *   (optional) The field to filter for dates: update (default) or create.
     *
     * @return array
     *   An array of Account table rows.
     *
     * @see Lead::$updateTimestamp
     */
    public function getAccountsDateRange($start_date, $end_date = '', $time_type = 'update')
    {
        $params['startDate'] = $start_date;
        $params['endDate'] = $end_date ? $end_date : date('Y-m-d H:i:s');
        $params['timestamp'] = $time_type;
        return $this->call('getAccountsDateRange', $params, ['single_result_key' => 'account']);
    }

    /**
     * Retrieves a single Campaign by its ID.
     *
     * @param int $id
     *   The campaign ID.
     * @return array
     *   A Campaign table row.
     */
    public function getCampaign($id)
    {
        $params['id'] = $id;
        $result = $this->call('getCampaign', $params, ['single_result_key' => 'campaign']);
        // For some reason getCampaign returns an array of exactly one accounts.
        if (count($result) > 1) {
            throw new UnexpectedValueException("Sharpspring REST API failure: response result 'campaign' value contains more than one object.'\nResponse: " . json_encode($result), 16);
        }
        return reset($result);
    }

    /**
     * Retrieves a list of Campaign objects.
     *
     * @param array $where
     *   (optional) Conditions
     *   - id
     *   - ownerID
     * @param int $limit
     *   (optional) Limit.
     * @param int $offset
     *   (optional) Offset.
     *
     * @return array
     *   An array of Campaign table rows.
     */
    public function getCampaigns(array $where = [], $limit = null, $offset = null)
    {
        return $this->callLimited('getCampaigns', 'campaign', $where, $limit, $offset);
    }

    /**
     * Retrieves Campaigns that were created or updated in a given time frame.
     *
     *
     * We assume this is local time (for whatever definition of local time that
     * Sharpspring has) but have not tested! Note that around 2017-07-26,
     * getLeadsDateRange switched from UTC to local timezone (both the format it
     * accepts and the format it displays in output) without announcement.
     *
     * @param string $start_date
     *   Start of date range; format Y-m-d H:i:s.
     * @param string $end_date
     *   (optional) End of date range; format Y-m-d H:i:s. Defaults to 'now'.
     * @param $time_type
     *   (optional) The field to filter for dates: update (default) or create.
     *
     * @return array
     *   An array of Campaign table rows.
     *
     * @see Lead::$updateTimestamp
     */
    public function getCampaignsDateRange($start_date, $end_date = '', $time_type = 'update')
    {
        $params['startDate'] = $start_date;
        $params['endDate'] = $end_date ? $end_date : date('Y-m-d H:i:s');
        $params['timestamp'] = $time_type;
        return $this->call('getCampaignsDateRange', $params, ['single_result_key' => 'campaign']);
    }

    /**
     * Retrieves a list of all active companies managed by your company.
     *
     * The API response contains a 'hasMore' property, which can be accessed
     * through getLastCallResponse().
     *
     * @return array
     *   An array of Client table rows (The Schema tab which documents the
     *   returned fields calls the table Client, though the Methods tab calls it
     *   Company Profile Managed By - which is also visible in the API response
     *   structure.)
     */
    public function getClients()
    {
        return $this->call('getClients', [], ['single_result_key' => 'getAllcompanyProfileManagedBys']);
    }

    /**
     * Retrieves a single Deal Stage by its ID.
     *
     * @param int $id
     *
     * @return array
     *   A DealStage table row.
     */
    public function getDealStage($id)
    {
        $params['id'] = $id;
        $result = $this->call('getDealStage', $params, ['single_result_key' => 'dealStage']);
        // For some reason getDealStage returns an array of exactly one deal
        // stages.
        if (count($result) > 1) {
            throw new UnexpectedValueException("Sharpspring REST API failure: response result 'dealStage' value contains more than one object.'\nResponse: " . json_encode($result), 16);
        }
        return reset($result);
    }

    /**
     * Retrieves a list of Deal Stage objects.
     *
     * @param array $where
     *   (optional) Conditions
     *   - id
     *   - ownerID
     * @param int $limit
     *   (optional) Limit.
     * @param int $offset
     *   (optional) Offset.
     *
     * @return array
     *   An array of DealStage table rows.
     */
    public function getDealStages(array $where = [], $limit = null, $offset = null)
    {
        // Result key documented as dealStages is actually dealStage
        return $this->callLimited('getDealStages', 'dealStage', $where, $limit, $offset);
    }

    /**
     * Retrieves DealStages that were created or updated in a given time frame.
     *
     * We assume this is local time (for whatever definition of local time that
     * Sharpspring has) but have not tested! Note that around 2017-07-26,
     * getLeadsDateRange switched from UTC to local timezone (both the format it
     * accepts and the format it displays in output) without announcement.
     *
     * @param string $start_date
     *   Start of date range; format Y-m-d H:i:s.
     * @param string $end_date
     *   (optional) End of date range; format Y-m-d H:i:s. Defaults to 'now'.
     * @param $time_type
     *   (optional) The field to filter for dates: update (default) or create.
     *
     * @return array
     *   An array of DealStage table rows.
     *
     * @see Lead::$updateTimestamp
     */
    public function getDealStagesDateRange($start_date, $end_date = '', $time_type = 'update')
    {
        $params['startDate'] = $start_date;
        $params['endDate'] = $end_date ? $end_date : date('Y-m-d H:i:s');
        $params['timestamp'] = $time_type;
        return $this->call('getDealStagesDateRange', $params, ['single_result_key' => 'dealStage']);
    }

    /**
     * Retrieves a list of EmailListing objects.
     *
     * @param int $id
     *   List ID. Unknown values will not return a validation error; they will
     *   just make the method return an empty list.
     * @param int $limit
     *   (optional) A limit to the number of objects returned.
     * @param int $offset
     *   (optional) The index in the full list of objects, of the first object
     *   to return. Zero-based.
     *
     * @return array
     *   An array of EmailListing table rows.
     */
    public function getEmailListing($id = null, $limit = null, $offset = null)
    {
        // 'where' is an optional parameter; we'll just always pass it because
        // that's how callLimited works (because this is actually the only API
        // call where 'where' is optional).
        $where = [];
        if (isset($id)) {
            $where['id'] = $id;
        }
        // Result key documented as 'fields' is actually getAllemailListings.
        return $this->callLimited('getEmailListing', 'getAllemailListings', $where, $limit, $offset);
    }

    /**
     * Retrieves a list of emailListing objects.
     *
     * @param int $limit
     *   (optional) A limit to the number of objects returned.
     * @param int $offset
     *   (optional) The index in the full list of objects, of the first object
     *   to return. Zero-based.
     *
     * @return array
     *   An array of EmailJob table rows.
     */
    public function getEmailJobs($limit = null, $offset = null)
    {
        // Note double s in result key.
        return $this->callLimited('getEmailJobs', 'getAllgetEmailJobss', [], $limit, $offset);
    }

    /**
     * Retrieves a list of active Lists.
     *
     * @param int $id
     *   (optional) List ID.
     * @param int $limit
     *   (optional) Limit.
     * @param int $offset
     *   (optional) Offset.
     *
     * @return array
     *   An array of List table rows.
     */
    public function getActiveLists($id = null, $limit = null, $offset = null)
    {
        // 'where' is a required parameter but it may be empty. (The API v1.1.17
        // documentation says that the 'where' parameter is "optional", but it's
        // wrong; it is actually required. That behavior is consistent with most
        // other API method calls where the documentation says "required", even
        // when it may be empty.
        $where = [];
        if (isset($id)) {
            $where['id'] = $id;
        }
        return $this->callLimited('getActiveLists', 'activeList', $where, $limit, $offset);
    }

    /**
     * Retrieves the active members for a list.
     *
     * @param int $id
     *   List ID. Unknown values will not return a validation error; they will
     *   just make the method return an empty list.
     * @param int $limit
     *   (optional) A limit to the number of objects returned.
     * @param int $offset
     *   (optional) The index in the full list of objects, of the first object
     *   to return. Zero-based.
     *
     * @return array
     *   An array of ActiveListMember table rows. (Not ListMember table rows;
     *   those have a different structure. The Schema tab which documents the
     *   returned fields calls the table ActiveListMember, though the Methods
     *   tab calls it listMembers in the overview table and list<List> in the
     *   return data.)
     */
    public function getListMembers($id, $limit = null, $offset = null)
    {
        $where = ['id' => $id];
        // Result key documented as 'fields' is actually getWherelistMemberGets.
        return $this->callLimited('getListMembers', 'getWherelistMemberGets', $where, $limit, $offset);
    }

    /**
     * Retrieves the members that are removed from a list.
     *
     * The API response contains a 'hasMore' property, which can be accessed
     * through getLastCallResponse().
     *
     * @param int $id
     *   List ID. Unknown values will not return a validation error; they will
     *   just make the method return an empty list.
     * @param string $flag
     *   (optional) "removed", "unsubscribed" or "hardbounced". The REST API
     *   defaults to returning "removed" members. Unknown values will not return
     *   validation errors; they will just make the method return an empty list.
     * @param int $limit
     *   (optional) A limit to the number of objects returned.
     * @param int $offset
     *   (optional) The index in the full list of objects, of the first object
     *   to return. Zero-based.
     *
     * @return array
     *   An array of RemovedListMember table rows. (The Schema tab which
     *   documents the returned fields calls the table RemovedListMember, though
     *   the Methods tab calls it listLeadMember - which is also visible in the
     *   API response structure.) The email field is actually 'emailaddress',
     *   not 'emailAddress' as documented.
     */
    public function getRemovedListMembers($id, $flag = null, $limit = null, $offset = null)
    {
        $where = ['id' => $id];
        if (isset($flag)) {
            $where['flag'] = $flag;
        }
        $result = $this->callLimited('getRemovedListMembers', 'getWherelistLeadMembers', $where, $limit, $offset);
        return $result;
    }

    /**
     * Retrieves UnsubscribeCategory objects. (See the API docs.)
     *
     * The API response contains a 'hasMore' property, which can be accessed
     * through getLastCallResponse().
     *
     * @return array
     *   An array of UnsubscribeCategory table rows.
     */
    public function getUnsubscribeCategories()
    {
        // Result key documented as getAllUnsubscribeCategories is actually
        // getAllunsubscribeCategorys.
        return $this->call('getUnsubscribeCategories', [], ['single_result_key' => 'getAllunsubscribeCategorys']);
    }

    public function getLeadTimeline($where = [])
    {
        return $this->callLimited('getLeadTimeline', 'leadTimeline', $where);
    }

    /**
     * Retrieves a single opportunity by its ID.
     *
     * @param int $id
     *   The opportunity ID.
     * @param array $options
     *   (optional) Reserved for future use
     *
     * @return array
     *   An opportunity table row (in array format as returned from the REST API; not as
     *   an Opportunity object). Empty array if not found.
     */
    public function getOpportunity($id, $options = [])
    {
        $params = [];
        $params['id'] = $id;
        $opportunities = $this->call('getOpportunity', $params, ['single_result_key' => 'opportunity']);
        /**
         * @see Connection::getLead()
         */
        if (count($opportunities) > 1) {
            throw new UnexpectedValueException("Sharpspring REST API failure: response result 'opportunity' value contains more than one object.'\nResponse: " . json_encode($opportunities), 16);
        }
        if ($opportunities) {
            $opportunity = reset($opportunities);
        } else {
            // If not found *and* the 'custom fields' bug is not encountered, an
            // empty array is returned. In this case we won't 'unwrap' it (to
            // return null or false), but just return an empty array.
            $opportunity = [];
        }
        return $opportunity;
    }

    /**
     * Retrieves a list of Opportunity objects.
     *
     * @param array $where
     *   A key-value array containing ONE item only, with key being one of 'id',
     *  'ownerID','dealStageID','accountID','campaignID. The return
     *  value will be one opportunity only if 'id' is specified.
     * @param int $limit
     *   (optional) A limit to the number of objects returned. A higher number
     *   than 500 does not have effect; the number of objects returned will be
     *   500 maximum. @todo confirm this is correct for opportunities
     * @param int $offset
     *   (optional) The index in the full list of objects, of the first object
     *   to return. Zero-based. (To reiterate: this number is 'object based',
     *   not 'batch/page based'.)
     * @param array $options
     *   (optional) Reserved for future use.
     *
     * @return array
     *   An array of opportunity table rows (in array format as returned from the REST
     *   API; not as Opportunity objects).
     * @see Connection::getLead() for comment on 'null string values'.
     */
    public function getOpportunities($where = [], $limit = null, $offset = null, $options = [])
    {
        $opportunities = $this->callLimited('getOpportunities', 'opportunity', $where, $limit, $offset);

        return $opportunities;
    }

    public function getOpportunityLeads($where = [])
    {
        return $this->callLimited('getOpportunityLeads', 'getWhereopportunityLeads', $where);
    }

    public function getOpportunityLeadsDateRange($start_date, $end_date = '', $time_type = 'update', $limit = null, $offset = null)
    {
        $params = [];
        $params['startDate'] = $start_date;
        $params['endDate'] = $end_date ? $end_date : date('Y-m-d H:i:s');
        $params['timestamp'] = $time_type;
        return $this->callLimited('getOpportunityLeadsDateRange', 'opportunityLead', [], $limit, $offset, $params);
    }
}
