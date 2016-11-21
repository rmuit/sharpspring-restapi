<?php

namespace SharpSpring\RestaApi;

use Exception;

/**
 * Class Lead
 * @package SharpSpring\RestaApi
 *
 * The Lead table consists of prospects who are possibly interested in your
 * product. As a lead progresses through your pipeline, their status changes
 * from unqualified to qualified. A lead can be converted into a contact,
 * opportunity, or account
 */
class Lead {
  /**
   * @var int
   */
  public $id;
  /**
   * @var int
   */
  public $accountID;
  /**
   * @var int
   */
  public $ownerID;
  /**
   * @var int
   */
  public $campaignID;
  /**
   * @var string
   */
  public $leadStatus;
  /**
   * @var string
   */
  public $leadScore;
  /**
   * @var string
   */
  public $leadScoreWeighted;
  /**
   * @var string
   */
  public $active;
  /**
   * @var string
   */
  public $firstName;
  /**
   * @var string
   */
  public $lastName;
  /**
   * @var string
   */
  public $emailAddress;
  /**
   * @var string
   */
  public $companyName;
  /**
   * @var string
   */
  public $title;
  /**
   * @var string
   */
  public $street;
  /**
   * @var string
   */
  public $city;
  /**
   * @var string
   */
  public $country;
  /**
   * @var string
   */
  public $state;
  /**
   * @var string
   */
  public $zipcode;
  /**
   * @var string
   */
  public $website;
  /**
   * @var string
   */
  public $phoneNumber;
  /**
   * @var string
   */
  public $officePhoneNumber;
  /**
   * @var string
   */
  public $phoneNumberExtension;
  /**
   * @var string
   */
  public $mobilePhoneNumber;
  /**
   * @var string
   */
  public $faxNumber;
  /**
   * @var string
   */
  public $description;
  /**
   * @var string
   */
  public $industry;
  /**
   * @var string
   */
  public $isUnsubscribed;
  /**
   * @var string
   */
  public $updateTimestamp;

