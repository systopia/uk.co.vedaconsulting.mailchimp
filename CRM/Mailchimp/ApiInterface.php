<?php

/**
 * @file
 * Interface for Mailchimp Api provider.
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

interface CRM_Mailchimp_ApiInterface {

  /**
   * Pass in the API keys etc.
   */
  public function __construct($settings);

  /**
   * Perform a GET request.
   */
  public function get($url);

  /**
   * Perform a POST request.
   */
  public function post($url, Array $data);

  /**
   * Perform a PUT request.
   */
  public function put($url, Array $data);

  /**
   * Perform a PATCH request.
   */
  public function patch($url, Array $data);

  /**
   * Perform a DELETE request.
   */
  public function delete($url);

}

