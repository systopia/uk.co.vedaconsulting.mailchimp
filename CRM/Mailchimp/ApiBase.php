<?php
/**
 * @file
 * Mailchimp API v3.0 service wrapper base class.
 */
abstract class CRM_Mailchimp_ApiBase implements CRM_Mailchimp_ApiInterface {
  
  /** string Mailchimp API key */
  protected $api_key;

  /** string URL to API end point. All API resources extend this. */
  protected $server;

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
  public function get($url) {
    return $this->request('GET', $url);
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
    return $this->request('PUT', $url);
  }

  /**
   * Perform a PATCH request.
   */
  public function patch($url, Array $data) {
    return $this->request('PATCH', $url);
  }

  /**
   * Perform a DELETE request.
   */
  public function delete($url) {
    return $this->request('DELETE', $url);
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
      'method' => $method,
      'url' => $this->server . $url,
      'headers' => "Content-Type: Application/json;charset=UTF-8",
      'userpwd' => "dummy:$this->api_key",
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
      $this->request->data = json_encode($data);
      $this->request->headers .= "\r\nContent-Length: " . strlen($this->request->data);
    }

    // We set up a null response.
    $this->response = (object) [
      'http_code' => null,
      'data' => null,
      ];

    return $this->sendRequest();
  }
  /**
   * Send the request and prepare the response.
   *
   * @throw InvalidArgumentException if called with a url that does not begin
   * with /.
   * @throw CRM_Mailchimp_NetworkErrorException
   * @throw CRM_Mailchimp_RequestErrorException
   */
  abstract protected function sendRequest();

  /**
   * Prepares the response object from the result of a cURL call.
   *
   * @return Array response object.
   * @throw CRM_Mailchimp_RequestErrorException
   * @throw CRM_Mailchimp_NetworkErrorException
   * @param array $info output of curl_getinfo().
   * @param string|null $result output of curl_exec().
   */
  protected function curlResultToResponse($info, $result) {

    // Check response.
    if (empty($info['http_code'])) {
      throw new CRM_Mailchimp_NetworkErrorException($this);
    }

    // Copy http_code into response object. (May yet be used by exceptions.)
    $this->response->http_code = $info['http_code'];

    // was JSON returned, as expected?
    $json_returned = isset($info['content_type'])
      && preg_match('@^application/(problem\+)?json\b@i', $info['content_type']);

    if (!$json_returned) {
      // According to Mailchimp docs it may return non-JSON in event of a
      // timeout.
      throw new CRM_Mailchimp_NetworkErrorException($this);
    }

    $this->response->data = $result ? json_decode($result) : null;

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
