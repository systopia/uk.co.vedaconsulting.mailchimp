<?php
/**
 * @file
 * Mailchimp API v3.0 service wrapper.
 *
 * ## Errors ##
 * 
 * According to:
 * http://developer.mailchimp.com/documentation/mailchimp/guides/get-started-with-mailchimp-api-3/#errors
 * Errors are always reported with a 4xx (fault probably ours) or 5xx (probably
 * theirs) http status code, *and* a data structure like this:
 * {
 *  "type":"http://kb.mailchimp.com/api/error-docs/405-method-not-allowed",
 *  "title":"Method Not Allowed",
 *  "status":405,
 *  "detail":"The requested method and resource are not compatible. See the
 *            Allow header for this resource's available methods.",
 *  "instance":""
 * }
 *
 * It also says that if you don't get JSON back, it's probably a timeout error.
 *
 * The request functions below should set up the response object and return it
 * in the case of a non-error response. Otherwise they should throw one of
 * CRM_Mailchimp_NetworkErrorException or CRM_Mailchimp_RequestErrorException
 *
 */
class CRM_Mailchimp_Api3 {
  
  /** string Mailchimp API key */
  protected $api_key;

  /** string URL to API end point. All API resources extend this. */
  protected $server;

  /** bool If set will use curl to talk to Mailchimp's API. Otherwise no
    *  networking. */
  protected $network_enabled=TRUE;

  /** Object that holds details used in the latest request.
   *  Public access just for testing purposes.
   */
  public $request;
  /** Object that holds the latest response.
   *  Props are http_code (e.g. 200 for success, found) and data.
   *
   *  This is returned from any of the get() post() etc. methods, but may be
   *  accessed directly as a property of this object, too.
   */
  public $response;
  /** For debugging. */
  protected static $request_id=0;
  /** supply a filename to start logging of all API requests and responses.. */
  public $log_to;
  /**
   * @param array $settings contains key 'api_key', possibly other settings.
   */
  public function __construct($settings) {

    // Check we have an api key.
    if (empty($settings['api_key'])) {
      throw new InvalidArgumentException("API Key required.");
    }
    $this->api_key = $settings['api_key'];

    // Set URL based on datacentre identifier at end of api key.                                       
    preg_match('/^.*-([^-]+)$/', $this->api_key, $matches);
    if (empty($matches[1])) {
      throw new InvalidArgumentException("Invalid API key - could not extract datacentre from given API key.");      
    }
    $datacenter = $matches[1];
    $this->server = "https://$datacenter.api.mailchimp.com/3.0";
  }

  /**
   * Perform a GET request.
   */
  public function get($url, $data=null) {
    return $this->request('GET', $url, $data);
  }

  /**
   * Perform a POST request.
   */
  public function post($url, Array $data) {
    return $this->request('POST', $url, $data);
  }

  /**
   * Perform a PUT request.
   */
  public function put($url, Array $data) {
    return $this->request('PUT', $url, $data);
  }

  /**
   * Perform a PATCH request.
   */
  public function patch($url, Array $data) {
    return $this->request('PATCH', $url, $data);
  }

  /**
   * Perform a DELETE request.
   */
  public function delete($url, $data=null) {
    return $this->request('DELETE', $url);
  }

