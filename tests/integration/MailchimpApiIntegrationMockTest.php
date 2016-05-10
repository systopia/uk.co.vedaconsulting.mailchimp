<?php
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

class MailchimpApiIntegrationMockTest extends \PHPUnit_Framework_TestCase {
  const MC_TEST_LIST_NAME = 'Mailchimp-CiviCRM Integration Test List';
  const MC_INTEREST_CATEGORY_TITLE = 'Test Interest Category';
  const MC_INTEREST_NAME = 'Orang-utans';
  const C_TEST_MEMBERSHIP_GROUP_NAME = 'mailchimp_integration_test_1';
  const C_TEST_INTEREST_GROUP_NAME = 'mailchimp_integration_test_2';
  protected static $api_contactable;
  /** string holds the Mailchimp Id for our test list. */
  protected static $test_list_id;
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
    try {
      // Use mock api.
      CRM_Mailchimp_Utils::setMailchimpApi(new CRM_Mailchimp_Api3Stub(['api_key' => 'aaabbbcccdddeee-uk']));

      $api = CRM_Mailchimp_Utils::getMailchimpApi();
      $api->addMockResponse(['result' => '{"account_name":"mock account","email":"mock@example.com","contact":{"company":"mock Co.","addr1":"12 somewhere","addr2":"","city":"","state":"","zip":"","country":"GB"}}']);
      $result = $api->get('/');
      static::$api_contactable = $result;

      // Ensure we have a test list.
      $test_list_id = NULL;
      $api->addMockResponse(['result' => '{"lists":[{"id":"aabbccddee","name":"' . self::MC_TEST_LIST_NAME . '"}]}']);
      $lists = $api->get('/lists', ['count' => 10000, 'fields' => 'lists.name,lists.id'])->data->lists;
      foreach ($lists as $list) {
        if ($list->name == self::MC_TEST_LIST_NAME) {
          $test_list_id = $list->id;
          break;
        }
      }

      if (empty($test_list_id)) {
        // Test list does not exist, create it now.

        // Annoyingly Mailchimp uses addr1 in a GET / response and address1 for
        // a POST /lists request!
        $contact = (array) static::$api_contactable->data->contact;
        $contact['address1'] = $contact['addr1'];
        $contact['address2'] = $contact['addr2'];
        unset($contact['addr1'], $contact['addr2']);

        $test_list_id = $api->post('/lists', [
          'name' => self::MC_TEST_LIST_NAME,
          'contact' => $contact,
          'permission_reminder' => 'This is sent to test email accounts only.',
          'campaign_defaults' => [
            'from_name' => 'Automated Test Script',
            'from_email' => static::$api_contactable->data->email,
            'subject' => 'Automated Test',
            'language' => 'en',
            ],
          'email_type_option' => FALSE,
        ])->data->id;
      }

      // Store this for our fixture.
      static::$test_list_id = $test_list_id;

      // Ensure the list has the interest category we need.
      $api->addMockResponse(['result' => '{"categories":[{"id":"categoryid","title":"' . self::MC_INTEREST_CATEGORY_TITLE . '"}]}']);
      $categories = $api->get("/lists/$test_list_id/interest-categories",
            ['fields' => 'categories.id,categories.title','count'=>10000])
          ->data->categories;
      $category_id = NULL;
      foreach ($categories as $category) {
        if ($category->title == static::MC_INTEREST_CATEGORY_TITLE) {
          $category_id = $category->id;
        }
      }
      if ($category_id === NULL) {
        // Create it.
        $category_id = $api->post("/lists/$test_list_id/interest-categories", [
          'title' => static::MC_INTEREST_CATEGORY_TITLE,
          'type' => 'hidden',
        ])->data->id;
      }

      // Ensure the interest category has the interests we need.
      $api->addMockResponse(['result' => '{"interests":[{"id":"interestid","name":"' . self::MC_INTEREST_NAME . '"}]}']);
      $interests = $api->get("/lists/$test_list_id/interest-categories/$category_id/interests",
            ['fields' => 'interests.id,interests.name','count'=>10000])
          ->data->interests;
      $interest_id = NULL;
      foreach ($interests as $interest) {
        if ($interest->name == static::MC_INTEREST_NAME) {
          $interest_id = $interest->id;
        }
      }
      if ($interest_id === NULL) {
        // Create it.
        // Note: as of 9 May 2016, Mailchimp do not advertise this method and
        // while it works, it throws an error. They confirmed this behaviour in
        // a live chat session and said their devs would look into it, so may
        // have been fixed.
        try {
          $interest_id = $api->post("/lists/$test_list_id/interest-categories/$category_id/interests", [
            'name' => static::MC_INTEREST_NAME,
          ])->data->id;
        }
        catch (CRM_Mailchimp_NetworkErrorException $e) {
          // As per comment above, this may still have worked. Repeat the
          // lookup.
          //
          $interests = $api->get("/lists/$test_list_id/interest-categories/$category_id/interests",
                ['fields' => 'interests.id,interests.name','count'=>10000])
              ->data->interests;
          foreach ($interests as $interest) {
            if ($interest->name == static::MC_INTEREST_NAME) {
              $interest_id = $interest->id;
            }
          }
          if (empty($interest_id)) {
            throw new CRM_Mailchimp_NetworkErrorException($api, "Creating the interest failed, and while this is a known bug, it actually did not create the interest, either. ");
          }
        }
      }
    }
    catch (CRM_Mailchimp_Exception $e) {
      // Spit out request and response for debugging.
      print "Request:\n";
      print_r($e->request);
      print "Response:\n";
      print_r($e->response);
      // re-throw exception.
      throw $e;
    }

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
    return;
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