  /**
   * Lead constructor.
   *
   * @param mixed $param
   *  - When $param is an array we create the Lead object based on this array.
   *    The keys of array have to be identical with the name of properties.
   *
   *  - When param is a string we suppose that it's just an email address.
   *
   * @throws \Exception
   *   To create a Lead object the emailAddress is required. We check both ways
   *   of creation a Lead object and if emailAddress is not provided we throw
   *   an Exception.
   */
  public function __construct($param) {
    if (is_array($param)) {
      if (empty($param['emailAddress'])) {
        throw new Exception('emailAddress is empty!');
      }
      foreach ($param as $key => $value) {
        if (property_exists(Lead::class, $key)) {
          $this->{$key} = $value;
        }
      }
    }
    elseif (filter_var($param, FILTER_VALIDATE_EMAIL)) {
      $this->emailAddress = $param;
    }
    else {
      throw new Exception('Not a valid email address is provided!');
    }
  }

}

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
   * SharpSpringRestClient constructor.
   *
   * @param string $account_id
   * @param string $secret_key
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
   * Execute a query against REST API.
   *
   * @param $data
   * @param array $query
   *
   * @return mixed
   *   In case of successful request a json will be returned.
   *
   * @throws \Exception
   *   If the request fails an error will be thrown.
   */
  public function exec($data, $query = []) {
    $url = $this->createUrl($query);
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
        return '';
      }
      $error = 'CURL Error (' . get_class($this) . ")\n
        url:$url\n
        body: $body";
      throw new Exception($error);
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
        throw new Exception($error);
      }
    }
    $result = json_decode($response, TRUE);

    return $result;
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
   * Create a Lead object.
   *
   * @param \SharpSpring\RestaApi\Lead $leads
   *
   * @return bool|mixed
   */
  public function createLead(Lead $leads) {
    $aleads = (array) $leads;
    $lead = [];
    foreach ($aleads as $key => $value) {
      if (empty($value)) {
        continue;
      }
      $lead[$key] = $value;
    }
    $params['objects'][] = $lead;
    $requestID = session_id();
    $data = [
      'method' => 'createLeads',
      'params' => $params,
      'id' => $requestID,
    ];
    $data = json_encode($data);
    $response = $this->exec($data);
    $result = FALSE;
    if (isset($response['result'])) {
      $result = reset($response['result']);
    }

    return $result;
  }

  /**
   * Specify a list of Lead objects to be updated in SharpSpring.
   *
   * Every lead object is a hash keyed by the system name of the lead field.
   * If you wish to push custom fields, first use the "getFields" API method in
   * order to retrieve a list of custom fields.In order to set a custom field
   * for a lead, use the field's "systemName" attribute as the key.
   *
   * @param array $leads
   */
  public function updateLead(Lead $leads) {
    $lead = [];
    foreach ($leads as $key => $value) {
      if (empty($value)) {
        continue;
      }
      $lead[$key] = $value;
    }
    $params['objects'][] = $lead;
    $requestID = session_id();
    $data = [
      'method' => 'updateLeads',
      'params' => $params,
      'id' => $requestID,
    ];
    $data = json_encode($data);
    $response = $this->exec($data);
    $result = FALSE;
    if (isset($response['result'])) {
      $result = reset($response['result']);
    }

    return $result;

  }
  /**
   * Retrieve a single Lead by its ID.
   *
   * @param int $id
   *
   * @return bool|mixed
   */
  public function getLead($id) {
    $params['id'] = $id;
    $requestID = session_id();

    $data = [
      'method' => 'getLead',
      'params' => $params,
      'id' => $requestID,
    ];

    $data = json_encode($data);
    $response = $this->exec($data);
    $result = FALSE;
    if (isset($response['result'])) {
      $result = reset($response['result']);
    }

    return $result;
  }

  /**
   * Get all leads within limit.
   * @param array $where
   *   $where can have keys:
   *   - id
   *   - emailAddress
   * @param null $limit
   *   Limit count of results.
   * @param null $offset
   *
   * @return bool|mixed
   */
  public function getLeads($where = [], $limit = NULL, $offset = NULL) {
    $params['where'] = $where;
    if (isset($limit)) {
      $params['limit'] = $limit;
    };
    if (isset($offset)) {
      $params['offset'] = $offset;
    }
    $requestID = session_id();
    $data = [
      'method' => 'getLeads',
      'params' => $params,
      'id' => $requestID,
    ];

    $data = json_encode($data);
    $response = $this->exec($data);
    $result = FALSE;
    if (isset($response['result'])) {
      $result = reset($response['result']);
    }

    return $result;
  }

  /**
   * Retrieve a list of Leads that have been either created or updated
   * between two timestamps.
   * Timestamps must be specified in Y-m-d H:i:s format
   *
   * @param $start_date
   *  The start of date range.
   * @param $end_date
   *  The end of date range.
   * @param $timestamp
   *  - 'create'
   *  - 'update'
   * @return bool|mixed
   */
  public function getLeadsDateRange($start_date, $end_date, $timestamp) {
    $params['startDate'] = $start_date;
    $params['endDate'] = $end_date;
    $params['timestamp'] = $timestamp;
    $requestID = session_id();

    $data = [
      'method' => 'getLeadsDateRange',
      'params' => $params,
      'id' => $requestID,
    ];

    $data = json_encode($data);
    $response = $this->exec($data);
    $result = FALSE;
    if (isset($response['result'])) {
      $result = reset($response['result']);
    }

    return $result;
  }

  /**
   * Specify a list of Opportunity IDs to be deleted in SharpSpring.
   *
   * @param array $ids
   */
  public function deleteLeads(array $ids) {
    foreach ($ids as $id) {
      $params['objects'][] = ['id' => $id];
    }
    $requestID = session_id();
    $data = [
      'method' => 'deleteLeads',
      'params' => $params,
      'id' => $requestID,
    ];

    $data = json_encode($data);
    $response = $this->exec($data);
    $result = FALSE;
    if (isset($response['result'])) {
      $result = reset($response['result']);
    }

    return $result;
  }

  /**
   * Delete a single lead identified by id.
   * Be aware that custom fields related with the Lead might not be deleted.
   *
   * @param int $id
   *
   * @return bool|mixed
   */
  public function deleteLead($id) {
    $params['objects'][] = ['id' => $id];
    $requestID = session_id();
    $data = [
      'method' => 'deleteLeads',
      'params' => $params,
      'id' => $requestID,
    ];

    $data = json_encode($data);
    $response = $this->exec($data);
    $result = FALSE;
    if (isset($response['result'])) {
      $result = reset($response['result']);
    }

    return $result;
  }

  /**
   * Delete a single lead identified by email address.
   * Be aware that custom fields related with the Lead might not be deleted.
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
        $result[] = $this->deleteLeads([['id' => $lead['id']],]);
      }
    }

    return $result;
  }

  /**
   * Get a list of fields.
   *
   * @param int $limit
   * @param int  $offset
   *
   * @return bool|mixed
   */
  public function getFields($limit = NULL, $offset = NULL) {
    $params = ['where' => []];
    if (isset($limit)) {
      $params['limit'] = $limit;
    };
    if (isset($offset)) {
      $params['offset'] = $offset;
    }
    $requestID = session_id();

    $data = [
      'method' => 'getFields',
      'params' => $params,
      'id' => $requestID,
    ];

    $data = json_encode($data);
    $response = $this->exec($data);
    $result = FALSE;
    if (isset($response['result'])) {
      $result = reset($response['result']);
    }

    return $result;
  }

  /**
   * Retrieve a single Account by its ID.
   *
   * @param int $id
   *
   * @return bool|mixed
   */
  public function getAccount($id) {
    $params['id'] = $id;
    $requestID = session_id();

    $data = [
      'method' => 'getAccount',
      'params' => $params,
      'id' => $requestID,
    ];

    $data = json_encode($data);
    $response = $this->exec($data);
    $result = FALSE;
    if (isset($response['result'])) {
      $result = reset($response['result']);
    }

    return $result;
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
   * @return bool|mixed
   */
  public function getAccounts(array $where = [], $limit = NULL, $offset = NULL) {
    $params['where'] = $where;
    if (isset($limit)) {
      $params['limit'] = $limit;
    };
    if (isset($offset)) {
      $params['offset'] = $offset;
    }
    $requestID = session_id();

    $data = [
      'method' => 'getAccounts',
      'params' => $params,
      'id' => $requestID,
    ];

    $data = json_encode($data);
    $response = $this->exec($data);
    $result = FALSE;
    if (isset($response['result'])) {
      $result = reset($response['result']);
    }

    return $result;
  }

  /**
   * Retrieve a list of Accounts that have been either created or updated
   * between two timestamps.
   * Timestamps must be specified in Y-m-d H:i:s format
   *
   * @param $start_date
   *
   * @param $end_date
   * @param $timestamp
   *  - 'create'
   *  - 'update'
   * @return bool|mixed
   */
  public function getAccountsDateRange($start_date, $end_date, $timestamp) {
    $params['startDate'] = $start_date;
    $params['endDate'] = $end_date;
    $params['timestamp'] = $timestamp;
    $requestID = session_id();

    $data = [
      'method' => 'getAccountsDateRange',
      'params' => $params,
      'id' => $requestID,
    ];

    $data = json_encode($data);
    $response = $this->exec($data);
    $result = FALSE;
    if (isset($response['result'])) {
      $result = reset($response['result']);
    }

    return $result;
  }

  /**
   * Retrieve a single Campaign by its ID.
   *
   * @param int $id
   *
   * @return bool|mixed
   */
  public function getCampaign($id) {
    $params['id'] = $id;
    $requestID = session_id();

    $data = [
      'method' => 'getCampaign',
      'params' => $params,
      'id' => $requestID,
    ];

    $data = json_encode($data);
    $response = $this->exec($data);
    $result = FALSE;
    if (isset($response['result'])) {
      $result = reset($response['result']);
    }

    return $result;
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
   * @return bool|mixed
   */
  public function getCampaigns(array $where = [], $limit = NULL, $offset = NULL) {
    $params['where'] = $where;
    if (isset($limit)) {
      $params['limit'] = $limit;
    };
    if (isset($offset)) {
      $params['offset'] = $offset;
    }
    $requestID = session_id();

    $data = [
      'method' => 'getCampaigns',
      'params' => $params,
      'id' => $requestID,
    ];

    $data = json_encode($data);
    $response = $this->exec($data);
    $result = FALSE;
    if (isset($response['result'])) {
      $result = reset($response['result']);
    }

    return $result;
  }

  /**
   * Retrieve a list of Campaigns that have been either created or updated
   * between two timestamps.
   * Timestamps must be specified in Y-m-d H:i:s format
   *
   * @param $start_date
   *
   * @param $end_date
   * @param $timestamp
   *  - 'create'
   *  - 'update'
   * @return bool|mixed
   */
  public function getCampaignsDateRange($start_date, $end_date, $timestamp) {
    $params['startDate'] = $start_date;
    $params['endDate'] = $end_date;
    $params['timestamp'] = $timestamp;
    $requestID = session_id();

    $data = [
      'method' => 'getCampaignsDateRange',
      'params' => $params,
      'id' => $requestID,
    ];

    $data = json_encode($data);
    $response = $this->exec($data);
    $result = FALSE;
    if (isset($response['result'])) {
      $result = reset($response['result']);
    }

    return $result;
  }

  /**
   * Get a list of all active companies managed by your company.
   *
   * @return bool|mixed
   */
  public function getClients() {
    $requestID = session_id();

    $data = [
      'params' => [],
      'method' => 'getClients',
      'id' => $requestID,
    ];

    $data = json_encode($data);
    $response = $this->exec($data);
    $result = FALSE;
    if (isset($response['result'])) {
      $result = $response['result'];
    }

    return $result;
  }

  /**
   * Retrieve a single DealStage by its ID.
   *
   * @param int $id
   *
   * @return bool|mixed
   */
  public function getDealStage($id) {
    $params['id'] = $id;
    $requestID = session_id();

    $data = [
      'method' => 'getDealStage',
      'params' => $params,
      'id' => $requestID,
    ];

    $data = json_encode($data);
    $response = $this->exec($data);
    $result = FALSE;
    if (isset($response['result'])) {
      $result = reset($response['result']);
    }

    return $result;
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
   * @return bool|mixed
   */
  public function getDealStages(array $where = [], $limit = NULL, $offset = NULL) {
    $params['where'] = $where;
    if (isset($limit)) {
      $params['limit'] = $limit;
    };
    if (isset($offset)) {
      $params['offset'] = $offset;
    }
    $requestID = session_id();

    $data = [
      'method' => 'getDealStages',
      'params' => $params,
      'id' => $requestID,
    ];

    $data = json_encode($data);
    $response = $this->exec($data);
    $result = FALSE;
    if (isset($response['result'])) {
      $result = reset($response['result']);
    }

    return $result;
  }

  /**
   * Retrieve a list of DealStages that have been either created or updated
   * between two timestamps.
   * Timestamps must be specified in Y-m-d H:i:s format
   *
   * @param $start_date
   *  The start of date range.
   * @param $end_date
   *  The end of date range.
   * @param $timestamp
   *  - 'create'
   *  - 'update'
   * @return bool|mixed
   */
  public function getDealStagesDateRange($start_date, $end_date, $timestamp) {
    $params['startDate'] = $start_date;
    $params['endDate'] = $end_date;
    $params['timestamp'] = $timestamp;
    $requestID = session_id();

    $data = [
      'method' => 'getDealStagesDateRange',
      'params' => $params,
      'id' => $requestID,
    ];

    $data = json_encode($data);
    $response = $this->exec($data);
    $result = FALSE;
    if (isset($response['result'])) {
      $result = reset($response['result']);
    }

    return $result;
  }

}