  /**
   * Perform a /batches POST request and sit and wait for the result.
   *
   * @todo is it quicker to run small ops directly? <10 items?
   *
   */
  public function batchAndWait(Array $batch) {

    $batch_result = $this->makeBatchRequest($batch);

    // This can take a long time...
    set_time_limit(0);
    do {
      sleep(3);
      $result = $this->get("/batches/{$batch_result->data->id}");
    } while ($result->data->status != 'finished');

    // Now complete.
    return $result;
  }
  /**
   * Sends a batch request.
   *
   * @param array batch array of arrays which contain three values: the method,
   * the path (e.g. /lists) and the data describing a set of requests.
   */
  public function makeBatchRequest(Array $batch) {
    $ops = [];
    foreach ($batch as $request) {
      $op = ['method' => strtoupper($request[0]), 'path' => $request[1]];
      if (substr($op['path'], 0, 1) != '/') {
        throw new Exception("path $op[path] should begin with /");
      }
      if (!empty($request[2])) {
        if ($op['method'] == 'GET') {
          $op['params'] = $request[2];
        }
        else {
          $op['body'] = json_encode($request[2]);
        }
      }
      $ops []= $op;
    }
    $result = $this->post('/batches', ['operations' => $ops]);

    return $result;
  }
  /**
   * Setter for $network_enabled.
   */
  public function setNetworkEnabled($enable=TRUE) {
    $this->network_enabled = (bool) $enable;
  }
  /**
   * All request types handled here.
   *
   * Set up all parameters for the request.
   * Submit the request.
   * Return the response.
   *
   * Implemenations should call this first, then do their curl-ing (or not),
   * then return the response.
   *
   * @throw InvalidArgumentException if called with a url that does not begin
   * with /.
   * @throw CRM_Mailchimp_NetworkErrorException
   * @throw CRM_Mailchimp_RequestErrorException
   */
  protected function request($method, $url, $data=null) {
    if (substr($url, 0, 1) != '/') {
      throw new InvalidArgumentException("Invalid URL - must begin with root /");
    }
    $this->request = (object) [
      'id' => static::$request_id++,
      'created' => date('Y-m-d H:i:s'),
      'completed' => NULL,
      'method' => $method,
      'url' => $this->server . $url,
      'headers' => "Content-Type: Application/json;charset=UTF-8",
      'userpwd' => "dummy:$this->api_key",
      // Set ZLS for default data.
      'data' => '',
      // Mailchimp's certificate chain does not include trusted root for cert for
      // some popular OSes (e.g. Debian Jessie, April 2016) so disable SSL verify
      // peer.
      'verifypeer' => FALSE,
      // ...but we can check that the certificate has the domain we were
      // expecting.@see http://curl.haxx.se/libcurl/c/CURLOPT_SSL_VERIFYHOST.html
      'verifyhost' => 2,
    ];

    if ($data !== null) {
      if ($this->request->method == 'GET') {
        // For GET requests, data must be added as query string.
        // Append if there's already a query string.
        $this->request->url .= ((strpos($this->request->url, '?')===false) ? '?' : '&')
           .http_build_query($data);
      }
      else {
        // Other requests have it added as JSON
        $this->request->data = json_encode($data);
        $this->request->headers .= "\r\nContent-Length: " . strlen($this->request->data);
      }
    }

    // We set up a null response.
    $this->response = (object) [
      'http_code' => null,
      'data' => null,
      ];

    $this->logRequest();
    if ($this->network_enabled) {
      $this->sendRequest();
    }
    return $this->response;
  }
  /**
   * Send the request and prepare the response.
   */
  protected function sendRequest() {
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $this->request->method);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $this->request->data);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $this->request->headers);
    curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($curl, CURLOPT_USERPWD, $this->request->userpwd);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $this->request->verifypeer);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, $this->request->verifyhost);
    curl_setopt($curl, CURLOPT_URL, $this->request->url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    $result = curl_exec($curl);
    $info = curl_getinfo($curl);
    curl_close($curl);

    return $this->curlResultToResponse($info, $result);
  }

  /**
   * For debugging purposes.
   *
   * Does nothing without $log_to being set to a filename.
   */
  protected function logRequest() {
    if ($this->log_to) {
      $msg = date('Y-m-d H:i:s') . " Request #" . $this->request->id . "--> " . $this->request->method . " " . $this->request->url
        . "\n\t" . json_encode($this->request) . "\n";
      file_put_contents($this->log_to, $msg, FILE_APPEND);
    }
  }
  /**
   * For debugging purposes.
   *
   * Does nothing without static::$log_to being set to a filename.
   */
  protected function logResponse() {
    if ($this->log_to) {
      $took = round((time() - strtotime($this->request->created))/60, 2);
      $msg = date('Y-m-d H:i:s') . " Request #" . $this->request->id . "<-- Took {$took}s. Result: " . $this->response->http_code
        . "\n\t" . json_encode($this->response) . "\n";
      file_put_contents($this->log_to, $msg, FILE_APPEND);
    }
  }
  /**
   * Prepares the response object from the result of a cURL call.
   *
   * Public to allow testing.
   *
   * @return Array response object.
   * @throw CRM_Mailchimp_RequestErrorException
   * @throw CRM_Mailchimp_NetworkErrorException
   * @param array $info output of curl_getinfo().
   * @param string|null $result output of curl_exec().
   */
  public function curlResultToResponse($info, $result) {

    // Check response.
    if (empty($info['http_code'])) {
      throw new CRM_Mailchimp_NetworkErrorException($this);
    }

    // Check response object is set up.
    if (!isset($this->response)) {
      $this->response = (object) [
        'http_code' => null,
        'data' => null,
        ];
    }

    // Copy http_code into response object. (May yet be used by exceptions.)
    $this->response->http_code = $info['http_code'];

    // was JSON returned, as expected?
    $json_returned = isset($info['content_type'])
      && preg_match('@^application/(problem\+)?json\b@i', $info['content_type']);


    if (!$json_returned) {
      // According to Mailchimp docs it may return non-JSON in event of a
      // timeout.
      $this->logResponse();
      throw new CRM_Mailchimp_NetworkErrorException($this);
    }

    $this->response->data = $result ? json_decode($result) : null;
    $this->logResponse();

    // Check for errors and throw appropriate CRM_Mailchimp_ExceptionBase.
    switch (substr((string) $this->response->http_code, 0, 1)) {
    case '4': // 4xx errors
      throw new CRM_Mailchimp_RequestErrorException($this);
    case '5': // 5xx errors
      throw new CRM_Mailchimp_NetworkErrorException($this);
    }

    // All good return response as a convenience.
    return $this->response;
  }
}
