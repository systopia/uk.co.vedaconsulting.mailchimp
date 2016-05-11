<?php
require 'integration-test-bootstrap.php';

class MailchimpApiIntegrationTest extends MailchimpApiIntegrationBase {
  /**
   * Connect to API and create test fixture lists.
   */
  public static function setUpBeforeClass() {
    static::createMailchimpFixtures();
    static::createCiviCrmFixtures();
  }
  /**
   * Remove the test list, if one was successfully set up.
   */
  public static function tearDownAfterClass() {
    static::tearDownCiviCrmFixtures();
    static::tearDownMailchimpFixtures();
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

  /**
   * Basic test of using the batchAndWait.
   *
   * Just should not throw anything. Tests that the round-trip of submitting a
   * batch request to MC, receiving a job id and polling it until finished is
   * working. For sanity's sake, really!
   *
   * The MC calls do not depend on any fixtures and should work with any
   * Mailchimp account.
   */
  public function testBatch() {
    $this->markTestSkipped("Fairly confident batch works now. Test ommited to speed up other more significant tests.");

    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());

    $api = CRM_Mailchimp_Utils::getMailchimpApi();

    try {
      $result = $api->batchAndWait([
        ['get', "/lists"],
        ['get', "/campaigns/", ['count'=>10]],
      ]);
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
  }


  /**
   * Starting with an empty MC list and one person on the CiviCRM mailchimp
   * group, a push should subscribe the person.
   *
   */
  public function testPushAddsNewPerson() {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();

    try {

      // We need to add the person to the membership group, but we need
      // to disable the post hook which will try to send the subscription to
      // Mailchimp. We can do that by temporarily disabling network access to the
      // Mailchimp API.
      // Cache list data before we disable network.
      CRM_Mailchimp_Utils::getGroupsToSync();
      $api->setNetworkEnabled(FALSE);
      $result = civicrm_api3('GroupContact', 'create', [
        'sequential' => 1,
        'group_id' => static::$civicrm_group_id_membership,
        'contact_id' => static::$civicrm_contact_1['contact_id'],
        'status' => "Added",
      ]);
      $api->setNetworkEnabled(TRUE);

      // Double-check this member is not known at Mailchimp.
      $this->assertContactNotListMember(static::$civicrm_contact_1);
      $sync = new CRM_Mailchimp_Sync(static::$test_list_id);

      // Now trigger a push for this test list.

      // Collect data from Mailchimp.
      // There shouldn't be any members in this list yet.
      $sync->collectMailchimp();
      $this->assertEquals(0, $sync->countMailchimpMembers());

      // Collect data from CiviCRM.
      // There should be one member.
      $sync->collectCiviCrm();
      $this->assertEquals(1, $sync->countCiviCrmMembers());

      // There should not be any in sync records.
      $in_sync = $sync->removeInSync();
      $this->assertEquals(0, $in_sync);

      // Check that removals (i.e. someone in Mailchimp but not/no longer in
      // Civi's group) are zero.
      $to_delete = $sync->getEmailsNotInCiviButInMailchimp();
      $this->assertEquals(0, count($to_delete));

      // Run bulk subscribe...
      $sync->addFromCiviCrm();

      // Now check they are subscribed.
      $not_found = TRUE;
      $i =0;
      $start = time();
      //print date('Y-m-d H:i:s') . " Mailchimp batch returned 'finished'\n";
      while ($not_found && $i++ < 2*10) {
        try {
          $result = $api->get("/lists/" . static::$test_list_id . "/members/" . static::$civicrm_contact_1['subscriber_hash'], ['fields' => 'status']);
          // print date('Y-m-d H:i:s') . " found now " . round(time() - $start, 2) . "s after Mailchimp reported the batch had finished.\n";
          $not_found = FALSE;
        }
        catch (CRM_Mailchimp_RequestErrorException $e) {
          if ($e->response->http_code == 404) {
            // print date('Y-m-d H:i:s') . " not found yet\n";
            sleep(10);
          }
          else {
            throw $e;
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
  }

  /**
   * Check interests are properly mapped as groups are changed.
   *
   * This uses the posthook, which in turn uses syncSingleContact.
   *
   * Ensure we have someone on the list by depending on the push.
   * @depends testPushAddsNewPerson
   */
  public function testSyncInterestGroupings() {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();

    try {

      // Add them to the interest group.
      $result = civicrm_api3('GroupContact', 'create', [
        'sequential' => 1,
        'group_id' => static::$civicrm_group_id_interest_1,
        'contact_id' => static::$civicrm_contact_1['contact_id'],
        'status' => "Added",
      ]);
      // Check their interest group was set.
      $result = $api->get("/lists/" . static::$test_list_id . "/members/" . static::$civicrm_contact_1['subscriber_hash'], ['fields' => 'status,interests'])->data;
      $this->assertEquals((object) [static::$test_interest_id_1 => TRUE, static::$test_interest_id_2 => FALSE], $result->interests);

      // Remove them to the interest group.
      $result = civicrm_api3('GroupContact', 'delete', [
        'group_id' => static::$civicrm_group_id_interest_1,
        'contact_id' => static::$civicrm_contact_1['contact_id'],
      ]);
      // Check their interest group was unset.
      $result = $api->get("/lists/" . static::$test_list_id . "/members/" . static::$civicrm_contact_1['subscriber_hash'], ['fields' => 'status,interests'])->data;
      $this->assertEquals((object) [static::$test_interest_id_1 => FALSE, static::$test_interest_id_2 => FALSE], $result->interests);

      // Add them to the 2nd interest group.
      // While this is a dull test, we assume it works if the other interest
      // group one did, it leaves the fixture with one on and one off which is a
      // good mix for the next test.
      $result = civicrm_api3('GroupContact', 'create', [
        'sequential' => 1,
        'group_id' => static::$civicrm_group_id_interest_2,
        'contact_id' => static::$civicrm_contact_1['contact_id'],
        'status' => "Added",
      ]);
      // Check their interest group was set.
      $result = $api->get("/lists/" . static::$test_list_id . "/members/" . static::$civicrm_contact_1['subscriber_hash'], ['fields' => 'status,interests'])->data;
      $this->assertEquals((object) [static::$test_interest_id_1 => FALSE, static::$test_interest_id_2 => TRUE], $result->interests);
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
  }

  /**
   * Test collectMailchimp and collectCiviCrm work as expected.
   *
   * The systems are in sync, so both collections should match.
   *
   * Ensure we have someone on the list with interest2 by this:
   * @depends testSyncInterestGroupings
   */
  public function testCollections() {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();

    try {
      $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
      $sync->collectMailchimp();
      $this->assertEquals(1, $sync->countMailchimpMembers());
      $sync->collectCiviCrm();
      $this->assertEquals(1, $sync->countCiviCrmMembers());

      // This should return 1
      $dao = CRM_Core_DAO::executeQuery("SELECT * FROM tmp_mailchimp_push_m");
      $dao->fetch();
      $mc = [
        'email' => $dao->email,
        'first_name' => $dao->first_name,
        'last_name' => $dao->last_name,
        'interests' => $dao->interests,
        'hash' => $dao->hash,
        'cid_guess' => $dao->cid_guess,
      ];
      $dao = CRM_Core_DAO::executeQuery("SELECT * FROM tmp_mailchimp_push_c");
      $dao->fetch();
      $civi = [
        'email' => $dao->email,
        'email_id' => $dao->email_id,
        'contact_id' => $dao->contact_id,
        'first_name' => $dao->first_name,
        'last_name' => $dao->last_name,
        'interests' => $dao->interests,
        'hash' => $dao->hash,
      ];
      $this->assertEquals($civi['hash'], $mc['hash']);
      $this->assertEquals($civi['first_name'], $mc['first_name']);
      $this->assertEquals($civi['last_name'], $mc['last_name']);
      $this->assertEquals($civi['email'], $mc['email']);
      $this->assertEquals($civi['interests'], $mc['interests']);

      // As the records are in sync, they should be and deleted.
      $in_sync = $sync->removeInSync();
      $this->assertEquals(1, $in_sync);

      // Now check the tables are both empty.
      $this->assertEquals(0, $sync->countMailchimpMembers());
      $this->assertEquals(0, $sync->countCiviCrmMembers());
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
  }

  /**
   * Test push updates a record that changed in CiviCRM.
   *
   * Ensure we have someone on the list with interest2 by this:
   * @depends testCollections
   */
  public function testPushChangedName() {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();

    try {
      // Let's change the name of our test record
      $this->assertNotEmpty(static::$civicrm_contact_1['contact_id']);
      civicrm_api3('Contact', 'create', [
        'contact_id' => static::$civicrm_contact_1['contact_id'],
        'first_name' => 'Betty',
        ]);

      $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
      $sync->collectMailchimp();
      $this->assertEquals(1, $sync->countMailchimpMembers());
      $sync->collectCiviCrm();
      $this->assertEquals(1, $sync->countCiviCrmMembers());

      // As the records are not in sync, none should get deleted.
      $in_sync = $sync->removeInSync();
      $this->assertEquals(0, $in_sync);

      // Now change name back and instead change their interests.
      civicrm_api3('Contact', 'create', [
        'contact_id' => static::$civicrm_contact_1['contact_id'],
        'first_name' => static::$civicrm_contact_1['first_name'],
        ]);
      // Avoid the hook doing the update.
      $api->setNetworkEnabled(FALSE);
      $result = civicrm_api3('GroupContact', 'create', [
        'sequential' => 1,
        'group_id' => static::$civicrm_group_id_interest_1,
        'contact_id' => static::$civicrm_contact_1['contact_id'],
        'status' => "Added",
      ]);
      $api->setNetworkEnabled(TRUE);
      // re-collect the CiviCRM data and check it's still just one record.
      $sync->collectCiviCrm();
      $this->assertEquals(1, $sync->countCiviCrmMembers());
      // As the records are not in sync, none should get deleted.
      $in_sync = $sync->removeInSync();
      $this->assertEquals(0, $in_sync);

      // Change the name again as this is another thing we can test gets updated
      // correctly.
      civicrm_api3('Contact', 'create', [
        'contact_id' => static::$civicrm_contact_1['contact_id'],
        'first_name' => 'Betty',
        ]);

      // Add another contact.
      $this->assertNotEmpty(static::$civicrm_contact_2['contact_id']);
      $result = civicrm_api3('GroupContact', 'create', [
        'sequential' => 1,
        'group_id' => static::$civicrm_group_id_membership,
        'contact_id' => static::$civicrm_contact_2['contact_id'],
        'status' => "Added",
      ]);
      // Now collect Civi again.
      $sync->collectCiviCrm();
      $this->assertEquals(2, $sync->countCiviCrmMembers());
      // No records in sync, check this.
      $in_sync = $sync->removeInSync();
      $this->assertEquals(0, $in_sync);

      // Send updates to Mailchimp.
      $sync->addFromCiviCrm();

      // Now re-collect from Mailchimp and check all are in sync.
      $sync->collectMailchimp();
      $this->assertEquals(2, $sync->countMailchimpMembers());
      $in_sync = $sync->removeInSync();
      $this->assertEquals(2, $in_sync);

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
  }


  public function xtestPostHookSubscribesWhenAddedToMembershipGroup() {
    //CRM_Mailchimp_Utils::setMailchimpApi(new CRM_Mailchimp_Api3Stub());
    $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
    // If someone is added to the CiviCRM group, then we should expect them to
    // get subscribed.
    // CRM_Mailchimp_Utils::setMailchimpApi(new CRM_Mailchimp_Api3Stub());
    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_membership,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Added",
    ]);

    // Now test if that person is subscribed at MC.

    try {
      $subscriber_hash = static::$civicrm_contact_1['subscriber_hash'];
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

    // Now remove them from the group.
    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_membership,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Removed",
    ]);
    try {
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
  /**
   * Check that the contact's email is a member in given state.
   *
   * @param array $contact e.g. static::$civicrm_contact_1
   * @param string $state Mailchimp member state: 'subscribed', 'unsubscribed', ...
   */
  public function assertContactExistsWithState($contact, $state) {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();
    try {
      $result = $api->get("/lists/" . static::$test_list_id . "/members/$contact[subscriber_hash]", ['fields' => 'status']);
    }
    catch (CRM_Mailchimp_RequestErrorException $e) {
      if ($e->response->http_code == 404) {
        // Not subscribed give more helpful error.
        $this->fail("Expected contact $contact[email] to be in the list at Mailchimp, but MC said resource not found; i.e. not subscribed.");
      }
      throw $e;
    }
    $this->assertEquals($state, $result->data->status);
  }
  /**
   * Check that the contact's email is not a member of the test list.
   *
   * @param array $contact e.g. static::$civicrm_contact_1
   */
  public function assertContactNotListMember($contact) {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();
    try {
      $subscriber_hash = static::$civicrm_contact_1['subscriber_hash'];
      $result = $api->get("/lists/" . static::$test_list_id . "/members/$contact[subscriber_hash]", ['fields' => 'status']);
    }
    catch (CRM_Mailchimp_RequestErrorException $e) {
      $this->assertEquals(404, $e->response->http_code);
    }
  }
}

//
// test that collect Civi collects right interests data.
// test that collect Mailchimp collects right interests data.
//
// test that push does interests correctly.
// test when mc has unmapped interests that they are not affected by our code.
