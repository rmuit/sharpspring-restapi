<?php

namespace SharpSpring\RestaApi;

use Exception;
use ReflectionObject;

/**
 * Class Lead
 * @package SharpSpring\RestaApi
 *
 * The Lead table consists of prospects who are possibly interested in your
 * product. As a lead progresses through your pipeline, their status changes
 * from unqualified to qualified. A lead can be converted into a contact,
 * opportunity, or account
 */
class Lead extends \stdClass {
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
      throw new Exception('No email address is provided!');
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
   * @var string
   */
  protected $url;


  public function __construct($account_id, $secret_key) {
    $this->setAccountId($account_id);
    $this->setSecretKey($secret_key);
  }

  public function getAccountId() {
    return $this->accountId;
  }

  public function setAccountId($account_id) {
    $this->accountId = $account_id;

    return $account_id;
  }

  public function getSecretKey() {
    return $this->secretKey;
  }

  public function setSecretKey($secret_key) {
    $this->secretKey = $secret_key;

    return $secret_key;
  }

  public function getUrl() {
    return $this->url;
  }

  public function setUrl($url) {
    $this->url = $url;

    return $url;
  }

  public function exec($data, $query = []) {
    $url = $this->createUrl($query);
    $this->setUrl($url);
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $this->url);
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
    // In case of connection error we repeat the request 5 times.
    if (empty($response)) {
      $seconds = 5;
      $i = 1;
      $count = 5;
      while (empty($response) && $count >= 0) {
        sleep($i * $seconds);
        $response = curl_exec($curl);
        $i++;
        $count--;
      }
    }

    if (!$response) {
      $http_response = curl_getinfo($curl, CURLINFO_HTTP_CODE);
      $body = curl_error($curl);
      curl_close($curl);

      //The server successfully processed the request, but is not returning any content.
      if ($http_response == 204) {
        return '';
      }
      $error = 'CURL Error (' . get_class($this) . ")\n
        url: $this->url\n
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
          $http_response, URL: $this->url
          \nError Message : $response";
        throw new Exception($error);
      }
    }
    $result = json_decode($response, TRUE);
    if (!empty($result['error'])) {
      if (!empty($response['error'])) {
        if (isset($response['error'][0])) {
          throw new Exception(sprintf('code: %s. Message: %s', $response['error'][0]['code'], $response['error'][0]['message']));
        }
        else {
          throw new Exception(sprintf('code: %s. Message: %s', $response['error']['code'], $response['error']['message']));
        }
      }
    }


    return $result;
  }


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
