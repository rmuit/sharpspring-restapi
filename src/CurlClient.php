<?php

namespace SharpSpring\RestApi;

/**
 * A simple Sharpspring 'Client' object working with the curl library.
 *
 * It is responsible for several things: the way of making a connection (the
 * library used for this); the way in which credentials are set / retrieved; and
 * the request IDs for individual calls. It did not seem necessary to split
 * these out just yet.
 */
class CurlClient {

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
   * Constructor.
   *
   * @param array $options
   *   An array with two values: 'account_id' and 'secret_key'.
   */
  public function __construct($options) {
    // This class is light on initialization checks. If no proper authentication
    // details are provided... call() will just throw some exception.
    if (isset($options['account_id'])) {
      $this->setAccountId($options['account_id']);
    }
    if (isset($options['secret_key'])) {
      $this->setSecretKey($options['secret_key']);
    }
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
   * Makes a REST API call.
   *
   * @param string $method
   *   The REST API method name.
   * @param array $params
   *   The parameters to the REST API method.
   *
   * @return array
   *   A structure corresponding to the JSON response returned by the API.
   *
   * @throws \UnexpectedValueException
   *   If the REST API response has an unexpected format.
   */
  public function call($method, array $params) {
    // Details around (checking) the request ID are implemented in this class
    // because that will hopefully be easier to change/subclass than if this
    // code were in the connection object.
    $request_id = session_id();
    $data = json_encode([
      'method' => $method,
      'params' => $params,
      'id' => $request_id,
    ]);
    
    $url = $this->getUrl();
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
      // @todo we can probably fix this up; look at other code for examples.
      $http_response = curl_getinfo($curl, CURLINFO_HTTP_CODE);
      $body = curl_error($curl);
      curl_close($curl);
      // The server successfully processed the request, but is not returning any
      // content.
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

    return $response;
  }

  /**
   * Helper function to create a url of the REST API.
   *
   * @return string
   */
  private function getUrl() {
    $query = [
      'accountID' => $this->getAccountId(),
      'secretKey' => $this->getSecretKey(),
    ];
    return static::SHARPSPRING_BASE_URL . '?' . http_build_query($query);
  }

}
