<?php
/**
 * @file
 * This class holds all the sync logic for a particular list.
 */

class CRM_Mailchimp_Sync {
  protected $list_id;
  /**
   * Cache of details from CRM_Mailchimp_Utils::getGroupsToSync.
   ▾ $this->group_details['61'] = (array [12])
     ⬦ $this->group_details['61']['list_id'] = (string [10]) `4882f4fdb8`
     ⬦ $this->group_details['61']['category_id'] = (null)
     ⬦ $this->group_details['61']['category_name'] = (null)
     ⬦ $this->group_details['61']['interest_id'] = (null)
     ⬦ $this->group_details['61']['interest_name'] = (null)
     ⬦ $this->group_details['61']['is_mc_update_grouping'] = (string [1]) `0`
     ⬦ $this->group_details['61']['civigroup_title'] = (string [28]) `mailchimp_integration_test_1`
     ⬦ $this->group_details['61']['civigroup_uses_cache'] = (bool) 0
     ⬦ $this->group_details['61']['grouping_id'] = (null)
     ⬦ $this->group_details['61']['grouping_name'] = (null)
     ⬦ $this->group_details['61']['group_id'] = (null)
     ⬦ $this->group_details['61']['group_name'] = (null)
   */
  protected $group_details;
  /**
   * As above but without membership group.
   */
  protected $interest_group_details;
  /**
   * The CiviCRM group id responsible for membership at Mailchimp.
   */
  protected $membership_group_id;

  public function __construct($list_id) {
    $this->list_id = $list_id;
    $this->group_details = CRM_Mailchimp_Utils::getGroupsToSync($groupIDs=[], $list_id, $membership_only=FALSE);
    foreach ($this->group_details as $group_id => $group_details) {
      if (empty($group_details['category_id'])) {
        $this->membership_group_id = $group_id;
      }
    }
    // Also cache without the membership group, i.e. interest groups only.
    $this->interest_group_details = $this->group_details;
    unset($this->interest_group_details[$this->membership_group_id]);
  }
  /**
   * Collect Mailchimp data into temporary working table.
   */
  public function collectMailchimp() {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncCollectMailchimp $this->list_id= ', $this->list_id);
    // Create a temporary table.
    // Nb. these are temporary tables but we don't use TEMPORARY table because
    // they are needed over multiple sessions because of queue.

    CRM_Core_DAO::executeQuery( "DROP TABLE IF EXISTS tmp_mailchimp_push_m;");
    $dao = CRM_Core_DAO::executeQuery(
      "CREATE TABLE tmp_mailchimp_push_m (
        email VARCHAR(200) NOT NULL,
        first_name VARCHAR(100) NOT NULL,
        last_name VARCHAR(100) NOT NULL,
        hash CHAR(32) NOT NULL,
        interests VARCHAR(4096) NOT NULL,
        cid_guess INT(10),
        PRIMARY KEY (email, hash),
        KEY (cid_guess))
        ENGINE=InnoDB DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ;");

    // Cheekily access the database directly to obtain a prepared statement.
    $db = $dao->getDatabaseConnection();
    $insert = $db->prepare('INSERT INTO tmp_mailchimp_push_m
             (email, first_name, last_name, hash, interests)
      VALUES (?,     ?,          ?,         ?,    ?)');

    // We need to know what grouping data we care about. The rest we completely ignore.
    // We only care about CiviCRM groups that are mapped to this MC List.
    $mapped_groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), $this->list_id);

    CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Form_Sync syncCollectMailchimp $mapped_groups', $mapped_groups);

    $api = CRM_Mailchimp_Utils::getMailchimpApi();
    $offset = 0;
    $batch_size = 1000;
    $total = null;
    $list_id = $this->list_id;
    $fetch_batch = function() use($api, &$offset, &$total, $batch_size, $list_id) {
      if ($total !== null && $offset >= $total) {
        // End of results.
        return [];
      }
      $response = $api->get("/lists/$this->list_id/members", [
        'offset' => $offset, 'count' => $batch_size,
        'status' => 'subscribed',
        'fields' => 'total_items,members.email_address,members.merge_fields,members.interests',
      ]);
      $total = (int) $response->data->total_items;
      $offset += $batch_size;
      return $response->data->members;
    };

