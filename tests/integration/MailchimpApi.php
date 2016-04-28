<?php
require 'integration-test-bootstrap.php';

class MailchimpApiTest extends \PHPUnit_Framework_TestCase {
  /**
   * Test that we can connect to the API and retrieve certain data
   */
  public function testConnection() {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();

    // Check we can connect and get account details.
    $result = $api->get('/');
    $this->assertEquals(200, $result->http_code);
    $this->assertTrue(!empty($result->data->account_name), "Expected account_name to be returned.");
    $this->assertTrue(!empty($result->data->email), "Expected email belonging to the account to be returned.");
  }
  /**
   * Test that we can connect to the API and retrieve lists.
   */
  public function testLists() {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();

    // Check we can access lists, that there is at least one list.
    $result = $api->get('/lists');
    $this->assertEquals(200, $result->http_code);
    $this->assertTrue(isset($result->data->lists));
    $this->assertInternalType('array', $result->data->lists);
    $this->assertGreaterThanOrEqual(1, count($result->data->lists));


    // Load webhooks for first list found.
    $this->assertFalse(empty($result->data->lists[0]->id));
    $list_id = $result->data->lists[0]->id;
    $result = $api->get("/lists/$list_id/webhooks");
    $this->assertEquals(200, $result->http_code);
    $this->assertTrue(isset($result->data->webhooks));
    $this->assertInternalType('array', $result->data->webhooks);
  }
  /**
   * Check that requesting something that's no there throws the right exception
   *
   * @expectedException CRM_Mailchimp_RequestErrorException
   */
  public function test404() {
    CRM_Mailchimp_Utils::getMailchimpApi()->get('/lists/thisisnotavalidlisthash');
  }
}

