<?php
/**
 * @file
 * These tests run with a mocked Mailchimp API.
 *
 * They test that expected calls are made (or not made) based on changes in
 * CiviCRM.
 *
 */
/**
 * This tests CiviCRM using mocked Mailchimp API responses.
 *
 * It does not depend on a live Mailchimp account. However it is not a unit test
 * because it does depend on and make changes to the CiviCRM database.
 *
 * It is useful to mock the Maichimp API because
 * - It removes a dependency, so test results are more predictable.
 * - It is much faster to run
 * - It can be run without a Mailchimp account/api_key, and makes no changes to
 *   a mailchimp account, so could be seen as safer.
 */
require 'integration-test-bootstrap.php';

use \Prophecy\Argument;

class MailchimpApiIntegrationMockTest extends \PHPUnit_Framework_TestCase {
  const MC_TEST_LIST_NAME = 'Mailchimp-CiviCRM Integration Test List';
  const MC_INTEREST_CATEGORY_TITLE = 'Test Interest Category';
  const MC_INTEREST_NAME = 'Orang-utans';
  const C_TEST_MEMBERSHIP_GROUP_NAME = 'mailchimp_integration_test_1';
  const C_TEST_INTEREST_GROUP_NAME = 'mailchimp_integration_test_2';
  protected static $api_contactable;
  /** string holds the Mailchimp Id for our test list. */
  protected static $test_list_id = 'dummylistid';
  /** string holds the Mailchimp Id for test interest category. */
  protected static $test_interest_category_id;
  /** string holds the Mailchimp Id for test interest. */
  protected static $test_interest_id;

  /** holds CiviCRM contact Id for test contact 1*/
  protected static $test_cid1;
  /** holds CiviCRM contact Id for test contact 2*/
  protected static $test_cid2;
  /** holds CiviCRM Group Id for membership group*/
  protected static $civicrm_group_id_membership;
  /** holds CiviCRM Group Id for interest group*/
  protected static $civicrm_group_id_interest;

  /**
   * array Test contact 1
   */
  protected static $civicrm_contact_1 = [
    'contact_id' => NULL,
    'first_name' => 'Wilma',
    'last_name' => 'Flintstone-Test-Record',
    ];

  /**
   * Connect to API and create test fixture lists.
   */
  public static function setUpBeforeClass() {

    //
    // Now set up the CiviCRM fixtures.
    //

    // Need to know field Ids for mailchimp fields.
    $result = civicrm_api3('CustomField', 'get', ['label' => array('LIKE' => "%mailchimp%")]);
    $custom_ids = [];
    foreach ($result['values'] as $custom_field) {
      $custom_ids[$custom_field['name']] = "custom_" . $custom_field['id'];
    }
    // Ensure we have the fields we later rely on.
    foreach (['Mailchimp_Group', 'Mailchimp_Grouping', 'Mailchimp_List', 'is_mc_update_grouping'] as $_) {
      if (empty($custom_ids[$_])) {
        throw new Exception("Expected to find the Custom Field with name $_");
      }
    }

    // Next create mapping groups in CiviCRM?
    $result = civicrm_api3('Group', 'get', ['name' => static::C_TEST_MEMBERSHIP_GROUP_NAME, 'sequential' => 1]);
    if ($result['count'] == 0) {
      // Didn't exist, create it now.
      $result = civicrm_api3('Group', 'create', [
        'sequential' => 1,
        'name' => static::C_TEST_MEMBERSHIP_GROUP_NAME,
        'title' => static::C_TEST_MEMBERSHIP_GROUP_NAME,
      ]);
    }
    static::$civicrm_group_id_membership = (int) $result['values'][0]['id'];

    // Ensure this group is set to be the membership group.
    $result = civicrm_api3('Group', 'create', array(
      'id' => static::$civicrm_group_id_membership,
      $custom_ids['Mailchimp_List'] => static::$test_list_id,
      $custom_ids['is_mc_update_grouping'] => 0,
      $custom_ids['Mailchimp_Grouping'] => NULL,
      $custom_ids['Mailchimp_Group'] => NULL,
    ));


    // Now create test contact 1
    $domain = preg_replace('@^https?://([^/]+).*$@', '$1', CIVICRM_UF_BASEURL);
    $email = strtolower(static::$civicrm_contact_1['first_name'] . '.' . static::$civicrm_contact_1['last_name'])
      . '@' . $domain;
    $result = civicrm_api3('Contact', 'get', ['sequential' => 1,
      'first_name' => static::$civicrm_contact_1['first_name'],
      'last_name'  => static::$civicrm_contact_1['last_name'],
      'email'      => $email,
      ]);

    if ($result['count'] == 0) {
      print "Creating contact...\n";
      // Create the contact.
      $result = civicrm_api3('Contact', 'create', ['sequential' => 1,
        'contact_type' => 'Individual',
        'first_name' => static::$civicrm_contact_1['first_name'],
        'last_name'  => static::$civicrm_contact_1['last_name'],
        'email'      => $email,
      ]);
    }
    static::$civicrm_contact_1['contact_id'] = (int) $result['values'][0]['id'];
  }
  /**
   * Remove the test list, if one was successfully set up.
   */
  public static function tearDownAfterClass() {
    // CiviCRM teardown.
    if (!empty(static::$civicrm_contact_1['contact_id'])) {
      print "Deleting test contact " . static::$civicrm_contact_1['contact_id'] . "\n";
      $contact_id = (int) static::$civicrm_contact_1['contact_id'];
      if ($contact_id>0) {
        $result = civicrm_api3('Contact', 'delete', [
          'id' => $contact_id,
          'skip_undelete' => 1,
        ]);
      }
    }
  }

