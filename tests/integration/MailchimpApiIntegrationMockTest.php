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

class MailchimpApiIntegrationMockTest extends MailchimpApiIntegrationBase {

  public static function setUpBeforeClass() {
    static::createMailchimpMockFixtures();
    static::createCiviCrmFixtures();
  }
  /**
   * Set dummy fixtures.
   */
  public static function createMailchimpMockFixtures() {
    static::$test_list_id = 'dummylistid';
    static::$test_interest_category_id = 'categoryid';
    static::$test_interest_id_1 = 'interestId1';
    static::$test_interest_id_2 = 'interestId2';
  }
  /**
   * Remove the test list, if one was successfully set up.
   */
  public static function tearDownAfterClass() {
    static::tearDownCiviCrmFixtures();
  }

  /**
   * Checks the right calls are made by the getMCInterestGroupings.
   *
   * This is a dependency of some other tests because it also caches the result,
   * which means that we don't have to duplicate prophecies for this behaviour
   * in other tests.
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
      ->willReturn(json_decode('{"http_code":200,"data":{"interests":[{"id":"interestId1","name":"' . self::MC_INTEREST_NAME_1 . '"},{"id":"interestId2","name":"' . self::MC_INTEREST_NAME_2 . '"}]}}'));

    $interests = CRM_Mailchimp_Utils::getMCInterestGroupings('dummylistid');
    $this->assertEquals([ 'categoryid' => [
      'id' => 'categoryid',
      'name' => self::MC_INTEREST_CATEGORY_TITLE,
      'interests' => [
        'interestId1' => [ 'id' => 'interestId1', 'name' => static::MC_INTEREST_NAME_1 ],
        'interestId2' => [ 'id' => 'interestId2', 'name' => static::MC_INTEREST_NAME_2 ],
      ],
    ]], $interests);

  }
  /**
   * Tests the mapping of CiviCRM group memberships to an array of Mailchimp
   * interest Ids => Bool.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testGetComparableInterestsFromCiviCrmGroups() {

    $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
    $g = static::C_TEST_MEMBERSHIP_GROUP_NAME;
    $i = static::C_TEST_INTEREST_GROUP_NAME_1;
    $j = static::C_TEST_INTEREST_GROUP_NAME_2;
    $cases = [
      // In both membership and interest1
      "$g,$i" => ['interestId1'=>TRUE,'interestId2'=>FALSE],
      // Just in membership group.
      "$g" => ['interestId1'=>FALSE,'interestId2'=>FALSE],
      // In interest1 only.
      "$i" => ['interestId1'=>TRUE,'interestId2'=>FALSE],
      // In lots!
      "$j,other list name,$g,$i,and another" => ['interestId1'=>TRUE,'interestId2'=>TRUE],
      // In both and other non MC groups.
      "other list name,$g,$i,and another" => ['interestId1'=>TRUE,'interestId2'=>FALSE],
      // In none, just other non MC groups.
      "other list name,and another" => ['interestId1'=> FALSE,'interestId2'=>FALSE],
      // In no groups.
      "" => ['interestId1'=> FALSE,'interestId2'=>FALSE],
      ];
    foreach ($cases as $input=>$expected) {
      $ints = $sync->getComparableInterestsFromCiviCrmGroups($input);
      $this->assertEquals($expected, $ints, "mapping failed for test '$input'");
    }

  }
  /**
   * Tests the mapping of CiviCRM group memberships to an array of Mailchimp
   * interest Ids => Bool.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testGetComparableInterestsFromMailchimp() {

    $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
    $cases = [
      // 'Normal' tests
      [ (object) ['interestId1' => TRUE, 'interestId2'=>TRUE], ['interestId1'=>TRUE, 'interestId2'=>TRUE]],
      [ (object) ['interestId1' => FALSE, 'interestId2'=>TRUE], ['interestId1'=>FALSE, 'interestId2'=>TRUE]],
      // Test that if Mailchimp omits an interest grouping we've mapped it's
      // considered false. This wil be the case if someone deletes an interest
      // on Mailchimp but not the mapped group in Civi.
      [ (object) ['interestId1' => TRUE], ['interestId1'=>TRUE, 'interestId2'=>FALSE]],
      // Test that non-mapped interests are ignored.
      [ (object) ['interestId1' => TRUE, 'foo' => TRUE], ['interestId1'=>TRUE, 'interestId2'=>FALSE]],
      ];
    foreach ($cases as $i=>$_) {
      list($input, $expected) = $_;
      $ints = $sync->getComparableInterestsFromMailchimp($input);
      $this->assertEquals($expected, $ints, "mapping failed for test '$i'");
    }

  }
  /**
   * Check the right calls are made to the Mailchimp API.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testPostHookForMembershipListChanges() {

    // Get Mock API.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());

    // handy copy.
    $subscriber_hash = static::$civicrm_contact_1['subscriber_hash'];

    //
    // Test:
    //
    // If someone is added to the CiviCRM group, then we should expect them to
    // get subscribed.

    // Prepare the mock for the syncSingleContact
    // We expect that a PUT request is sent to Mailchimp.
    $api_prophecy->put("/lists/dummylistid/members/$subscriber_hash",
      Argument::that(function($_){
        return $_['status'] == 'subscribed'
          && $_['interests']['interestId1'] === FALSE
          && $_['interests']['interestId2'] === FALSE
          && count($_['interests']) == 2;
      }))
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
    $result = civicrm_api3('GroupContact', 'delete', [
      'group_id' => static::$civicrm_group_id_membership,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
    ]);


  }
  /**
   * Check the right calls are made to the Mailchimp API as result of
   * adding/removing/deleting someone from an group linked to an interest
   * grouping.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testPostHookForInterestGroupChanges() {

    // Get Mock API.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());

    $subscriber_hash = static::$civicrm_contact_1['subscriber_hash'];

    //
    // Test:
    //
    // Because this person is NOT on the membership list, nothing we do to their
    // interest group membership should result in a Mailchimp update.
    //
    // Prepare the mock for the syncSingleContact
    $api_prophecy->put("/lists/dummylistid/members/$subscriber_hash", Argument::any())->shouldNotBeCalled();

    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_interest_1,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Added",
    ]);
    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_interest_1,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Removed",
    ]);
    $result = civicrm_api3('GroupContact', 'delete', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_interest_1,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
    ]);

    //
    // Test:
    //
    // Add them to the membership group, then these interest changes sould
    // result in an update.

    // Create a new prophecy since we used the last one to assert something had
    // not been called.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());

    // Prepare the mock for the syncSingleContact
    // We expect that a PUT request is sent to Mailchimp.
    $api_prophecy->put("/lists/dummylistid/members/$subscriber_hash",
      Argument::that(function($_){
        return $_['status'] == 'subscribed'
          && $_['interests']['interestId1'] === FALSE
          && $_['interests']['interestId2'] === FALSE
          && count($_['interests']) == 2;
      }))
      ->shouldBeCalled();

    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_membership,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Added",
    ]);

    // Use new prophecy
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->put("/lists/dummylistid/members/$subscriber_hash", Argument::any())->shouldBeCalledTimes(3);

    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_interest_1,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Added",
    ]);
    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_interest_1,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Removed",
    ]);
    $result = civicrm_api3('GroupContact', 'delete', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_interest_1,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
    ]);

    // Finally delete the membership list group link to re-set the fixture.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->patch("/lists/dummylistid/members/$subscriber_hash", Argument::any())->shouldBeCalled();
    $result = civicrm_api3('GroupContact', 'delete', [
      'group_id' => static::$civicrm_group_id_membership,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
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
  /**
   * Tests the selection of email address.
   *
   * 1. Check initial email is picked up.
   * 2. Check that a bulk one is preferred, if exists.
   * 3. Check that a primary one is used bulk is on hold.
   * 4. Check that a primary one is used if no bulk one.
   * 5. Check that secondary, not bulk, not primary one is NOT used.
   * 6. Check that a not bulk, not primary one is used if all else fails.
   * 7. Check contact not selected if all emails on hold
   * 8. Check contact not selected if opted out
   * 9. Check contact not selected if 'do not email' is set
   * 10. Check contact not selected if deceased.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testCollectCiviUsesRightEmail() {

    $subscriber_hash = static::$civicrm_contact_1['subscriber_hash'];

    // Prepare the mock for the subscription the post hook will do.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->put("/lists/dummylistid/members/$subscriber_hash", Argument::any());
    $this->joinMembershipGroup(static::$civicrm_contact_1);
    $sync = new CRM_Mailchimp_Sync(static::$test_list_id);

    //
    // Test 1:
    //
    $sync->collectCiviCrm('push');
    // Should have one person in it.
    $this->assertEquals(1, $sync->countCiviCrmMembers());
    $dao = CRM_Core_DAO::executeQuery("SELECT email FROM tmp_mailchimp_push_c");
    $dao->fetch();
    // Check email is what we'd expect.
    $this->assertEquals(static::$civicrm_contact_1['email'], $dao->email);

    //
    // Test 2:
    //
    // Now add another email, this one is bulk.
    // Nb. adding a bulk email removes the is_bulkmail flag from other email
    // records.
    $second_email = civicrm_api3('Email', 'create', [
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'email' => static::$civicrm_contact_2['email'],
      'is_bulkmail' => 1,
      'sequential' => 1,
      ]);
    if (empty($second_email['id'])) {
      throw new Exception("Well this shouldn't happen. No Id for created email.");
    }
    $sync->collectCiviCrm('push');
    // Should have one person in it.
    $this->assertEquals(1, $sync->countCiviCrmMembers());
    $dao = CRM_Core_DAO::executeQuery("SELECT email FROM tmp_mailchimp_push_c");
    $dao->fetch();
    // Check email is what we'd expect.
    $this->assertEquals(static::$civicrm_contact_2['email'], $dao->email);

    //
    // Test 3:
    //
    // Set the bulk one to on hold.
    //
    civicrm_api3('Email', 'create', ['id' => $second_email['id'], 'on_hold' => 1]);
    $sync->collectCiviCrm('push');
    $this->assertEquals(1, $sync->countCiviCrmMembers());
    $dao = CRM_Core_DAO::executeQuery("SELECT email FROM tmp_mailchimp_push_c");
    $dao->fetch();
    // Check email is what we'd expect.
    $this->assertEquals(static::$civicrm_contact_1['email'], $dao->email);

    //
    // Test 4:
    //
    // Delete the bulk one; should now fallback to primary.
    //
    civicrm_api3('Email', 'delete', ['id' => $second_email['id']]);
    $sync->collectCiviCrm('push');
    // Should have one person in it.
    $this->assertEquals(1, $sync->countCiviCrmMembers());
    $dao = CRM_Core_DAO::executeQuery("SELECT email FROM tmp_mailchimp_push_c");
    $dao->fetch();
    // Check email is what we'd expect.
    $this->assertEquals(static::$civicrm_contact_1['email'], $dao->email);

    //
    // Test 5:
    //
    // Add a not bulk, not primary one. This should NOT get used.
    //
    $second_email = civicrm_api3('Email', 'create', [
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'email' => static::$civicrm_contact_2['email'],
      'is_bulkmail' => 0,
      'is_primary' => 0,
      'sequential' => 1,
      ]);
    if (empty($second_email['id'])) {
      throw new Exception("Well this shouldn't happen. No Id for created email.");
    }
    $sync->collectCiviCrm('push');
    $this->assertEquals(1, $sync->countCiviCrmMembers());
    $dao = CRM_Core_DAO::executeQuery("SELECT email FROM tmp_mailchimp_push_c");
    $dao->fetch();
    // Check email is what we'd expect.
    $this->assertEquals(static::$civicrm_contact_1['email'], $dao->email);

    //
    // Test 6:
    //
    // Check that an email is selected, even if there's no primary and no bulk.
    //
    // Find the primary email and delete it.
    $result = civicrm_api3('Email', 'get', [
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'email' => static::$civicrm_contact_1['email'],
      'api.Email.delete' => ['id' => '$value.id']
    ]);

    $sync->collectCiviCrm('push');
    // Should have one person in it.
    $this->assertEquals(1, $sync->countCiviCrmMembers());
    $dao = CRM_Core_DAO::executeQuery("SELECT email FROM tmp_mailchimp_push_c");
    $dao->fetch();
    // Check email is what we'd expect.
    $this->assertEquals(static::$civicrm_contact_2['email'], $dao->email);

    //
    // Test 7
    //
    // Check that if all emails are on hold, user is not selected.
    //
    civicrm_api3('Email', 'create', ['id' => $second_email['id'], 'on_hold' => 1]);
    $sync->collectCiviCrm('push');
    $this->assertEquals(0, $sync->countCiviCrmMembers());

    //
    // Test 8
    //
    // Check that even with a bulk, primary email, contact is not selected if
    // they have opted out.
    civicrm_api3('Email', 'create', ['id' => $second_email['id'],
      'email' => static::$civicrm_contact_1['email'],
      'on_hold' => 0,
    ]);
    civicrm_api3('Contact', 'create', [
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'is_opt_out' => 1,
    ]);
    $sync->collectCiviCrm('push');
    $this->assertEquals(0, $sync->countCiviCrmMembers());


    //
    // Test 9
    //
    // Check that even with a bulk, primary email, contact is not selected if
    // they have do_not_email
    civicrm_api3('Contact', 'create', [
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'is_opt_out' => 0,
      'do_not_email' => 1,
    ]);
    $sync->collectCiviCrm('push');
    $this->assertEquals(0, $sync->countCiviCrmMembers());


    //
    // Test 10
    //
    // Check that even with a bulk, primary email, contact is not selected if
    // they is_deceased
    civicrm_api3('Contact', 'create', [
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'do_not_email' => 0,
      'is_deceased' => 1,
    ]);
    $sync->collectCiviCrm('push');
    $this->assertEquals(0, $sync->countCiviCrmMembers());

  }
  /**
   * Check that list problems are spotted.
   *
   * 1. Test for missing webhooks.
   * 2. Test for error if the list is not found at Mailchimp.
   * 3. Test for network error.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testCheckGroupsConfig() {
    //
    // Test 1
    //
    // The default mock list does not have any webhooks set.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->get('/lists/dummylistid/webhooks');
    $groups = CRM_Mailchimp_Utils::getGroupsToSync([static::$civicrm_group_id_membership]);
    $warnings = CRM_Mailchimp_Utils::checkGroupsConfig($groups);
    $this->assertEquals(1, count($warnings));
    $this->assertContains(ts('Need to create a webhook'), $warnings[0]);


    //
    // Test 2
    //
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->get('/lists/dummylistid/webhooks')
      ->will(function($args) {
        // Need to mock a 404 response.
        $this->response = (object) ['http_code' => 404, 'data' => []];
        $this->request = (object) ['method' => 'GET'];
        throw new CRM_Mailchimp_RequestErrorException($this->reveal(), "Not found");
      });
    $groups = CRM_Mailchimp_Utils::getGroupsToSync([static::$civicrm_group_id_membership]);
    $warnings = CRM_Mailchimp_Utils::checkGroupsConfig($groups);
    $this->assertEquals(1, count($warnings));
    $this->assertContains(ts('The Mailchimp list that this once worked with has been deleted'), $warnings[0]);

    //
    // Test 3
    //
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->get('/lists/dummylistid/webhooks')
      ->will(function($args) {
        // Need to mock a network error
        $this->response = (object) ['http_code' => 500, 'data' => []];
        throw new CRM_Mailchimp_NetworkErrorException($this->reveal(), "Someone unplugged internet");
      });
    $groups = CRM_Mailchimp_Utils::getGroupsToSync([static::$civicrm_group_id_membership]);
    $warnings = CRM_Mailchimp_Utils::checkGroupsConfig($groups);
    $this->assertEquals(1, count($warnings));
    $this->assertContains(ts('Problems (possibly temporary)'), $warnings[0]);
    $this->assertContains(ts('Someone unplugged internet'), $warnings[0]);


  }
  /**
   * Check that config is updated as expected.
   *
   * 1. Webhook created where non exists.
   * 2. Webhook untouched if ok
   * 3. Webhook deleted, new one created if different.
   * 4. Webhooks untouched if multiple
   * 5. As 1 but in dry-run
   * 6. As 2 but in dry-run
   * 7. As 3 but in dry-run
   *
   *
   * @depends testGetMCInterestGroupings
   */
  public function testConfigureList() {
    //
    // Test 1
    //
    // The default mock list does not have any webhooks set, test one gets
    // created.
    //
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->get('/lists/dummylistid/webhooks')->shouldBeCalled();
    $api_prophecy->post('/lists/dummylistid/webhooks', Argument::any())->shouldBeCalled();
    $warnings = CRM_Mailchimp_Utils::configureList(static::$test_list_id);
    $this->assertEquals(1, count($warnings));
    $this->assertContains(ts('Created a webhook at Mailchimp'), $warnings[0]);

    //
    // Test 2
    //
    // If it's all correct, nothing to do.
    //
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->get('/lists/dummylistid/webhooks')->shouldBeCalled()->willReturn(
      json_decode(json_encode([
        'http_code' => 200,
        'data' => [
          'webhooks' => [
            [
              'id' => 'dummywebhookid',
              'url' => CRM_Mailchimp_Utils::getWebhookUrl(),
              'events' => [
                'subscribe' => TRUE,
                'unsubscribe' => TRUE,
                'profile' => TRUE,
                'cleaned' => TRUE,
                'upemail' => TRUE,
                'campaign' => FALSE,
              ],
              'sources' => [
                'user' => TRUE,
                'admin' => TRUE,
                'api' => FALSE,
              ],
            ]
        ]]])));
    $api_prophecy->post()->shouldNotBeCalled();
    $warnings = CRM_Mailchimp_Utils::configureList(static::$test_list_id);
    $this->assertEquals(0, count($warnings));

    //
    // Test 3
    //
    // If something's different, note and change.
    //
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->get('/lists/dummylistid/webhooks')->shouldBeCalled()->willReturn(
      json_decode(json_encode([
        'http_code' => 200,
        'data' => [
          'webhooks' => [
            [
              'id' => 'dummywebhookid',
              'url' => 'http://example.com', // WRONG
              'events' => [
                'subscribe' => FALSE, // WRONG
                'unsubscribe' => TRUE,
                'profile' => TRUE,
                'cleaned' => TRUE,
                'upemail' => TRUE,
                'campaign' => FALSE,
              ],
              'sources' => [
                'user' => TRUE,
                'admin' => TRUE,
                'api' => TRUE, // WRONG
              ],
            ]
        ]]])));
    $api_prophecy->delete('/lists/dummylistid/webhooks/dummywebhookid')->shouldBeCalled();
    $api_prophecy->post('/lists/dummylistid/webhooks', Argument::any())->shouldBeCalled();
    $warnings = CRM_Mailchimp_Utils::configureList(static::$test_list_id);
    $this->assertEquals(3, count($warnings));
    $this->assertContains('Changed webhook URL from http://example.com to', $warnings[0]);
    $this->assertContains('Changed webhook source api', $warnings[1]);
    $this->assertContains('Changed webhook event subscribe', $warnings[2]);

    //
    // Test 4
    //
    // If multiple webhooks configured, leave it alone.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->get('/lists/dummylistid/webhooks')->shouldBeCalled()->willReturn(
      json_decode(json_encode([
        'http_code' => 200,
        'data' => [
          'webhooks' => [1, 2],
        ]])));
    $api_prophecy->delete()->shouldNotBeCalled();
    $api_prophecy->post()->shouldNotBeCalled();
    $warnings = CRM_Mailchimp_Utils::configureList(static::$test_list_id);
    $this->assertEquals(1, count($warnings));
    $this->assertContains('Mailchimp list dummylistid has more than one webhook configured.', $warnings[0]);

