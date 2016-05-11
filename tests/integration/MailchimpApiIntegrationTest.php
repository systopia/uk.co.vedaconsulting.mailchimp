<?php
/**
 * @file
 * Tests that the systems work together as expected.
 *
 */

require 'integration-test-bootstrap.php';

class MailchimpApiIntegrationTest extends MailchimpApiIntegrationBase {
  /**
   * Connect to API and create test fixtures in Mailchimp and CiviCRM.
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
    $this->assertGreaterThan(0, static::$civicrm_contact_2['contact_id']);

    foreach ([static::$civicrm_contact_1, static::$civicrm_contact_2] as $contact) {
      $this->assertGreaterThan(0, $contact['contact_id']);
      $this->assertNotEmpty($contact['email']);
      $this->assertNotEmpty($contact['subscriber_hash']);
      // Ensure one and only one contact exists with each of our test emails.
      civicrm_api3('Contact', 'getsingle', ['email' => $contact['email']]);
    }
  }

  /**
   * Reset the fixture to the new state.
   *
   * This means neither CiviCRM contact has any group records;
   * Mailchimp test list is empty.
   */
  public function tearDown() {

    // Delete all GroupContact records on our test contacts to test groups.
    // Disable posthook.
    $api = CRM_Mailchimp_Utils::getMailchimpApi();
    $api->setNetworkEnabled(FALSE);
    $contacts = array_filter([static::$civicrm_contact_1, static::$civicrm_contact_2],
      function($_) { return $_['contact_id']>0; });
    foreach ($contacts as $contact) {
      foreach ([static::$civicrm_group_id_membership, static::$civicrm_group_id_interest_1, static::$civicrm_group_id_interest_2] as $group_id) {
        civicrm_api3('GroupContact', 'delete',
          ['contact_id' => $contact['contact_id'], 'group_id' => $group_id]);
        // Ensure name is as it should be as some tests change this.
        civicrm_api3('Contact', 'create', [
          'contact_id' => $contact['contact_id'],
          'first_name' => $contact['first_name'],
          'last_name' =>  $contact['last_name'],
          ]);
      }
    }
    $api->setNetworkEnabled(TRUE);

    // Ensure list is empty.
    $url_prefix = "/lists/$this->list_id/members/";
    foreach ($contacts as $contact) {
      if ($contact['subscriber_hash']) {
        try {
          $api->delete($url_prefix . $contact['subscriber_hash']);
        }
        catch (CRM_Mailchimp_RequestErrorException $e) {
          if (!$e->response || $e->response->http_code != 404) {
            throw $e;
          }
          // Contact not subscribed; fine.
        }
      }
    }
    // Check it really is empty.
    $this->assertEquals(0, $api->get("/lists/$this->list_id", ['fields' => 'stats.member_count'])->data->stats->member_count);
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
   * Fixture at start: as in setUpBeforeClass()
   *
   * Fixture at end: contact_1 subscribed at Mailchimp, named Wilma, no
   * interests.
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
      // Check they are definitely in the group.
      $this->assertContactIsInGroup(static::$civicrm_contact_1['contact_id'], static::$civicrm_group_id_membership);

      // Double-check this member is not known at Mailchimp.
      $this->assertContactNotListMember(static::$civicrm_contact_1);
      $sync = new CRM_Mailchimp_Sync(static::$test_list_id);

      // Now trigger a push for this test list.

      // Collect data from Mailchimp.
      // There shouldn't be any members in this list yet.
      $sync->collectMailchimp('push');
      $this->assertEquals(0, $sync->countMailchimpMembers());

      // Collect data from CiviCRM.
      // There should be one member.
      $sync->collectCiviCrm('push');
      $this->assertEquals(1, $sync->countCiviCrmMembers());

      // There should not be any in sync records.
      $in_sync = $sync->removeInSync();
      $this->assertEquals(0, $in_sync);

      // Check that removals (i.e. someone in Mailchimp but not/no longer in
      // Civi's group) are zero.
      $to_delete = $sync->getEmailsNotInCiviButInMailchimp();
      $this->assertEquals(0, count($to_delete));

      // Run bulk subscribe...
      $sync->updateMailchimpFromCivi();

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
   * Test push updates a record that changed in CiviCRM.
   *
   * 1. Test that a changed name is recognised as needing an update:
   *
   * 2. Test that a changed interest also triggers an update being needed.
   *
   * 3. Test that these changes and adding a new contact are all achieved by a
   *    push operation.
   *
   * Fixture at end:
   * contact 1 renamed to Betty, both interests set.
   * contact 2, Barney, added to list, no interests.
   */
  public function testPushChangedName() {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();
    $this->assertNotEmpty(static::$civicrm_contact_1['contact_id']);

    try {
      // Set up:
      // Add contact 1, Wilma, to the interest group, then the membership group.
      // The post hook should subscribe this person.
      $result = civicrm_api3('GroupContact', 'create', [
        'sequential' => 1,
        'group_id' => static::$civicrm_group_id_membership,
        'contact_id' => static::$civicrm_contact_1['contact_id'],
        'status' => "Added",
      ]);
      //
      // Test 1: is a changed name spotted?
      //
      // Let's change the name of our test record
      civicrm_api3('Contact', 'create', [
        'contact_id' => static::$civicrm_contact_1['contact_id'],
        'first_name' => 'Betty',
        ]);
      $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
      $sync->collectMailchimp('push');
      $this->assertEquals(1, $sync->countMailchimpMembers());
      $sync->collectCiviCrm('push');
      $this->assertEquals(1, $sync->countCiviCrmMembers());
      // As the records are not in sync, none should get deleted.
      $in_sync = $sync->removeInSync();
      $this->assertEquals(0, $in_sync);
      // Now change name back for the mo. (does this trigger a hook?)
      $api->setNetworkEnabled(FALSE);
      // Avoid the hook doing the any updates
      civicrm_api3('Contact', 'create', [
        'contact_id' => static::$civicrm_contact_1['contact_id'],
        'first_name' => static::$civicrm_contact_1['first_name'],
        ]);
      // Test 2: is a changed interest group spotted?
      //
      // Add contact 1 to interest group 1.
      $result = civicrm_api3('GroupContact', 'create', [
        'sequential' => 1,
        'group_id' => static::$civicrm_group_id_interest_1,
        'contact_id' => static::$civicrm_contact_1['contact_id'],
        'status' => "Added",
      ]);
      $api->setNetworkEnabled(TRUE);
      // re-collect the CiviCRM data and check it's still just one record.
      $sync->collectCiviCrm('push');
      $this->assertEquals(1, $sync->countCiviCrmMembers());
      // As the records are not in sync, none should get deleted.
      $in_sync = $sync->removeInSync();
      $this->assertEquals(0, $in_sync);

      //
      // Test 3: Change name back to Betty again, add new contact to membership
      // group and check updates work.
      //

      // Change the name again as this is another thing we can test gets updated
      // correctly.
      civicrm_api3('Contact', 'create', [
        'contact_id' => static::$civicrm_contact_1['contact_id'],
        'first_name' => 'Betty',
        ]);

      // Now collect Civi again.
      $sync->collectCiviCrm('push');
      $this->assertEquals(1, $sync->countCiviCrmMembers());
      // No records in sync, check this.
      $in_sync = $sync->removeInSync();
      $this->assertEquals(0, $in_sync);

      // Send updates to Mailchimp.
      $sync->updateMailchimpFromCivi();

      // Now re-collect from Mailchimp and check all are in sync.
      $sync->collectMailchimp('push');
      $this->assertEquals(1, $sync->countMailchimpMembers());
      // Verify that they are in deed all in sync:
      $in_sync = $sync->removeInSync();
      $this->assertEquals(1, $in_sync);
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
   * Check interests are properly mapped as groups are changed and that
   * collectMailchimp and collectCiviCrm work as expected.
   *
   *
   * This uses the posthook, which in turn uses syncSingleContact.
   *
   * If all is working then at that point both collections should match.
   *
   */
  public function testSyncInterestGroupings() {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();

    try {
      // Add them to the interest group (this should not trigger a Mailchimp
      // update as they are not in thet membership list yet).
      $result = civicrm_api3('GroupContact', 'create', [
        'sequential' => 1,
        'group_id' => static::$civicrm_group_id_interest_1,
        'contact_id' => static::$civicrm_contact_1['contact_id'],
        'status' => "Added",
      ]);
      // The post hook should subscribe this person and set their interests.
      $result = civicrm_api3('GroupContact', 'create', [
        'sequential' => 1,
        'group_id' => static::$civicrm_group_id_membership,
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

      // Now check collections work.
      $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
      $sync->collectMailchimp('push');
      $this->assertEquals(1, $sync->countMailchimpMembers());
      $sync->collectCiviCrm('push');
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
      // xxx
      $this->assertEquals($civi['first_name'], $mc['first_name']);
      $this->assertEquals($civi['last_name'], $mc['last_name']);
      $this->assertEquals($civi['email'], $mc['email']);
      $this->assertEquals($civi['interests'], $mc['interests']);
      $this->assertEquals($civi['hash'], $mc['hash']);

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
   * Test pull updates a records that changed name in Mailchimp.
   *
   * Test that changing name at Mailchimp changes name in CiviCRM.
   * But does not overwrite a CiviCRM name with a blank from Mailchimp.
   *
   * Fixture at start:
   * (webhook not set up for this list.)
   * contact 1 named Betty, both interests set.
   * contact 2, Barney, added to list, no interests.
   * @depends testPushChangedName
   *
   * Fixture at end:
   * contact 1 named Wilma again.
   *
   */
  public function testPullChangesName() {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();
    $this->assertNotEmpty(static::$civicrm_contact_1['contact_id']);

    try {
      // Change the name at Mailchimp, collect and compare
      // data, run updates to Civi, check expectations.

      // Change name at Mailchimp to Wilma (was Betty)
      $this->assertNotEmpty(static::$civicrm_contact_1['subscriber_hash']);
      $result = $api->patch('/lists/' . static::$test_list_id . '/members/' . static::$civicrm_contact_1['subscriber_hash'],
        ['merge_fields' => ['FNAME' => 'Wilma']]);
      $this->assertEquals(200, $result->http_code);

      // Change last name of contact 2 at Mailchimp to blank.
      $result = $api->patch('/lists/' . static::$test_list_id . '/members/' . static::$civicrm_contact_2['subscriber_hash'],
        ['merge_fields' => ['LNAME' => '']]);
      $this->assertEquals(200, $result->http_code);

      // Collect data from Mailchimp and CiviCRM.
      $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
      $sync->collectMailchimp('pull');
      $sync->collectCiviCrm('pull');

      // Remove in-sync things (both have changed, should be zero)
      $in_sync = $sync->removeInSync();
      $this->assertEquals(0, $in_sync);

      // Make changes in Civi.
      $sync->updateCiviFromMailchimp();

      // Ensure expected change was made.
      civicrm_api3('Contact', 'getsingle', [
        'contact_id' => static::$civicrm_contact_1['contact_id'],
        'first_name' => 'Wilma',
        ]);

      // Ensure change was NOT made; contact 2 should still have same surname.
      civicrm_api3('Contact', 'getsingle', [
        'contact_id' => static::$civicrm_contact_2['contact_id'],
        'last_name' => static::$civicrm_contact_2['last_name'],
        ]);

      // Now re-set the surname for contact 2.
      $result = $api->patch('/lists/' . static::$test_list_id . '/members/' . static::$civicrm_contact_2['subscriber_hash'],
        ['merge_fields' => ['LNAME' => static::$civicrm_contact_2['last_name']]]);
      $this->assertEquals(200, $result->http_code);

      $sync->dropTemporaryTables();
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
   * Test new mailchimp contacts added to CiviCRM.
   *
   * Delete contact 1 from CiviCRM, then do a pull.
   * This should result in contact 1 being re-created with all their details.
   *
   * Fixture at start:
   * (webhook not set up for this list.)
   * contact 1 Wilma, both interests set.
   * contact 2, Barney, no interests.
   * @depends testPullChangesName
   *
   * Fixture at end:
   * same.
   *
   */
  public function testPullAddsContact() {

    // Delete contact1 from CiviCRM
    // We have to ensure no post hooks are fired, so we disable the API.
    $api = CRM_Mailchimp_Utils::getMailchimpApi();
    $api->setNetworkEnabled(FALSE);
    $this->assertGreaterThan(0, static::$civicrm_contact_1['contact_id']);

    $result = civicrm_api3('Contact', 'delete', ['id' => static::$civicrm_contact_1['contact_id'], 'skip_undelete' => 1]);
    static::$civicrm_contact_1['contact_id'] = 0;
    $api->setNetworkEnabled(TRUE);

    try {
      // Collect data from Mailchimp and CiviCRM.
      $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
      $sync->collectMailchimp('pull');
      $sync->collectCiviCrm('pull');

      // Remove in-sync things (contact 2 is unchanged)
      $in_sync = $sync->removeInSync();
      $this->assertEquals(1, $in_sync);

      // Make changes in Civi.
      $sync->updateCiviFromMailchimp();

      // Ensure expected change was made.
      $result = civicrm_api3('Contact', 'getsingle', [
        'email' => static::$civicrm_contact_1['email'],
        'first_name' => static::$civicrm_contact_1['first_name'],
        'last_name' => static::$civicrm_contact_1['last_name'],
        ]);
      if ($result['contact_id']) {
        // Fix the fixture.
        static::$civicrm_contact_1['contact_id'] = (int) $result['contact_id'];
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
   * Test unsibscribed/missing mailchimp contacts are removed from CiviCRM
   * membership group.
   *
   * Update contact 1 at mailchimp to unsubscribed.
   * Delete contact 2 at mailchimp.
   * Run pull.
   * Both contacts should be 'removed' from CiviCRM group.
   *
   * Fixture at start:
   * (webhook not set up for this list.)
   * contact 1 Wilma, both interests set.
   * contact 2, Barney, no interests.
   * @depends testPullChangesName
   *
   * Fixture at end:
   * same.
   *
   */
  public function testPullRemovesContacts() {

    try {
      // Update contact 1 at Mailchimp to unsubscribed.
      $api = CRM_Mailchimp_Utils::getMailchimpApi();
      $this->assertGreaterThan(0, static::$civicrm_contact_1['contact_id']);
      $result = $api->patch('/lists/' . static::$test_list_id . '/members/' . static::$civicrm_contact_1['subscriber_hash'],
          ['status' => 'unsubscribed']);
      $this->assertEquals(200, $result->http_code);

      // Delete contact 2 from Mailchimp completely.
      $result = $api->delete('/lists/' . static::$test_list_id . '/members/' . static::$civicrm_contact_2['subscriber_hash']);
      $this->assertEquals(204, $result->http_code);

      // Collect data from Mailchimp and CiviCRM.
      $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
      // Nothing should be subscribed at Mailchimp.
      $sync->collectMailchimp('pull');
      $this->assertEquals(0, $sync->countMailchimpMembers());
      // Both contacts should still be subscribed according to CiviCRM.
      $sync->collectCiviCrm('pull');
      $this->assertEquals(2, $sync->countCiviCrmMembers());

      // Remove in-sync things (nothing is in sync)
      $in_sync = $sync->removeInSync();
      $this->assertEquals(0, $in_sync);

      // Make changes in Civi.
      $sync->updateCiviFromMailchimp();

      // Each contact should now be removed from the group.
      $this->assertContactIsNotInGroup(static::$civicrm_contact_1['contact_id'], static::$civicrm_group_id_membership);
      $this->assertContactIsNotInGroup(static::$civicrm_contact_2['contact_id'], static::$civicrm_group_id_membership);

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
    $sync->collectMailchimp('push');
    $this->assertEquals(0, $sync->countMailchimpMembers());

    // Collect data from CiviCRM.
    // There should be one member.
    $sync->collectCiviCrm('push');
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
    $sync->collectMailchimp('push');
    $this->assertEquals(0, $sync->countMailchimpMembers());

    // Collect data from CiviCRM.
    // There should be one member.
    $sync->collectCiviCrm('push');
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
   * Check that the contact's email is a member in given state on Mailchimp.
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
   * Check that the contact's email is not a member of the test list at
   * Mailchimp.
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
  /**
   * Assert that a contact exists in the given CiviCRM group.
   */
  public function assertContactIsInGroup($contact_id, $group_id) {
    $result = civicrm_api3('Contact', 'getsingle', ['group' => $this->membership_group_id, 'id' => $contact_id]);
    $this->assertEquals($contact_id, $result['contact_id']);
  }
  /**
   * Assert that a contact does not exist in the given CiviCRM group.
   */
  public function assertContactIsNotInGroup($contact_id, $group_id, $msg=NULL) {

    // Fetching the contact should work.
    $result = civicrm_api3('Contact', 'getsingle', ['id' => $contact_id]);
    try {
      // ...But not if we filter for this group.
      $result = civicrm_api3('Contact', 'getsingle', ['group' => $group_id, 'id' => $contact_id]);
      if ($msg === NULL) {
        $msg = "Contact '$contact_id' should not be in group '$group_id', but is.";
      }
      $this->fail($msg);
    }
    catch (CiviCRM_API3_Exception $e) {
      $x=1;
    }
  }
}

//
// test that collect Civi collects right interests data.
// test that collect Mailchimp collects right interests data.
//
// test that push does interests correctly.
// test when mc has unmapped interests that they are not affected by our code.