    // Mailchimp tear-down:
    if (empty(static::$api_contactable->http_code)
      || static::$api_contactable->http_code != 200
      || empty(static::$test_list_id)
      || !is_string(static::$test_list_id)) {

      // Nothing to do.
      return;
    }

    try {

      // Delete is a bit of a one-way thing so we really test that it's the
      // right thing to do.

      // Check that the list exists, is named as we expect and only has max 2
      // contacts.
      $api = CRM_Mailchimp_Utils::getMailchimpApi();
      $test_list_id = static::$test_list_id;
      $api->addMockResponse(['result' => '{"id":"'.$test_list_id.'","name":"'.static::MC_TEST_LIST_NAME.'","stats":{"member_count":1}}']);
      $result = $api->get("/lists/$test_list_id", ['fields' => 'id,name,stats.member_count']);
      if ($result->http_code != 200) {
        throw new CRM_Mailchimp_RequestErrorException($api, "Trying to delete test list $test_list_id but getting list details failed. ");
      }
      if ($result->data->id != $test_list_id) {
        // OK this is paranoia.
        throw new CRM_Mailchimp_RequestErrorException($api, "Trying to delete test list $test_list_id but getting list returned different list?! ");
      }
      if ($result->data->name != static::MC_TEST_LIST_NAME) {
        // OK this is paranoia.
        throw new CRM_Mailchimp_RequestErrorException($api, "Trying to delete test list $test_list_id but the name was not as expected, so not deleted. ");
      }
      if ($result->data->stats->member_count > 2) {
        // OK this is paranoia.
        throw new CRM_Mailchimp_RequestErrorException($api, "Trying to delete test list $test_list_id but it has more than 2 members, so not deleted. ");
      }

      // OK, the test list exists, has the right name and only has two members:
      // delete it.
      $api->addMockResponse(['curl_info'=>['http_code'=>204]]);
      $result = $api->delete("/lists/$test_list_id");
      if ($result->http_code != 204) {
        throw new CRM_Mailchimp_RequestErrorException($api, "Trying to delete test list $test_list_id but delete method did not return 204 as http response. ");
      }

    }
    catch (CRM_Mailchimp_Exception $e) {
      print "*** Exception!***\n" . $e->getMessage() . "\n";
      // Spit out request and response for debugging.
      print "Request:\n";
      print_r($e->request);
      print "Response:\n";
      print_r($e->response);
      // re-throw exception for usual stack trace etc.
      throw $e;
    }
  }
  /**
   * This is run before every test method.
   */
  public function assertPreConditions() {
    $this->assertEquals(200, static::$api_contactable->http_code);
    $this->assertTrue(!empty(static::$api_contactable->data->account_name), "Expected account_name to be returned.");
    $this->assertTrue(!empty(static::$api_contactable->data->email), "Expected email belonging to the account to be returned.");

    $this->assertNotEmpty(static::$test_list_id);
    $this->assertInternalType('string', static::$test_list_id);
    $this->assertGreaterThan(0, static::$civicrm_contact_1['contact_id']);
  }
  public function testPostHookSubscribesWhenAddedToMembershipGroup() {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();
    $api->addMockResponse(['result' => '{"categories":[{"id":"categoryid","title":"' . self::MC_INTEREST_CATEGORY_TITLE . '"}]}']);
    $api->addMockResponse(['result' => '{"interests":[{"id":"interestid","name":"' . self::MC_INTEREST_NAME . '"}]}']);
    //CRM_Mailchimp_Utils::setMailchimpApi(new CRM_Mailchimp_Api3Stub());
    $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
    // If someone is added to the CiviCRM group, then we should expect them to
    // get subscribed.

    $api->addMockResponse();
    // Prepare the mock for the syncSingleContact - there should be one call to
    // the API and it just needs to return success.
    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_membership,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Added",
    ]);

    // Now test if that person is subscribed at MC.
    $domain = preg_replace('@^https?://([^/]+).*$@', '$1', CIVICRM_UF_BASEURL);
    $email = strtolower(static::$civicrm_contact_1['first_name'] . '.' . static::$civicrm_contact_1['last_name'])
      . '@' . $domain;
    $subscriber_hash = md5(strtolower($email));

    try {
      $api->addMockResponse(['result' => '{"status":"subscribed"}']);
      $result = $api->get("/lists/" . static::$test_list_id . "/members/$subscriber_hash", ['fields' => 'status']);
    }
    catch (CRM_Mailchimp_RequestErrorException $e) {
      if ($e->response->http_code == 404) {
        // Not subscribed.
        $this->fail("Expected contact to be in the list at Mailchimp, but MC said resource not found; i.e. not subscribed.");
      }
      throw $e;
    }
    $this->assertEquals('subscribed', $result->data->status);

    // Prepare the mock for the syncSingleContact - there should be one call to
    // the API and it just needs to return success.
    $api->addMockResponse();
    // Now remove them from the group.
    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_membership,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Removed",
    ]);
    try {
      $api->addMockResponse(['result' => '{"status":"unsubscribed"}']);
      $result = $api->get("/lists/" . static::$test_list_id . "/members/$subscriber_hash", ['fields' => 'status']);
    }
    catch (CRM_Mailchimp_RequestErrorException $e) {
      if ($e->response->http_code == 404) {
        // Not subscribed.
        $this->fail("Expected contact to be in the list at Mailchimp, in an unsubscribed state but Mailchimp said not a member.");
      }
      throw $e;
    }
    $this->assertEquals('unsubscribed', $result->data->status);

    // @todo what if the record was deleted. there would be no remove - so my
    // optimization does not work :-(

  }
  /**
   * Test the post hooks.
   */
  public function xtestPostHooks() {
    // Disable Mailchimp API for testing.

    $result = civicrm_api3('GroupContact', 'create', array(
      'sequential' => 1,
      'group_id' => static::C_TEST_MEMBERSHIP_GROUP_NAME,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Added",
    ));

    // Now sync this list.
    // We do this without CiviCRM's batch process.
    $sync = new CRM_Mailchimp_Sync(static::$test_list_id);

    // Collect data from Mailchimp.
    // There shouldn't be any members in this list yet.
    $sync->collectMailchimp();
    $this->assertEquals(0, $sync->countMailchimpMembers());

    // Collect data from CiviCRM.
    // There should be one member.
    $sync->collectCiviCrm();
    $this->assertEquals(1, $sync->countCiviCrmMembers());

      //array('CRM_Mailchimp_Form_Sync', 'syncPushRemove'),
      //array('CRM_Mailchimp_Form_Sync', 'syncPushAdd'),


  }
  /**
   * Test that we can add someone to the Mailchimp list by
   * adding them to the sync'ed group in CiviCRM.
   */
  public function xtestNewGroupMembersBecomeSubscribed() {
    $result = civicrm_api3('GroupContact', 'create', array(
      'sequential' => 1,
      'group_id' => static::C_TEST_MEMBERSHIP_GROUP_NAME,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Added",
    ));

    // Now sync this list.
    // We do this without CiviCRM's batch process.
    $sync = new CRM_Mailchimp_Sync(static::$test_list_id);

    // Collect data from Mailchimp.
    // There shouldn't be any members in this list yet.
    $sync->collectMailchimp();
    $this->assertEquals(0, $sync->countMailchimpMembers());

    // Collect data from CiviCRM.
    // There should be one member.
    $sync->collectCiviCrm();
    $this->assertEquals(1, $sync->countCiviCrmMembers());

      //array('CRM_Mailchimp_Form_Sync', 'syncPushRemove'),
      //array('CRM_Mailchimp_Form_Sync', 'syncPushAdd'),


  }
  /**
   * Test that we can connect to the API and retrieve lists.
   */
  public function xtestLists() {
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
  public function xtest404() {
    CRM_Mailchimp_Utils::getMailchimpApi()->get('/lists/thisisnotavalidlisthash');
  }
  /**
   * Test that we can get members' details, like the old export API.
   */
  public function xtestExport() {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();

    // Assumes there is at least one list with at least 10 people on it.
    $result = $api->get('/lists');
    $list_id = $result->data->lists[0]->id;
    $result = $api->get("/lists/$list_id/members?count=5");
    $this->assertEquals(200, $result->http_code);
    $this->assertEquals(5, count($result->data->members));
  }
  /**
   * Test CiviCRM API function to get mailchimp lists.
   */
  public function xtestCiviCrmApiGetLists() {
    $params = [];
    $lists = civicrm_api3('Mailchimp', 'getlists', $params);
    $a=1;
  }
  /**
   * Test syncCollectMailchimp WIP.
   */
  public function xtestSCM() {
    $list_id = 'dea38e8063';
    //$api = CRM_Mailchimp_Utils::getMailchimpApi();
    //$response = $api->get("/lists/$list_id/members", ['count' => 50]);
    //$mapped_groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), $list_id);
    CRM_Mailchimp_Form_Sync::syncCollectMailchimp($list_id);
    //$api = CRM_Mailchimp_Utils::getMailchimpApi(); $data = $api->get("/lists", ['fields'=>'lists.id,lists.name'])->data;
    $a=1;
  }
}
