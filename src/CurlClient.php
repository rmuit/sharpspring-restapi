<?php

namespace SharpSpring\RestApi;

use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

/**
 * A simple Sharpspring 'Client' object working with the curl library.
 *
 * It is responsible for several things: the way of (/library used for)
 * establishing a connection; the way in which credentials are set / retrieved;
 * (filling/checking) the request IDs for individual calls; and decoding
 * wrongly encoded responses from the endpoint. It did not seem necessary to
 * split these things out into different classes just yet.
 */
class CurlClient
{
    // REST endpoint without trailing slash.
    const SHARPSPRING_BASE_URL = 'https://api.sharpspring.com/pubapi/v1';

    /**
     * Configuration options.
     *
     * @var array
     */
    protected $options;

    /**
     * HTTP headers which are disallowed, or seen in constructor options.
     *
     * Header names, lower case, in the array keys. Disallowed headers are
     * defined here; these will cause an exception to be thrown if seen while
     * parsing headers. This array is added to, so duplicate headers are flagged
     * and after parsing, other code can later check which headers are used.
     *
     * @var array
     */
    protected $headersSeenOrDisallowed = [
        'content-length' => false,
        'transfer-encoding' => false,
    ];

    /**
     * Constructor.
     *
     * @param array $options
     *   Configuration options.
     *   Required:
     *   - account_id:   Account ID, as used in the REST endpoint URL.
     *   - secret_key:   Secret, as used in the REST endpoint URL.
     *   Optional:
     *   - headers:      HTTP headers to pass to Curl: an array of key-value
     *                   pairs in the form of ['User-Agent' => 'Me', ...].
     *   - curl_options: Options to pass to Curl: an array of values keyed by
     *                   CURLOPT_ constants. Some options are overridden / not
     *                   possible to set through here.
     */
    public function __construct($options)
    {
        foreach (['account_id', 'secret_key'] as $required_key) {
            if (empty($options[$required_key])) {
                $classname = get_class($this);
                throw new InvalidArgumentException("Required configuration parameter for $classname missing: $required_key.", 1);
            }
        }

        $options += ['headers' => []];
        if (!is_array($options['headers'])) {
            $classname = get_class($this);
            throw new InvalidArgumentException("Non-array 'headers' option passed to $classname constructor.", 2);
        }
        // Determine default headers with names not present in the 'headers'
        // option (case insensitive comparison).
        $default_headers = array_diff_ukey([
            'Content-Type' => 'application/json',
            'User-Agent' => 'PHP Curl/Sharpspring-RESTAPI',
            ], $options['headers'], 'strcasecmp');
        // Sanitize/set HTTPHEADER Curl option.
        $options['curl_options'][CURLOPT_HTTPHEADER] = $this->httpHeaders($options['headers'] + $default_headers);

        // We will not use 'headers' in our own code (because it's contained in
        // 'curl_options'), but won't clean it out.
        $this->options = $options;
    }

