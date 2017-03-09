<?php

namespace SharpSpring\RestApi;

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
   * 'message' (string), 'code' (numeric) and 'data' values.
   *
   * The 'data' could be anything / its exact structure is not explored yet. It
   * is always an array, except for codes < 100; see handleLeads() for those.
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
