<?php
/**
 * @file
 * Mailchimp API v3.0 service wrapper.
 */
class CRM_Mailchimp_Api3 extends CRM_Mailchimp_ApiBase implements CRM_Mailchimp_ApiInterface {
  /**
   * Send request using cURL
   */
  protected function request($method, $url, $data=null) {
    // Use base class to sort out all the options.
    parent::request($method, $url, $data);

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

    $this->response = (object) [
      'http_code' => $info['http_code'],
      'data' => $result ? json_decode($result) : null,
      ];

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