    /**
     * Constructs HTTP header lines.
     *
     * @param array $headers
     *   Header name-value pairs in the form of ['User-Agent' => 'Me', ...]
     *
     * @return array
     *   Header lines in the form of ['User-Agent: Me', ...]
     *
     * @throws \InvalidArgumentException
     *  For disallowed header fields/values.
     */
    protected function httpHeaders(array $headers)
    {
        // The spec for what is allowed (rfc7230) is not extremely detailed:
        // - field names MUST not have spaces before the colon; servers MUST
        //   reject such messages.
        // - field values SHOULD contain only ASCII. (What to do with non-ASCII
        //   is not specified.)
        // We have the option of:
        // - passing through without checking: no.
        // - filtering invalid characters only: considered potentially unsafe.
        // - encoding: possible, but it is unlikely that the server does
        //   anything useful with it and 'escape sequences' cannot be sent in a
        //   non-ambiguous way.
        // - throw exception when invalid characters are encountered.
        //   We'll do the last thing.
        $header_lines = [];

        foreach ($headers as $name => $value) {
            $lower_name = strtolower($name);
            if (isset($this->headersSeenOrDisallowed[$lower_name])) {
                throw new InvalidArgumentException("Duplicate or disallowed HTTP header name '$name'.", 2);
            }
            $this->headersSeenOrDisallowed[$lower_name] = true;

            // Check for non-ascii characters.
            if (strpos($name, ' ') !== false || strpos($name, ':') !== false || preg_match('/[^\x20-\x7f]/', $name)) {
                throw new InvalidArgumentException("Disallowed HTTP header name '$name'.", 2);
            }
            if (preg_match('/[^\x20-\x7f]/', $value)) {
                throw new InvalidArgumentException("Disallowed HTTP '$name' header value '$value'.", 2);
            }
            $header_lines[] = "$name: $value";
        }

        return $header_lines;
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
    public function call($method, array $params)
    {
        $request_id = $this->getRequestId();

        // Curl options that we really need for this particular call/code to
        // work:
        $forced_options = [
            CURLOPT_URL => $this->getUrl(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode([
                'method' => $method,
                'params' => $params,
                'id' => $request_id,
            ]),
        ];
        $ch = curl_init();
        curl_setopt_array($ch, $forced_options + $this->options['curl_options']);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_errno = curl_errno($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);
        if ($curl_errno) {
            // Body is likely empty but we'll still log it. Since our Connection
            // uses low error codes and <1000 is used by the Sharpspring API,
            // add 1600 to the thrown code, in case the caller cares about
            // distinguishing them.
            throw new RuntimeException("CURL returned code: $curl_errno / error: \"$curl_error\" / response body: \"$response\"", (int) $curl_errno + 1000);
        }
        // We'll start out strict, and throw on all unexpected return codes.
        if ($http_code != 200 && $http_code != 201) {
            throw new RuntimeException("CURL returned HTTP code $http_code / Response body: \"$response\"", $http_code + 1000);
        }

        $response = static::decodeResponse($response, $method, $params);
        // In circumstances that should never happen according to the API docs,
        // we throw exceptions without doing anything else. It will be up to the
        // caller to safely halt the execution and alert a human to investigate.
        if (!isset($response['id'])) {
            throw new UnexpectedValueException("Sharpspring REST API systemic error: no id found in JSON response from Sharpspring API endpoint. This should never happen.\nResponse (possibly double-encoded): " . json_encode($response), 1);
        }
        // Check if the request ID is the same in the response as in the
        // request. (Just following instructions from Sharpspring programming
        // examples. And what do we do if it is not equal? No idea. How could it
        // ever be not equal in the first place; what was this ever intended
        // for? No idea. Maybe an earlier implementation of the API was just
        // that bad, that it would get confused between requests...)
        if ($response['id'] != $request_id) {
            throw new UnexpectedValueException("Sharpspring REST API systemic error: unexpected id value found in JSON response from Sharpspring API endpoint. This should never happen.\nRequest ID: $request_id\nResponse (possibly double-encoded): " . json_encode($response), 2);
        }

        return $response;
    }

    /**
     * Helper function to create a url of the REST API.
     *
     * @return string
     */
    protected function getUrl()
    {
        $query = [
            'accountID' => $this->options['account_id'],
            'secretKey' => $this->options['secret_key'],
        ];
        return static::SHARPSPRING_BASE_URL . '?' . http_build_query($query);
    }

    /**
     * Helper function to get a request ID.
     *
     * Split off into a separate extensible method because maybe someone won't
     * like using the session ID.
     *
     * @return string
     */
    protected function getRequestId()
    {
        return session_id();
    }

    /**
     * Decodes a response from the REST API and fixes strange issues.
     *
     * This is a public static function (with a 'mixed' first argument), so
     * unrelated code can choose to call it to fix up known issues with field
     * values. These are discussed in encoding.md.
     *
     * The method accepts optional arguments for method/parameters. Though it
     * does not do anything with them at the moment, it might later - or a
     * child class might.
     *
     *
     * @param string|array $response
     *   The response to decode
     * @param string $method
     *   (optional) The REST API method called.
     * @param array $params
     *   (optional) The parameters to the REST API method.
     *
     * @return mixed
     *   The decoded response. At this moment this will never be null, though
     *   support for that might be added when needed.
     *
     * @throws \UnexpectedValueException
     *   For invalid JSON (or null value).
     */
    public static function decodeResponse($response, $method = '', array $params = null)
    {
        if (!is_array($response)) {
            $response = json_decode($response, true);
            if ($response === null) {
                throw new UnexpectedValueException("Response holds invalid JSON (or null).\nValue (possibly double-encoded): " . json_encode($response), 3);
            }
        }
        // See encoding.md: values entered from the UI are probably all HTML
        // encoded _except_ for custom fields. And values entered through a REST
        // endpoint are not. Even stranger: '<' characters are _double_
        // encoded. So we'll just assume that no values are _supposed_ to
        // contain HTML, and decode everything. We can either double-decode
        // everything, or decode + replace only &lt; characters. We'll do the
        // last thing, so if a caller still sees HTML at that point, they can /
        // should investigate what is wrong in their setup.
        array_walk_recursive($response, function (&$v) {
            $v = str_replace('&lt;', '<', htmlspecialchars_decode($v));
        });

        return $response;
    }
}
