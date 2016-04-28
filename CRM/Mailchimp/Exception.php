<?php
/**
 * @file
 * Exception base class for all Mailchimp API exceptions.
 */

abstract class CRM_Mailchimp_Exception extends Exception {

  public $request;

  public $response;

  public function __construct(CRM_Mailchimp_ApiInterface $api) {
    $this->request = clone($api->request);
    $this->response = clone($api->response);
    
    if ($this->response->data) {
      $message = "Mailchimp API said: " . $this->response->data->title;
    }
    else {
      $message = 'No data received, possibly a network timeout';
    }
    parent::__construct($message, $this->response->http_code);
  }

}
