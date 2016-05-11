<?php
/**
 * @file
 * These tests do not depend on the Mailchimp API.
 *
 * It does not depend on a live Mailchimp account. However it is not a unit test
 * because it does depend on and make changes to the CiviCRM database.
 *
 */
require 'integration-test-bootstrap.php';

class SyncIntegrationTest extends MailchimpApiIntegrationBase {

  public static function setUpBeforeClass() {
  }
  /**
   *
   */
  public static function tearDownAfterClass() {
    static::tearDownCiviCrmFixtures();
  }

  /**
   * Tests the guessContactIdsBySubscribers method.
   *
   */
  public function testGuessContactIdsBySubscribers() {
    // Create empty tables.
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
    CRM_Mailchimp_Sync::createTemporaryTableForCiviCRM();

    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_c (email, contact_id) VALUES
      ('found@example.com', 1),
      ('red-herring@example.com', 2);");
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email) VALUES
      ('found@example.com'),
      ('notfound@example.com');");
    CRM_Mailchimp_Sync::guessContactIdsBySubscribers();

    // Check the matched record did indeed match.
    $dao = CRM_Core_DAO::executeQuery('SELECT COUNT(*) c FROM tmp_mailchimp_push_m WHERE email = "found@example.com" AND cid_guess = 1');
    $dao->fetch();
    $this->assertEquals(1, $dao->c);

    // Check the other one did not.
    $dao = CRM_Core_DAO::executeQuery('SELECT COUNT(*) c FROM tmp_mailchimp_push_m WHERE email = "notfound@example.com" AND cid_guess IS NULL');
    $dao->fetch();
    $this->assertEquals(1, $dao->c);

    CRM_Mailchimp_Sync::dropTemporaryTables();
  }
  /**
   * Tests the guessContactIdsByUniqueEmail method.
   *
   */
  public function testGuessContactIdsByUniqueEmail() {
    // Create empty tables.
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
    // Create test contact.
    static::createTestContact(static::$civicrm_contact_1);

    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email) VALUES (%1), (%2);",[
      1 => [static::$civicrm_contact_1['email'], 'String'],
      2 => ['notfound@example.com', 'String'],
    ]);
    CRM_Mailchimp_Sync::guessContactIdsByUniqueEmail();

    // Check the matched record did indeed match.
    $dao = CRM_Core_DAO::executeQuery('SELECT COUNT(*) c FROM tmp_mailchimp_push_m WHERE email = %1 AND cid_guess = ' . static::$civicrm_contact_1['contact_id'],[
      1 => [static::$civicrm_contact_1['email'], 'String'],
    ]);
    $dao->fetch();
    $this->assertEquals(1, $dao->c);

    // Check the other one did not.
    $dao = CRM_Core_DAO::executeQuery('SELECT COUNT(*) c FROM tmp_mailchimp_push_m WHERE email = "notfound@example.com" AND cid_guess IS NULL');
    $dao->fetch();
    $this->assertEquals(1, $dao->c);

    CRM_Mailchimp_Sync::dropTemporaryTables();
  }
}