  /**
   * Checks the right calls are made by the getMCInterestGroupings.
   *
   */
  public function testGetMCInterestGroupings() {

    // Get Mock API.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());

    // Creating a sync object will cause a load of interests for that list from
    // Mailchimp, so we prepare the mock for that.
    $api_prophecy->get("/lists/dummylistid/interest-categories", Argument::any())
      ->shouldBeCalled()
      ->willReturn(json_decode('{"http_code":200,"data":{"categories":[{"id":"categoryid","title":"'. self::MC_INTEREST_CATEGORY_TITLE . '"}]}}'));

    $api_prophecy->get("/lists/dummylistid/interest-categories/categoryid/interests", Argument::any())
      ->shouldBeCalled()
      ->willReturn(json_decode('{"http_code":200,"data":{"interests":[{"id":"interestid","name":"' . self::MC_INTEREST_NAME . '"}]}}'));

    CRM_Mailchimp_Utils::getMCInterestGroupings('dummylistid');
  }
  /**
   * Check the right calls are made to the Mailchimp API.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testPostHookSubscribesWhenAddedToMembershipGroup() {

    // Get Mock API.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());

    // Prepare some vars used in testing.
    $domain = preg_replace('@^https?://([^/]+).*$@', '$1', CIVICRM_UF_BASEURL);
    $email = strtolower(static::$civicrm_contact_1['first_name'] . '.' . static::$civicrm_contact_1['last_name'])
      . '@' . $domain;
    $subscriber_hash = md5(strtolower($email));

    //
    // Test:
    //
    // If someone is added to the CiviCRM group, then we should expect them to
    // get subscribed.

    // Prepare the mock for the syncSingleContact
    // We expect that a PUT request is sent to Mailchimp.
    $api_prophecy->put("/lists/dummylistid/members/$subscriber_hash",
      Argument::that(function($_){ return $_['status'] == 'subscribed'; }))
      ->shouldBeCalled();

    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_membership,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Added",
    ]);


    //
    // Test:
    //
    // If someone is removed or deleted from the CiviCRM group they should get
    // removed from Mailchimp.

    // Prepare the mock for the syncSingleContact - this should get called
    // twice.
    $api_prophecy->patch("/lists/dummylistid/members/$subscriber_hash", ['status' => 'unsubscribed'])
      ->shouldbecalledTimes(2);

    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_membership,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Removed",
    ]);

    //
    // Test:
    //
    // If someone is deleted from the CiviCRM group they should get removed from
    // Mailchimp.
    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_membership,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Removed",
    ]);


  }
  /**
   * Checks that multiple updates do not trigger syncs.
   *
   * We run the testGetMCInterestGroupings first as it caches data this depends
   * on.
   * @depends testGetMCInterestGroupings
   */
  public function testPostHookDoesNotRunForBulkUpdates() {

    // Get Mock API.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());

    $api_prophecy->put()->shouldNotBeCalled();
    $api_prophecy->patch()->shouldNotBeCalled();
    $api_prophecy->get()->shouldNotBeCalled();
    $api_prophecy->post()->shouldNotBeCalled();
    $api_prophecy->delete()->shouldNotBeCalled();

    // Array of ContactIds - provide 2.
    $objectRef = [static::$civicrm_contact_1['contact_id'], 1];
    mailchimp_civicrm_post('create', 'GroupContact', $objectId=static::$civicrm_group_id_membership, $objectRef );
  }
}
