<?php
/**
 * @file
 * This provides the Sync Push from CiviCRM to Mailchimp form.
 */

class CRM_Mailchimp_Form_Sync extends CRM_Core_Form {

  const QUEUE_NAME = 'mc-sync';
  const END_URL    = 'civicrm/mailchimp/sync';
  const END_PARAMS = 'state=done';

  /**
   * Function to pre processing
   *
   * @return None
   * @access public
   */
  function preProcess() {
    $state = CRM_Utils_Request::retrieve('state', 'String', CRM_Core_DAO::$_nullObject, FALSE, 'tmp', 'GET');
    if ($state == 'done') {
      $stats = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'push_stats');
      $groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), null, $membership_only=TRUE);
      if (!$groups) {
        return;
      }
      $output_stats = array();
      foreach ($groups as $group_id => $details) {
        $list_stats = $stats[$details['list_id']];
        $output_stats[] = array(
          'name' => $details['civigroup_title'],
          'stats' => $list_stats,
        );
      }
      $this->assign('stats', $output_stats);
    }
  }

  /**
   * Function to actually build the form
   *
   * @return None
   * @access public
   */
  public function buildQuickForm() {

    $groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), null, $membership_only = TRUE);
    if (!empty($_GET['reset'])) {
      $will = '';
      $wont = '';
      foreach ($groups as $group_id => $details) {
        $description = "<a href='/civicrm/group?reset=1&action=update&id=$group_id' >"
          . "CiviCRM group $group_id: "
          . htmlspecialchars($details['civigroup_title']) . "</a>";

        if (empty($details['list_name'])) {
          $wont .= "<li>$description</li>";
        }
        else {
          $will .= "<li>$description &rarr; Mailchimp List: " . htmlspecialchars($details['list_name']) . "</li>";
        }
      }
    }
    $msg = '';
    if ($will) {
      $msg .= "<h2>" . ts('The following lists will be synchronised') . "</h2><ul>$will</ul>";

      // Create the Submit Button.
      $buttons = array(
        array(
          'type' => 'submit',
          'name' => ts('Sync Contacts'),
        ),
      );
      $this->addButtons($buttons);
    }
    if ($wont) {
      $msg .= "<h2>" . ts('The following lists will be NOT synchronised') . "</h2><p>The following list(s) no longer exist at Mailchimp.</p><ul>$wont</ul>";
    }
    $this->assign('summary', $msg);
  }

  /**
   * Function to process the form
   *
   * @access public
   *
   * @return None
   */
  public function postProcess() {
    $runner = self::getRunner();
    if ($runner) {
      // Run Everything in the Queue via the Web.
      $runner->runAllViaWeb();
    } else {
      CRM_Core_Session::setStatus(ts('Nothing to sync. Make sure mailchimp settings are configured for the groups with enough members.'));
    }
  }

  /**
   * Set up the queue.
   */
  public static function getRunner($skipEndUrl = FALSE) {
    // Setup the Queue
    $queue = CRM_Queue_Service::singleton()->create(array(
      'name'  => self::QUEUE_NAME,
      'type'  => 'Sql',
      'reset' => TRUE,
    ));

    // reset push stats
    CRM_Core_BAO_Setting::setItem(Array(), CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'push_stats');
    $stats = array();

    // We need to process one list at a time.
    $groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), null, $membership_only = TRUE);
    CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Form_Sync getRunner $groups= ', $groups);

    // Each list is a task.
    foreach ($groups as $group_id => $details) {
      if (empty($details['list_name'])) {
        // This list has been deleted at Mailchimp, or for some other reason we
        // could not access its name. Best not to sync it.
        continue;
      }

      $stats[$details['list_id']] = [
        'c_count'      => 0,
        'mc_count'     => 0,
        'in_sync'      => 0,
        'updates'      => 0,
        'additions'    => 0,
        'unsubscribes' => 0,
      ];

      $identifier = "List " . $listCount++ . " " . $details['civigroup_title'];

      $task  = new CRM_Queue_Task(
        array ('CRM_Mailchimp_Form_Sync', 'syncPushList'),
        array($details['list_id'], $identifier),
        "Preparing queue for $identifier"
      );

      // Add the Task to the Queue
      $queue->createItem($task);
    }
    if (empty($stats)) {
      // Nothing to do.
      return FALSE;
    }

    // Setup the Runner
		$runnerParams = array(
      'title' => ts('Mailchimp Sync: CiviCRM to Mailchimp'),
      'queue' => $queue,
      'errorMode'=> CRM_Queue_Runner::ERROR_ABORT,
      'onEndUrl' => CRM_Utils_System::url(self::END_URL, self::END_PARAMS, TRUE, NULL, FALSE),
    );
		// Skip End URL to prevent redirect
		// if calling from cron job
		if ($skipEndUrl == TRUE) {
			unset($runnerParams['onEndUrl']);
		}
    $runner = new CRM_Queue_Runner($runnerParams);

    static::updatePushStats($stats);
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Form_Sync getRunner $identifier= ', $identifier);

    return $runner;
  }

  /**
   * Set up (sub)queue for syncing a Mailchimp List.
   */
  public static function syncPushList(CRM_Queue_TaskContext $ctx, $listID, $identifier) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncPushList $listID= ', $listID);
    // Split the work into parts:

    // Add the CiviCRM collect data task to the queue
    // It's important that this comes before the Mailchimp one, as some
    // fast contact matching SQL can run if it's done this way.
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Sync', 'syncPushCollectCiviCRM'),
      array($listID),
      "$identifier: Fetched data from CiviCRM, fetching from Mailchimp..."
    ));

    // Add the Mailchimp collect data task to the queue
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Sync', 'syncPushCollectMailchimp'),
      array($listID),
      "$identifier: Fetched data from Mailchimp. Matching..."
    ));

    // Add the slow match process for difficult contacts.
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Pull', 'syncPushDifficultMatches'),
      array($listID),
      "$identifier: Matched up contacts. Comparing..."
    ));

    // Add the Mailchimp collect data task to the queue
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Sync', 'syncPushIgnoreInSync'),
      array($listID),
      "$identifier: Ignored any in-sync already. Updating Mailchimp..."
    ));

    // Add the Mailchimp changes
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Sync', 'syncPushToMailchimp'),
      array($listID),
      "$identifier: Completed additions/updates/unsubscribes."
    ));

    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Collect CiviCRM data into temporary working table.
   */
  public static function syncPushCollectCiviCRM(CRM_Queue_TaskContext $ctx, $listID) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncPushCollectCiviCRM $listID= ', $listID);

    $sync = new CRM_Mailchimp_Sync($listID);
    $stats[$listID]['c_count'] = $sync->collectCiviCrm('push');

    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncPushCollectCiviCRM $stats[$listID][c_count]= ', $stats[$listID]['c_count']);
    static::updatePushStats($stats);

    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Collect Mailchimp data into temporary working table.
   */
  public static function syncPushCollectMailchimp(CRM_Queue_TaskContext $ctx, $listID) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncPushCollectMailchimp $listID= ', $listID);

    // Nb. collectCiviCrm must have run before we call this.
    $sync = new CRM_Mailchimp_Sync($listID);
    $stats[$listID]['mc_count'] = $sync->collectMailchimp('push', $civi_collect_has_already_run=TRUE);

    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncPushCollectMailchimp $stats[$listID][mc_count]', $stats[$listID]['mc_count']);
    static::updatePushStats($stats);

    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Do the difficult matches.
   */
  public static function syncPushDifficultMatches(CRM_Queue_TaskContext $ctx, $listID) {

    // Nb. collectCiviCrm must have run before we call this.
    $sync = new CRM_Mailchimp_Sync($listID);
    $c = $sync->matchMailchimpMembersToContacts();
    CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Form_Sync syncPushDifficultMatches count=', $c);
    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Collect Mailchimp data into temporary working table.
   */
  public static function syncPushIgnoreInSync(CRM_Queue_TaskContext $ctx, $listID) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncPushIgnoreInSync $listID= ', $listID);

    $sync = new CRM_Mailchimp_Sync($listID);
    $stats[$listID]['in_sync'] = $sync->removeInSync();

    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncPushIgnoreInSync $stats[$listID][in_sync]', $stats[$listID]['in_sync']);
    static::updatePushStats($stats);

    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Batch update Mailchimp with new contacts that need to be subscribed, or
   * have changed data including unsubscribes.
   */
  public static function syncPushToMailchimp(CRM_Queue_TaskContext $ctx, $listID) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncPushAdd $listID= ', $listID);

    // Do the batch update. Might take a while :-O
    $sync = new CRM_Mailchimp_Sync($listID);
    // this generates updates and unsubscribes
    $stats[$listID] = $sync->updateMailchimpFromCivi();
    // Finally, finish up by removing the two temporary tables
    CRM_Mailchimp_Sync::dropTemporaryTables();
    static::updatePushStats($stats);

    return CRM_Queue_Task::TASK_SUCCESS;
  }


  /**
   * Collect Mailchimp data into temporary working table.
   */
  public static function syncCollectMailchimp($listID) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncCollectMailchimp $listID= ', $listID);
    // Create a temporary table.
    // Nb. these are temporary tables but we don't use TEMPORARY table because they are
    // needed over multiple sessions because of queue.

    CRM_Core_DAO::executeQuery( "DROP TABLE IF EXISTS tmp_mailchimp_push_m;");
    CRM_Core_DAO::executeQuery(
      "CREATE TABLE tmp_mailchimp_push_m (
        email VARCHAR(200),
        first_name VARCHAR(100),
        last_name VARCHAR(100),
        euid VARCHAR(10),
        leid VARCHAR(10),
        hash CHAR(32),
        interests VARCHAR(4096),
        cid_guess INT(10),
        PRIMARY KEY (email, hash))
        ENGINE=InnoDB DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ;");
    // I'll use the cid_guess column to store the cid when it is
    // immediately clear. This will speed up pulling updates (see #118).
    // Create an index so that this cid_guess can be used for fast
    // searching.
    $dao = CRM_Core_DAO::executeQuery(
        "CREATE INDEX index_cid_guess ON tmp_mailchimp_push_m(cid_guess);");

    // Cheekily access the database directly to obtain a prepared statement.
    $db = $dao->getDatabaseConnection();
    //$insert = $db->prepare('INSERT INTO tmp_mailchimp_push_m(email, first_name, last_name, euid, leid, hash, groupings) VALUES(?, ?, ?, ?, ?, ?, ?)');
    $insert = $db->prepare('INSERT INTO tmp_mailchimp_push_m(email, first_name, last_name, hash, interests) VALUES(?, ?, ?, ?, ?)');

    // We need to know what grouping data we care about. The rest we completely ignore.
    // We only care about CiviCRM groups that are mapped to this MC List.
    $mapped_groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), $listID);

    CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Form_Sync syncCollectMailchimp $mapped_groups', $mapped_groups);

    $api = CRM_Mailchimp_Utils::getMailchimpApi();
    $offset = 0;
    $batch_size = 1000;
    $total = null;

    $fetch_batch = function() use($api, &$offset, &$total, $batch_size, $listID) {
      if ($total !== null && $offset >= $total) {
        // End of results.
        return [];
      }
      $response = $api->get("/lists/$listID/members", [
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
    //
    // Make an array of interests that aren't the 'membership' interest.
    // for use in the inside loop below.
    $mapped_interests = array_filter($mapped_groups, function($details) {
      return (bool) $details['category_id'];
    });

    while ($members = $fetch_batch()) {
      foreach ($members as $member) {
        // Find out which of our mapped groups apply to this subscriber.
        // Save to an array like: $interests[categoryid][interestid] = (bool)
        $interests = array();
        foreach ($mapped_interests as $civi_group_id => $details) {
          $interests[$details['category_id']][$details['interest_id']] = $member->interests->{$details['interest_id']};
        }
        // Serialize the grouping array for SQL storage - this is the fastest way.
        $interests = serialize($interests);

        // we're ready to store this but we need a hash that contains all the info
        // for comparison with the hash created from the CiviCRM data (elsewhere).
        $first_name = isset($member->merge_fields->FNAME) ? $member->merge_fields->FNAME : null;
        $last_name  = isset($member->merge_fields->LNAME) ? $member->merge_fields->LNAME : null;
        $hash = md5($member->email_address . $first_name . $last_name . $interests);
        // run insert prepared statement
        $db->execute($insert, [
          $member->email_address,
          $first_name,
          $lasts_name,
          $hash,
          $interests,
        ]);
      }
    }

    // Tidy up.
    fclose($handle);
    $db->freePrepared($insert);

    // Guess the contact ID's, to speed up syncPullUpdates (See issue #188).
    CRM_Mailchimp_Utils::guessCidsMailchimpContacts();

    $dao = CRM_Core_DAO::executeQuery("SELECT COUNT(*) c  FROM tmp_mailchimp_push_m");
    $dao->fetch();
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Form_Sync syncCollectMailchimp $listID= ', $listID);
    return $dao->c;
  }

  /**
   * Collect CiviCRM data into temporary working table.
   */
  public static function syncCollectCiviCRM($listID) {
  CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncCollectCiviCRM $listID= ', $listID);
    // Nb. these are temporary tables but we don't use TEMPORARY table because they are
    // needed over multiple sessions because of queue.
    CRM_Core_DAO::executeQuery( "DROP TABLE IF EXISTS tmp_mailchimp_push_c;");
    $dao = CRM_Core_DAO::executeQuery("CREATE TABLE tmp_mailchimp_push_c (
        contact_id INT(10) UNSIGNED NOT NULL,
        email_id INT(10) UNSIGNED NOT NULL,
        email VARCHAR(200),
        first_name VARCHAR(100),
        last_name VARCHAR(100),
        hash CHAR(32),
        groupings VARCHAR(4096),
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
    $mapped_groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), $listID);

    // First, get all subscribers from the membership group for this list.
    // ... Find CiviCRM group id for the membership group.
    // ... And while we're at it, build an SQL-safe array of groupIds for groups mapped to groupings.
    //     (we use that later)
    $membership_group_id = FALSE;
    // There used to be a distinction between the handling of 'normal' groups
    // and smart groups. But now the API will take care of this.
    $grouping_group_ids = array();
    $default_info = array();

    // The CiviCRM Contact API returns group titles instead of group ID's.
    // Nobody knows why. So let's build this array to convert titles to ID's.
    $title2gid = array();

    foreach ($mapped_groups as $group_id => $details) {
      $title2gid[$details['civigroup_title']] = $group_id;
      CRM_Contact_BAO_GroupContactCache::loadAll($group_id);
      if (!$details['category_id']) {
        $membership_group_id = $group_id;
      }
      else {
        $grouping_group_ids[] = (int)$group_id;
        $default_info[ $details['grouping_id'] ][ $details['group_id'] ] = FALSE;
      }
    }
    if (!$membership_group_id) {
      throw new Exception("No CiviCRM group is mapped to determine membership of Mailchimp list $listID");
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

    foreach ($result['values'] as $contact) {
      // Find out the ID's of the groups the $contact belongs to, and
      // save in $info.
      $info = $default_info;

      $contact_group_titles = explode(',', $contact['groups'] );
      foreach ($contact_group_titles as $title) {
        $group_id = $title2gid[$title];
        if (in_array($group_id, $grouping_group_ids)) {
          $details = $mapped_groups[$group_id];
          $info[$details['grouping_id']][$details['group_id']] = TRUE;
        }
      }

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
    // count
    $dao = CRM_Core_DAO::executeQuery("SELECT COUNT(*) c  FROM tmp_mailchimp_push_c");
    $dao->fetch();
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Form_Sync syncCollectCiviCRM $listID= ', $listID);
    return $dao->c;
  }

  /**
   * Update the push stats setting.
   */
  public static function updatePushStats($updates) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync updatePushStats $updates= ', $updates);

    $stats = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'push_stats');
    foreach ($updates as $listId=>$settings) {
      foreach ($settings as $key=>$val) {
        $stats[$listId][$key] = $val;
      }
    }
    CRM_Core_BAO_Setting::setItem($stats, CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'push_stats');
  }
}
