<?php
/**
 * @file
 * Tests of CRM_Mailchimp_Sync methods that do not need the Mailchimp API.
 *
 * It does not depend on a live Mailchimp account and nor does it need a mock
 * mailchimp api object - these methods don't use the Mailchimp API anyway.
 * However it is not a unit test because it does depend on and make changes to
 * the CiviCRM database.
 *
 * The CRM_Mailchimp_Sync class is also tested in:
 * - MailchimpApiIntegrationMockTest
 * - MailchimpApiIntegrationTest
 *
 */
require 'integration-test-bootstrap.php';

class SyncIntegrationTest extends MailchimpApiIntegrationBase {

  public static function setUpBeforeClass() {
  }
  /**
   *
   */
  public function tearDown() {
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
    // Create test contact.
    static::createTestContact(static::$civicrm_contact_1);
    static::createTestContact(static::$civicrm_contact_2);

    //
    // Test 1: Primary case: match a unique email.
    //
    // Create empty tables.
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
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

    //
    // Test 2: Secondary case: match an email unique to one person.
    //
    // Start again, this time the email will be unique to a contact, but not
    // unique in the email table, e.g. it's in twice, but for the same contact.
    //
    civicrm_api3('Email', 'create', [
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'email' => static::$civicrm_contact_1['email'],
      'is_billing' => 1,
      ]);
    CRM_Mailchimp_Sync::dropTemporaryTables();
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email) VALUES (%1);",[
      1 => [static::$civicrm_contact_1['email'], 'String'],
    ]);
    CRM_Mailchimp_Sync::guessContactIdsByUniqueEmail();
    // Check the matched record did indeed match.
    $dao = CRM_Core_DAO::executeQuery('SELECT COUNT(*) c FROM tmp_mailchimp_push_m WHERE email = %1 AND cid_guess = ' . static::$civicrm_contact_1['contact_id'],[
      1 => [static::$civicrm_contact_1['email'], 'String'],
    ]);
    $dao->fetch();
    $this->assertEquals(1, $dao->c);

    //
    // Test 3: Primary negative case: if an email is owned by 2 different
    // contacts, we cannot match it.
    //
    static::tearDownCiviCrmFixtures();
    static::createTestContact(static::$civicrm_contact_1);
    static::createTestContact(static::$civicrm_contact_2);
    civicrm_api3('Email', 'create', [
      'contact_id' => static::$civicrm_contact_2['contact_id'],
      'email' => static::$civicrm_contact_1['email'],
      'is_billing' => 1,
      ]);
    CRM_Mailchimp_Sync::dropTemporaryTables();
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email) VALUES (%1);",[
      1 => [static::$civicrm_contact_1['email'], 'String'],
    ]);
    CRM_Mailchimp_Sync::guessContactIdsByUniqueEmail();
    // Check no match.
    $dao = CRM_Core_DAO::executeQuery('SELECT COUNT(*) c FROM tmp_mailchimp_push_m WHERE email = %1 AND cid_guess IS NULL', [
      1 => [static::$civicrm_contact_1['email'], 'String'],
    ]);
    $dao->fetch();
    $this->assertEquals(1, $dao->c);

  }
  /**
   * Tests the guessContactIdsByNameAndEmail method.
   *
   */
  public function testGuessContactIdsByNameAndEmail() {
    // Create test contacts
    static::createTestContact(static::$civicrm_contact_1);
    static::createTestContact(static::$civicrm_contact_2);

    //
    // Test 1: Primary case: match on name, email when they only match one
    // contact.
    //
    // Create empty tables.
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email, first_name, last_name)
      VALUES (%1, %2, %3);",[
      1 => [static::$civicrm_contact_1['email'], 'String'],
      2 => [static::$civicrm_contact_1['first_name'], 'String'],
      3 => [static::$civicrm_contact_1['last_name'], 'String'],
    ]);
    CRM_Mailchimp_Sync::guessContactIdsByNameAndEmail();
    // Check the matched record did indeed match.
    $dao = CRM_Core_DAO::executeQuery('SELECT COUNT(*) c FROM tmp_mailchimp_push_m WHERE email = %1 AND cid_guess = ' . static::$civicrm_contact_1['contact_id'],[
      1 => [static::$civicrm_contact_1['email'], 'String'],
    ]);
    $dao->fetch();
    $this->assertEquals(1, $dao->c);

    //
    // Test 2: Check this still works if contact 2 shares the email address (but
    // has a different name)
    //
    civicrm_api3('Email', 'create', [
      'contact_id' => static::$civicrm_contact_2['contact_id'],
      'email' => static::$civicrm_contact_1['email'],
      'is_billing' => 1,
      ]);
    CRM_Mailchimp_Sync::dropTemporaryTables();
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email, first_name, last_name)
      VALUES (%1, %2, %3);",[
      1 => [static::$civicrm_contact_1['email'], 'String'],
      2 => [static::$civicrm_contact_1['first_name'], 'String'],
      3 => [static::$civicrm_contact_1['last_name'], 'String'],
    ]);
    CRM_Mailchimp_Sync::guessContactIdsByNameAndEmail();
    // Check the matched record did NOT match.
    $dao = CRM_Core_DAO::executeQuery('SELECT COUNT(*) c FROM tmp_mailchimp_push_m WHERE email = %1 AND cid_guess = ' . static::$civicrm_contact_1['contact_id'],[
      1 => [static::$civicrm_contact_1['email'], 'String'],
    ]);
    $dao->fetch();
    $this->assertEquals(1, $dao->c);

    //
    // Test 2: Check that if there's 2 matches, we fail to guess.
    // Give Contact2 the same email and name as contact 1
    //
    civicrm_api3('Contact', 'create', [
      'contact_id' => static::$civicrm_contact_2['contact_id'],
      'first_name' => static::$civicrm_contact_1['first_name'],
      'last_name'  => static::$civicrm_contact_1['last_name'],
      ]);
    CRM_Mailchimp_Sync::dropTemporaryTables();
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email, first_name, last_name)
      VALUES (%1, %2, %3);",[
      1 => [static::$civicrm_contact_1['email'], 'String'],
      2 => [static::$civicrm_contact_1['first_name'], 'String'],
      3 => [static::$civicrm_contact_1['last_name'], 'String'],
    ]);
    CRM_Mailchimp_Sync::guessContactIdsByNameAndEmail();
    // Check the matched record did NOT match.
    $dao = CRM_Core_DAO::executeQuery('SELECT COUNT(*) c FROM tmp_mailchimp_push_m WHERE email = %1 AND cid_guess IS NULL;',[
      1 => [static::$civicrm_contact_1['email'], 'String'],
    ]);
    $dao->fetch();
    $this->assertEquals(1, $dao->c);

  }
}
