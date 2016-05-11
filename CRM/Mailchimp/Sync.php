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
   *
   * There are two modes of operation:
   *
   * In **pull** mode we only collect data that comes from Mailchimp that we are
   * allowed to update in CiviCRM.
   *
   * In **push** mode we collect data that we would update in Mailchimp from
   * CiviCRM.
   *
   * Crucially the difference is for CiviCRM groups mapped to a Mailchimp
   * interest: these can either allow updates *from* Mailchimp or not. Typical
   * use case is a hidden-from-subscriber 'interest' called 'donor type' which
   * might include 'major donor' and 'minor donor' based on some valuation by
   * the organisation recorded in CiviCRM groups.
   *
   * @param string $mode pull|push.
   */
  public function collectMailchimp($mode) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncCollectMailchimp $this->list_id= ', $this->list_id);
    if (!in_array($mode, ['pull', 'push'])) {
      throw new InvalidArgumentException(__FUNCTION__ . " expects push/pull but called with '$mode'.");
    }
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

    CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Form_Sync syncCollectMailchimp: ', $this->interest_group_details);

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
        $interests = serialize($this->getComparableInterestsFromMailchimp($member->interests, $mode));

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

    // If an address is unique, that's the one we need.
    CRM_Core_DAO::executeQuery(
        "UPDATE tmp_mailchimp_push_m m
          JOIN civicrm_email e1 ON m.email = e1.email
          LEFT OUTER JOIN civicrm_email e2 ON m.email = e2.email AND e1.id <> e2.id
          SET m.cid_guess = e1.contact_id
          WHERE e2.id IS NULL")->free();
    // In the other case, if we find a unique contact with matching
    // first name, last name and e-mail address, it is probably the one we
    // are looking for as well.
    CRM_Core_DAO::executeQuery(
       "UPDATE tmp_mailchimp_push_m m
          JOIN civicrm_email e1 ON m.email = e1.email
          JOIN civicrm_contact c1 ON e1.contact_id = c1.id AND c1.first_name = m.first_name AND c1.last_name = m.last_name 
          LEFT OUTER JOIN civicrm_email e2 ON m.email = e2.email
          LEFT OUTER JOIN civicrm_contact c2 on e2.contact_id = c2.id AND c2.first_name = m.first_name AND c2.last_name = m.last_name AND c2.id <> c1.id
          SET m.cid_guess = e1.contact_id
          WHERE m.cid_guess IS NULL AND c2.id IS NULL")->free();
  }

  /**
   * Collect CiviCRM data into temporary working table.
   *
   @todo tidy this - mapped groups sould use interest_groups
   * @param string $mode pull|push.
   */
  public function collectCiviCrm($mode) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncCollectCiviCRM $this->list_id= ', $this->list_id);
    if (!in_array($mode, ['pull', 'push'])) {
      throw new InvalidArgumentException(__FUNCTION__ . " expects push/pull but called with '$mode'.");
    }
    // Nb. these are temporary tables but we don't use TEMPORARY table because
    // they are needed over multiple sessions because of queue.
    CRM_Core_DAO::executeQuery( "DROP TABLE IF EXISTS tmp_mailchimp_push_c;");
    $dao = CRM_Core_DAO::executeQuery("CREATE TABLE tmp_mailchimp_push_c (
        contact_id INT(10) UNSIGNED NOT NULL,
        email VARCHAR(200) NOT NULL,
        first_name VARCHAR(100) NOT NULL,
        last_name VARCHAR(100) NOT NULL,
        hash CHAR(32) NOT NULL,
        interests VARCHAR(4096) NOT NULL,
        PRIMARY KEY (email, hash)
        )
        ENGINE=InnoDB DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ;");
    // Cheekily access the database directly to obtain a prepared statement.
    $db = $dao->getDatabaseConnection();
    $insert = $db->prepare('INSERT INTO tmp_mailchimp_push_c VALUES(?, ?, ?, ?, ?, ?)');

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

    // There used to be a distinction between the handling of 'normal' groups
    // and smart groups. But now the API will take care of this but this
    // requires the following function to have run.
    foreach ($this->interest_group_details as $group_id => $details) {
      if ($mode == 'push' || $details['is_mc_update_grouping'] == 1) {
        // Either we are collecting for a push from C->M,
        // or we're pulling and this group is configured to allow updates.
        // Therefore we need to make sure the cache is filled.
        CRM_Contact_BAO_GroupContactCache::loadAll($group_id);
      }
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
      'group' => $this->membership_group_id,
      'return' => array('first_name', 'last_name', 'email_id', 'email', 'group'),
      'options' => array('limit' => 0),
    ));

    // Loop contacts:
    foreach ($result['values'] as $contact) {
      // Find out the ID's of the groups the $contact belongs to, and
      // save in $info.
      $info = $this->getComparableInterestsFromCiviCrmGroups($contact['groups'], $mode);

      // OK we should now have all the info we need.
      // Serialize the grouping array for SQL storage - this is the fastest way.
      $info = serialize($info);

      // we're ready to store this but we need a hash that contains all the info
      // for comparison with the hash created from the CiviCRM data (elsewhere).
      //          email,           first name,      last name,      groupings
      $hash = md5($contact['email'] . $contact['first_name'] . $contact['last_name'] . $info);
      // run insert prepared statement
      $db->execute($insert, array($contact['id'], $contact['email'], $contact['first_name'], $contact['last_name'], $hash, $info));
    }

    // Tidy up.
    $db->freePrepared($insert);
  }
  /**
   * Drop tmp_mailchimp_push_m and tmp_mailchimp_push_c, if they exist.
   *
   * Those tables are created by collectMailchimp() and collectCiviCrm()
   * for the purposes of syncing to/from Mailchimp/CiviCRM and are not needed
   * outside of those operations.
   */
  public function dropTemporaryTables() {
    CRM_Core_DAO::executeQuery("DROP TABLE IF EXISTS tmp_mailchimp_push_m;");
    CRM_Core_DAO::executeQuery("DROP TABLE IF EXISTS tmp_mailchimp_push_c;");
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
   * @param string $mode pull|push.
   * @return array of interest_ids to booleans.
   */
  public function getComparableInterestsFromCiviCrmGroups($groups, $mode) {
    $civi_groups = CRM_Mailchimp_Utils::splitGroupTitles($groups, $this->interest_group_details);
    $info = [];
    foreach ($this->interest_group_details as $civi_group_id => $details) {
      if ($mode == 'pull' && $details['is_mc_update_grouping'] != 1) {
        // This group is configured to disallow updates from Mailchimp to
        // CiviCRM.
        continue;
      }
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
   * @param string $mode pull|push.
   */
  public function getComparableInterestsFromMailchimp($interests, $mode) {
    $info = [];
    // If pulling data from Mailchimp to CiviCRM we ignore any changes to
    // interests where such changes are disallowed by configuration.
    $ignore_non_updatables = $mode == 'pull';
    foreach ($this->interest_group_details as $details) {
      if ($ignore_non_updatables && $details['is_mc_update_grouping'] != 1) {
        // This group is configured to disallow updates from Mailchimp to
        // CiviCRM.
        continue;
      }
      $info[$details['interest_id']] = !empty($interests->{$details['interest_id']});
    }
    ksort($info);
    return $info;
  }

  /**
   * Subscribes the contents of the tmp_mailchimp_push_c table.
   */
  public function updateMailchimpFromCivi() {

    $operations = [];
    $dao = CRM_Core_DAO::executeQuery( "SELECT * FROM tmp_mailchimp_push_c;");
    $url_prefix = "/lists/$this->list_id/members/";
    while ($dao->fetch()) {

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
   * Updates CiviCRM from Mailchimp using the tmp_mailchimp_push_[cm] tables.
   *
   * It is assumed that collections (in 'pull' mode) and `removeInSync` have
   * already run.
   *
   * 1. Loop the full tmp_mailchimp_push_m table:
   *
   *    1. Contact identified by collectMailchimp()?
   *       - Yes: update name if different.
   *       - No:  Create or find-and-update the contact.
   *
   *    2. Check for changes in groups; record what needs to be changed for a
   *       batch update.
   *
   * 2. Batch add/remove contacts from groups.
   *
   * @todo.
   *
   */
  public function updateCiviFromMailchimp() {
    $changes = ['removals' => [], 'additions' => []];

    // all Mailchimp table
    $dao = CRM_Core_DAO::executeQuery( "SELECT m.*,
      c.interests c_interests, c.first_name c_first_name, c.last_name c_last_name,
      (COALESCE(c.interests,'') = m.interests) identical_groupings
      FROM tmp_mailchimp_push_m m
      LEFT JOIN tmp_mailchimp_push_c c ON m.email = c.email
      ;");

    // Create lookup hash to map Mailchimp Interest Ids to CiviCRM Groups.
    $interest_to_group_id = [];
    foreach ($this->interest_group_details as $group_id=>$details) {
      $interest_to_group_id[$details['interest_id']] = $group_id;
    }

    // Loop records found at Mailchimp, creating/finding contacts in CiviCRM.
    while ($dao->fetch()) {

      // Get contact_id and ensure contact details are updated.
      if (!empty($dao->cid_guess)) {
        $contact_id = $dao->cid_guess;

        // Update the first name and last name of the contacts we already
        // matched, if needed and making sure we don't overwrite
        // something with nothing. See issue #188.
        $edits = [];
        if ($dao->first_name && $dao->first_name != $dao->c_first_name) {
          // We have a first name from Mailchimp and it's different to ours.
          $edits['first_name'] = $dao->first_name;
        }
        if ($dao->last_name && $dao->last_name != $dao->c_last_name) {
          // We have a last name from Mailchimp and it's different to ours.
          $edits['last_name'] = $dao->last_name;
        }
        if ($edits) {
          // There are changes to be made so make them now.
          civicrm_api3('Contact', 'create', ['id' => $contact_id] + $edits);
        }
      }
      else {
        // We don't know yet who this is.
        // Update/create contact.
        $params = ['FNAME' => $dao->first_name, 'LNAME' => $dao->last_name, 'EMAIL' => $dao->email];
        $contact_id = CRM_Mailchimp_Utils::updateContactDetails($params);
        if(!$contact_id) {
          // We failed to identify the contact.
          // Move on to the next record, nothing we can do here.
          continue;
        }
      }

      if ($dao->identical_groupings) {
        // Nothing more to do.
        continue;
      }

      // Groups are different between Mailchimp and CiviCRM.
      // Unpack the interests reported by MC
      $mc_interests = unserialize($dao->interests);

      // Get interests from CiviCRM.
      if ($dao->c_groupings) {
        // This contact is in C and MC, but has differences.
        // unpack the interests from CiviCRM.
        $civi_interests = unserialize($dao->c_interests);
      }
      else {
        // This contact was not found in the CiviCRM table. Therefore they are
        // not in the membership group. (actually they could have an email
        // problem as well, but that's OK). Add them into the membership group.
        $changes['additions'][$this->membership_group_id][] = $contact_id;
                      
        // Set interests empty.
        $civi_interests = array();
      }

      // Discover what needs changing to bring CiviCRM inline with Mailchimp.
      foreach ($mc_interests as $interest=>$member_has_interest) {
        if ($member_has_interest && empty($civi_interests[$interest])) {
          // Member is interested in something, but CiviCRM does not know yet.
          $changes['additions'][$interest_to_group_id[$interest]][] = $contact_id;
        }
        elseif (!$member_has_interest && !empty($civi_interests[$interest])) {
          // Member is not interested in something, but CiviCRM thinks it is.
          $changes['removals'][$interest_to_group_id[$interest]][] = $contact_id;
        }
      }
    }

    // And now, what if a contact is not in the Mailchimp list?
    // We must remove them from the membership group.
    // Accademic interest (#188): what's faster, this or a 'WHERE NOT EXISTS'
    // construct?
    $dao = CRM_Core_DAO::executeQuery( "
    SELECT c.contact_id
      FROM tmp_mailchimp_push_c c
      LEFT OUTER JOIN tmp_mailchimp_push_m m ON m.email = c.email
      WHERE m.email IS NULL;
      ");
    // Collect the contact_ids that need removing from the membership group.
    while ($dao->fetch()) {
      $changes['removals'][$this->membership_group_id][] =$dao->contact_id;
    }

    // Log group contacts which are going to be added/removed to/from CiviCRM
    CRM_Core_Error::debug_var( 'Mailchimp $changes= ', $changes);

    // FIXME: dirty hack setting a variable in session to skip post hook
		require_once 'CRM/Core/Session.php';
    $session = CRM_Core_Session::singleton();
    $session->set('skipPostHook', 'yes');

    if ($changes['additions']) {
      // We have some contacts to add into groups...
      foreach($changes['additions'] as $groupID => $contactIDs) {
        CRM_Contact_BAO_GroupContact::addContactsToGroup($contactIDs, $groupID, 'Admin', 'Added');
      }
    }

    if ($changes['removals']) {
      // We have some contacts to add into groups...
      foreach($changes['removals'] as $groupID => $contactIDs) {
        CRM_Contact_BAO_GroupContact::removeContactsFromGroup($contactIDs, $groupID, 'Admin', 'Removed');
      }
    }
    // FIXME: unset variable in session
		$session->set('skipPostHook', '');
  }
  /**
   * Update first name and last name of the contacts of which we already
   * know the contact id.
   */
  public function updateGuessedContactDetails() {
    // In theory I could do this with one SQL join statement, but this way
    // we would bypass user defined hooks. So I will use the API, but only
    // in the case that the names are really different. This will save
    // some expensive API calls. See issue #188.

    $dao = CRM_Core_DAO::executeQuery(
      "SELECT c.id, m.first_name, m.last_name
       FROM tmp_mailchimp_push_m m
       JOIN civicrm_contact c ON m.cid_guess = c.id
       WHERE m.first_name NOT IN ('', COALESCE(c.first_name, ''))
          OR m.last_name  NOT IN ('', COALESCE(c.last_name,  ''))");

    while ($dao->fetch()) {
      $params = array('id' => $dao->id);
      if ($dao->first_name) {
        $params['first_name'] = $dao->first_name;
      }
      if ($dao->last_name) {
        $params['last_name'] = $dao->last_name;
      }
      civicrm_api3('Contact', 'create', $params);
    }
    $dao->free();
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

