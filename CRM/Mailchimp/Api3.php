<?php
/**
 * @file
 * Mailchimp API v3.0 service wrapper.
 */
class CRM_Mailchimp_Api3 extends CRM_Mailchimp_ApiBase implements CRM_Mailchimp_ApiInterface {
  /**
   * Send request using cURL
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
}