    //
    // Main loop of all the records.
    while ($members = $fetch_batch()) {
      foreach ($members as $member) {
        $first_name = isset($member->merge_fields->FNAME) ? $member->merge_fields->FNAME : '';
        $last_name  = isset($member->merge_fields->LNAME) ? $member->merge_fields->LNAME : '';
        // Find out which of our mapped groups apply to this subscriber.
        // Serialize the grouping array for SQL storage - this is the fastest way.
        $interests = serialize($this->getComparableInterestsFromMailchimp($member->interests));

        // we're ready to store this but we need a hash that contains all the info
        // for comparison with the hash created from the CiviCRM data (elsewhere).
        $hash = md5($member->email_address . $first_name . $last_name . $interests);
        // run insert prepared statement
        $result = $db->execute($insert, [
          $member->email_address,
          $first_name,
          $last_name,
          $hash,
          $interests,
        ]);
        if ($result instanceof DB_Error) {
          throw new Exception ($result->message . "\n" . $result->userinfo);
        }
      }
    }

    // Tidy up.
    fclose($handle);
    $db->freePrepared($insert);

    // Guess the contact ID's, to speed up syncPullUpdates (See issue #188).
    CRM_Mailchimp_Utils::guessCidsMailchimpContacts();
  }

  /**
   * Collect CiviCRM data into temporary working table.
   */
  public function collectCiviCrm() {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncCollectCiviCRM $this->list_id= ', $this->list_id);
    // Nb. these are temporary tables but we don't use TEMPORARY table because they are
    // needed over multiple sessions because of queue.
    CRM_Core_DAO::executeQuery( "DROP TABLE IF EXISTS tmp_mailchimp_push_c;");
    $dao = CRM_Core_DAO::executeQuery("CREATE TABLE tmp_mailchimp_push_c (
        contact_id INT(10) UNSIGNED NOT NULL,
        email_id INT(10) UNSIGNED NOT NULL,
        email VARCHAR(200) NOT NULL,
        first_name VARCHAR(100) NOT NULL,
        last_name VARCHAR(100) NOT NULL,
        hash CHAR(32) NOT NULL,
        interests VARCHAR(4096) NOT NULL,
        PRIMARY KEY (email_id, email, hash))
        ENGINE=InnoDB DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ;");
    // Cheekily access the database directly to obtain a prepared statement.
    $db = $dao->getDatabaseConnection();
    $insert = $db->prepare('INSERT INTO tmp_mailchimp_push_c VALUES(?, ?, ?, ?, ?, ?, ?)');

    //create table for mailchim civicrm syn errors
    $dao = CRM_Core_DAO::executeQuery("CREATE TABLE IF NOT EXISTS mailchimp_civicrm_syn_errors (
        id int(11) NOT NULL AUTO_INCREMENT,
        email VARCHAR(200),
        error VARCHAR(200),
        error_count int(10),
        group_id int(20),
        list_id VARCHAR(20),
        PRIMARY KEY (id)
        );");

    // We need to know what interests we have maps to.
    // We only care about CiviCRM groups that are mapped to this MC List:
    $mapped_groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), $this->list_id);

    // First, get all subscribers from the membership group for this list.
    // ... Find CiviCRM group id for the membership group.
    // ... And while we're at it, build an SQL-safe array of groupIds for groups
    //     mapped to groupings. (we use that later)
    $membership_group_id = FALSE;
    // There used to be a distinction between the handling of 'normal' groups
    // and smart groups. But now the API will take care of this.
    $grouping_group_ids = array();
    foreach ($mapped_groups as $group_id => $details) {
      CRM_Contact_BAO_GroupContactCache::loadAll($group_id);
      if (!$details['category_id']) {
        $membership_group_id = $group_id;
      }
      else {
        $interest_group_ids[] = (int) $group_id;
      }
    }
    if (!$membership_group_id) {
      throw new Exception("No CiviCRM group is mapped to determine membership of Mailchimp list $this->list_id");
    }
    // Use a nice API call to get the information for tmp_mailchimp_push_c.
    // The API will take care of smart groups.
    $result = civicrm_api3('Contact', 'get', array(
      'is_deleted' => 0,
      // The email filter below does not work (CRM-18147)
      // 'email' => array('IS NOT NULL' => 1),
      // Now I think that on_hold is NULL when there is no e-mail, so if
      // we are lucky, the filter below implies that an e-mail address
      // exists ;-)
      'on_hold' => 0,
      'is_opt_out' => 0,
      'do_not_email' => 0,
      'group' => $membership_group_id,
      'return' => array('first_name', 'last_name', 'email_id', 'email', 'group'),
      'options' => array('limit' => 0),
    ));

    // Loop contacts:
    foreach ($result['values'] as $contact) {
      // Find out the ID's of the groups the $contact belongs to, and
      // save in $info.
      $info = $this->getComparableInterestsFromCiviCrmGroups($contact['groups']);

      // OK we should now have all the info we need.
      // Serialize the grouping array for SQL storage - this is the fastest way.
      $info = serialize($info);

      // we're ready to store this but we need a hash that contains all the info
      // for comparison with the hash created from the CiviCRM data (elsewhere).
      //          email,           first name,      last name,      groupings
      $hash = md5($contact['email'] . $contact['first_name'] . $contact['last_name'] . $info);
      // run insert prepared statement
      $db->execute($insert, array($contact['id'], $contact['email_id'], $contact['email'], $contact['first_name'], $contact['last_name'], $hash, $info));
    }

    // Tidy up.
    $db->freePrepared($insert);
  }
  /**
   * Convert a 'groups' string as provided by CiviCRM's API to a structured
   * array of arrays whose keys are Mailchimp interest ids and whos value is
   * boolean.
   *
   * Nb. this is then key-sorted, which results in a standardised array for
   * comparison.
   *
   * @param string $groups as returned by CiviCRM's API.
   * @return array of interest_ids to booleans.
   */
  public function getComparableInterestsFromCiviCrmGroups($groups) {
    $civi_groups = CRM_Mailchimp_Utils::splitGroupTitles($groups, $this->interest_group_details);
    foreach ($this->interest_group_details as $civi_group_id => $details) {
      $info[$details['interest_id']] = in_array($civi_group_id, $civi_groups);
    }
    ksort($info);
    return $info;
  }

  /**
   * Convert interests object received from the Mailchimp API into
   * a structure identical to that produced by
   * getComparableInterestsFromCiviCrmGroups.
   *
   * Note this will only return information about interests mapped in CiviCRM.
   * Any other interests that may have been created on Mailchimp are not
   * included here.
   *
   * @param object $interests 'interests' as returned by GET
   * /list/.../members/...?fields=interests
   */
  public function getComparableInterestsFromMailchimp($interests) {
    $info = [];
    foreach ($this->interest_group_details as $details) {
      $info[$details['interest_id']] = !empty($interests->{$details['interest_id']});
    }
    ksort($info);
    return $info;
  }

  /**
   * Subscribes the contents of the tmp_mailchimp_push_c table.
   */
  public function addFromCiviCrm() {

    $operations = [];
    $dao = CRM_Core_DAO::executeQuery( "SELECT * FROM tmp_mailchimp_push_c;");
    $url_prefix = "/lists/$this->list_id/members/";
    while ($dao->fetch()) {

      // Gather interests. @todo
      $op = [
        'PUT',
        $url_prefix . md5(strtolower($dao->email)),
        [
          'status' => 'subscribed',
          'email_address' => $dao->email,
          'interests' => unserialize($dao->interests),
          'merge_fields' => [
            'FNAME' => $dao->first_name,
            'LNAME' => $dao->last_name,
          ],
        ]
      ];

      $operations []= $op;
    }

    $api = CRM_Mailchimp_Utils::getMailchimpApi();
    $result = $api->batchAndWait($operations);
    // print "Batch results: " . $result->data->response_body_url . "\n";
  }

  /**
   * Get list of emails to unsubscribe.
   *
   * @return array
   */
  public function getEmailsNotInCiviButInMailchimp() {
    $dao = CRM_Core_DAO::executeQuery(
      "SELECT m.email
       FROM tmp_mailchimp_push_m m
       WHERE NOT EXISTS (
         SELECT email FROM tmp_mailchimp_push_c c WHERE c.email = m.email
       );");

    $emails = [];
    while ($dao->fetch()) {
      $batch[] = $dao->email;
    }
    return $emails;
  }
  /**
   * Removes from the temporary tables those records that do not need processing.
   *
   * @return int
   */
  public function removeInSync() {
    // Delete records have the same hash - these do not need an update.
    // count for testing purposes.
    $dao = CRM_Core_DAO::executeQuery("SELECT COUNT(c.email) co FROM tmp_mailchimp_push_m m
      INNER JOIN tmp_mailchimp_push_c c ON m.email = c.email AND m.hash = c.hash;");
    $dao->fetch();
    $count = $dao->co;
    if ($count > 0) {
      CRM_Core_DAO::executeQuery(
        "DELETE m, c
         FROM tmp_mailchimp_push_m m
         INNER JOIN tmp_mailchimp_push_c c ON m.email = c.email AND m.hash = c.hash;");
    }
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Form_Sync syncIdentical $count= ', $count);
    return $count;
  }
  /**
   * Return a count of the members on Mailchimp from the tmp_mailchimp_push_m
   * table.
   */
  public function countMailchimpMembers() {
    $dao = CRM_Core_DAO::executeQuery("SELECT COUNT(*) c  FROM tmp_mailchimp_push_m");
    $dao->fetch();
    return $dao->c;
  }

  /**
   * Return a count of the members on CiviCRM from the tmp_mailchimp_push_c
   * table.
   */
  public function countCiviCrmMembers() {
    $dao = CRM_Core_DAO::executeQuery("SELECT COUNT(*) c  FROM tmp_mailchimp_push_c");
    $dao->fetch();
    return $dao->c;
  }

  /**
   * Sync a single contact's membership and interests for this list from their
   * details in CiviCRM.
   */
  public function syncSingleContact($contact_id) {

    // Get all the groups related to this list that the contact is currently in.
    // We have to use this dodgy API that concatenates the titles of the groups
    // with a comma (making it unsplittable if a group title has a comma in it).
    $contact = civicrm_api3('Contact', 'getsingle', [
      'contact_id' => $contact_id,
      'return' => ['first_name', 'last_name', 'email_id', 'email', 'group'],
      'sequential' => 1
      ]);

    $in_groups = CRM_Mailchimp_Utils::splitGroupTitles($contact['groups'], $this->group_details);
    $currently_a_member = in_array($this->membership_group_id, $in_groups);

    if (empty($contact['email'])) {
      // Without an email we can't do anything.
      return;
    }
    $subscriber_hash = md5(strtolower($contact['email']));
    $api = CRM_Mailchimp_Utils::getMailchimpApi();

    if (!$currently_a_member) {
      // They are not currently a member.
      //
      // We should ensure they are unsubscribed from Mailchimp. They might
      // already be, but as we have no way of telling exactly what just changed
      // at our end, we have to make sure.
      //
      // Nb. we don't bother updating their interests for unsubscribes.
      $result = $api->patch("/lists/$this->list_id/members/$subscriber_hash",
        ['status' => 'unsubscribed']);
      return;
    }

    // Now left with 'subscribe' case.
    //
    // Do this with a PUT as this allows for both updating existing and
    // creating new members.
    $data = [
      'status' => 'subscribed',
      'email_address' => $contact['email'],
      'merge_fields' => [
        'fname' => $contact['first_name'],
        'lname' => $contact['last_name'],
        ],
    ];
    // Do interest groups.
    $data['interests'] = $this->getComparableInterestsFromCiviCrmGroups($contact['groups']);
    $result = $api->put("/lists/$this->list_id/members/$subscriber_hash", $data);
  }
}