    //
    // Test 5
    //
    // The default mock list does not have any webhooks set, test one gets
    // created.
    //
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->get('/lists/dummylistid/webhooks')->shouldBeCalled();
    $api_prophecy->delete()->shouldNotBeCalled();
    $api_prophecy->post()->shouldNotBeCalled();
    $warnings = CRM_Mailchimp_Utils::configureList(static::$test_list_id, TRUE);
    $this->assertEquals(1, count($warnings));
    $this->assertContains(ts('Need to create a webhook at Mailchimp'), $warnings[0]);

    //
    // Test 6
    //
    // If it's all correct, nothing to do.
    //
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->get('/lists/dummylistid/webhooks')->shouldBeCalled()->willReturn(
      json_decode(json_encode([
        'http_code' => 200,
        'data' => [
          'webhooks' => [
            [
              'id' => 'dummywebhookid',
              'url' => CRM_Mailchimp_Utils::getWebhookUrl(),
              'events' => [
                'subscribe' => TRUE,
                'unsubscribe' => TRUE,
                'profile' => TRUE,
                'cleaned' => TRUE,
                'upemail' => TRUE,
                'campaign' => FALSE,
              ],
              'sources' => [
                'user' => TRUE,
                'admin' => TRUE,
                'api' => FALSE,
              ],
            ]
        ]]])));
    $api_prophecy->delete()->shouldNotBeCalled();
    $api_prophecy->post()->shouldNotBeCalled();
    $warnings = CRM_Mailchimp_Utils::configureList(static::$test_list_id, TRUE);
    $this->assertEquals(0, count($warnings));

    //
    // Test 7
    //
    // If something's different, note and change.
    //
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->get('/lists/dummylistid/webhooks')->shouldBeCalled()->willReturn(
      json_decode(json_encode([
        'http_code' => 200,
        'data' => [
          'webhooks' => [
            [
              'id' => 'dummywebhookid',
              'url' => 'http://example.com', // WRONG
              'events' => [
                'subscribe' => FALSE, // WRONG
                'unsubscribe' => TRUE,
                'profile' => TRUE,
                'cleaned' => TRUE,
                'upemail' => TRUE,
                'campaign' => FALSE,
              ],
              'sources' => [
                'user' => TRUE,
                'admin' => TRUE,
                'api' => TRUE, // WRONG
              ],
            ]
        ]]])));
    $api_prophecy->delete()->shouldNotBeCalled();
    $api_prophecy->post()->shouldNotBeCalled();
    $warnings = CRM_Mailchimp_Utils::configureList(static::$test_list_id, TRUE);
    $this->assertEquals(3, count($warnings));
    $this->assertContains('Need to change webhook URL from http://example.com to', $warnings[0]);
    $this->assertContains('Need to change webhook source api', $warnings[1]);
    $this->assertContains('Need to change webhook event subscribe', $warnings[2]);
  }
  /**
   * @depends testGetMCInterestGroupings
   */
  public function testGuessContactIdSingle() {

    static::createTestContact(static::$civicrm_contact_1);
    static::createTestContact(static::$civicrm_contact_2);
    // Mock the API
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->put();
    $api_prophecy->get();

    //
    // 1. unique email match.
    //
    $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
    $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], static::$civicrm_contact_1['first_name'], static::$civicrm_contact_1['last_name']);
    $this->assertEquals(static::$civicrm_contact_1['contact_id'], $c);

    //
    // 2. email exists twice, but on the same contact
    //
    $second_email = civicrm_api3('Email', 'create', [
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'email' => static::$civicrm_contact_1['email'],
      'is_billing' => 1,
      'sequential' => 1,
      ]);
    $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], static::$civicrm_contact_1['first_name'], static::$civicrm_contact_1['last_name']);
    $this->assertEquals(static::$civicrm_contact_1['contact_id'], $c);

    //
    // 3. email exists multiple times, on multiple contacts
    // but only one contact has the same last name.
    //
    // Give the second email to the 2nd contact.
    $r = civicrm_api3('Email', 'create', [
      'id' => $second_email['id'],
      'contact_id' => static::$civicrm_contact_2['contact_id'],
      'email' => static::$civicrm_contact_1['email'],
      ]);
    $c1 = static::$civicrm_contact_1;
    $c2 = static::$civicrm_contact_2;
    $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], static::$civicrm_contact_1['first_name'], static::$civicrm_contact_1['last_name']);
    $this->assertEquals(static::$civicrm_contact_1['contact_id'], $c);

    //
    // 4. email exists multiple times, on multiple contacts with same last name
    // but only one contact has the same first name.
    //
    // Rename second contact's last name
    $r = civicrm_api3('Contact', 'create', [
      'contact_id' => $c2['contact_id'],
      'last_name'  => $c1['last_name'],
      ]);
    $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], static::$civicrm_contact_1['first_name'], static::$civicrm_contact_1['last_name']);
    $this->assertEquals(static::$civicrm_contact_1['contact_id'], $c);

    //
    // 5. email exists multiple times, on multiple contacts with same last name
    // and first name. Returning *either* contact is OK.
    //
    // Rename second contact's first name
    $r = civicrm_api3('Contact', 'create', [
      'contact_id' => $c2['contact_id'],
      'first_name'  => $c1['first_name'],
      ]);
    $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], static::$civicrm_contact_1['first_name'], static::$civicrm_contact_1['last_name']);
    $this->assertContains($c, [$c1['contact_id'], $c2['contact_id']]);


    //
    // 6. email exists multiple times, on multiple contacts with same last name
    // and first name. But only one contact is in the group.
    //
    $this->joinMembershipGroup($c1);
    $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], static::$civicrm_contact_1['first_name'], static::$civicrm_contact_1['last_name']);
    $this->assertEquals(static::$civicrm_contact_1['contact_id'], $c);

    //
    // 7. email exists multiple times, on multiple contacts with same last name
    // and first name and both contacts on the group.
    //
    $this->joinMembershipGroup($c2);
    $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], static::$civicrm_contact_1['first_name'], static::$civicrm_contact_1['last_name']);
    $this->assertEquals(static::$civicrm_contact_1['contact_id'], $c);

    //
    // 8. email exists multiple times, on multiple contacts with same last name
    // and different first names and both contacts on the group.
    //
    civicrm_api3('Contact', 'create', ['contact_id' => $c2['contact_id'], 'first_name'  => $c2['first_name']]);
    $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], static::$civicrm_contact_1['first_name'], static::$civicrm_contact_1['last_name']);
    $this->assertEquals(static::$civicrm_contact_1['contact_id'], $c);

    //
    // 9. email exists multiple times, on multiple contacts with same last name
    // but there's one contact on the group with the wrong first name and one
    // contact off the group with the right first name.
    //
    // It should go to the contact on the group.
    //
    // Remove contact 1 (has right names) from group, leaving contact 2.
    $this->removeGroup($c1, static::$civicrm_group_id_membership);
    civicrm_api3('Contact', 'create', ['contact_id' => $c2['contact_id'], 'first_name'  => $c2['first_name']]);
    $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], static::$civicrm_contact_1['first_name'], static::$civicrm_contact_1['last_name']);
    $this->assertEquals(static::$civicrm_contact_2['contact_id'], $c);


    //
    // 10. email exists multiple times, on multiple contacts not on the group
    // and none of them has the right last name but one has right first name -
    // should be picked.
    //
    // This is a grudge - we're just going on email and first name, which is not
    // lots, but we really want to avoid not being able to match someone up as
    // then we lose any chance of managing this contact/subscription.
    //
    // Remove contact 2 from group, none now on the group.
    $this->removeGroup($c2, static::$civicrm_group_id_membership);
    $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], static::$civicrm_contact_1['first_name'], 'thisnameiswrong');
    $this->assertEquals(static::$civicrm_contact_1['contact_id'], $c);


    //
    // 10. email exists multiple times, on multiple contacts not on the group
    // and none of them has the right last or first name
    //
    //
    // Remove contact 2 from group, none now on the group.
    try {
      $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], 'wrongfirstname', 'thisnameiswrong');
      $this->fail("Expected a CRM_Mailchimp_DuplicateContactsException to be thrown.");
    }
    catch (CRM_Mailchimp_DuplicateContactsException $e) {}

  }
}
