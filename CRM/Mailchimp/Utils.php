<?php

class CRM_Mailchimp_Utils {

  const MC_SETTING_GROUP = 'MailChimp Preferences';

  /** Mailchimp API object to use. */
  static protected $mailchimp_api;

  /** Holds runtime cache of group details */
  static protected $mailchimp_interest_details = [];

  /** Holds a cache of list names from Mailchimp */
  static protected $mailchimp_lists;

  /**
   * Checked by mailchimp_civicrm_post before it acts on anything.
   *
   * That post hook might send requests to Mailchimp's API, but in the cases
   * where we're responding to data from Mailchimp, this could possibly result
   * in a loop, so we have a central on/off switch here.
   *
   * In previous versions it was a session variable, but this is not necessary.
   */
  public static $post_hook_enabled = TRUE;

  /**
   * Split a string of group titles into an array of groupIds.
   *
   * The Contact:get API is the only place you can get a list of all the groups
   * (smart and normal) that a contact has membership of. But it returns them as
   * a comma separated string. You can't split on a comma because there is no
   * restriction on commas in group titles. So instead we take a list of
   * candidate titles and look for those.
   *
   * This function solves the problem of:
   * Group name: "Sponsored walk, 2015"
   * Group name: "Sponsored walk"
   *
   * Contact 1's groups: "Sponsored walk,Sponsored walk, 2015"
   * This contact is in both groups.
   *
   * Contact 2's groups: "Sponsored walk"
   * This contact is only in the one group.
   *
   * If we just split on comma then the contacts would only be in the "sponsored
   * walk" group and never the one with the comma in.
   *
   * @param string $group_titles As output by the CiviCRM api for a contact when
   * you request the 'group' output (which comes in a key called 'groups').
   * @param array $group_details As from CRM_Mailchimp_Utils::getGroupsToSync
   * but only including groups you're interested in.
   * @return array CiviCRM groupIds.
   */
  static function splitGroupTitles($group_titles, $group_details) {
    $groups = [];

    // Sort the group titles by length, longest first.
    uasort($group_details, function($a, $b) {
      return (strlen($b['civigroup_title']) - strlen($a['civigroup_title']));
    });
    // Remove the found titles longest first.
    $group_titles = ",$group_titles,";

    foreach ($group_details as $civi_group_id => $detail) {
      $i = strpos($group_titles, ",$detail[civigroup_title],");
      if ($i !== FALSE) {
        $groups[] = $civi_group_id;
        // Remove this from the string.
        $group_titles = substr($group_titles, 0, $i+1) . substr($group_titles, $i + strlen(",$detail[civigroup_title],"));
      }
    }
    return $groups;
  }
  /**
   * Returns the webhook URL.
   */
  static function getWebhookUrl() {
    $security_key = CRM_Core_BAO_Setting::getItem(self::MC_SETTING_GROUP, 'security_key', NULL, FALSE);
    if (empty($security_key)) {
      // @Todo what exception should this throw?
      throw new InvalidArgumentException("You have not set a security key for your Mailchimp integration. Please do this on the settings page at civicrm/mailchimp/settings");
    }
    $webhook_url = CRM_Utils_System::url('civicrm/mailchimp/webhook',
      $query = 'reset=1&key=' . urlencode($security_key),
      $absolute = TRUE,
      $fragment = NULL,
      $htmlize = FALSE,
      $fronteend = TRUE);

    return $webhook_url;
  }
  /**
   * Returns an API class for talking to Mailchimp.
   *
   * This is a singleton pattern with a factory method to create an object of
   * the normal API class. You can set the Api object with
   * CRM_Mailchimp_Utils::setMailchimpApi() which is essential for being able to
   * passin mocks for testing.
   *
   * @param bool $reset If set it will replace the API object with a default.
   * Only useful after changing stored credentials.
   */
  static function getMailchimpApi($reset=FALSE) {
    if ($reset) {
      static::$mailchimp_api = NULL;
    }

    // Singleton pattern.
    if (!isset(static::$mailchimp_api)) {
      $params = ['api_key' => CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'api_key')];
      $debugging = CRM_Core_BAO_Setting::getItem(self::MC_SETTING_GROUP, 'enable_debugging', NULL, FALSE);
      if ($debugging == 1) {
        // We want debugging. Inject a logging callback.
        $params['log_facility'] = function($message) {
          CRM_Core_Error::debug_log_message($message, FALSE, 'mailchimp');
        };
      }
      $api = new CRM_Mailchimp_Api3($params);
      static::setMailchimpApi($api);
    }

    return static::$mailchimp_api;
  }

  /**
   * Set the API object.
   *
   * This is for testing purposes only.
   */
  static function setMailchimpApi(CRM_Mailchimp_Api3 $api) {
    static::$mailchimp_api = $api;
  }

  /**
   * Reset caches.
   */
  static function resetAllCaches() {
    static::$mailchimp_api = NULL;
    static::$mailchimp_lists = NULL;
    static::$mailchimp_interest_details = [];
  }
  /**
   * deprecated (soon!) v1 API
   */
  static function mailchimp() {
    $apiKey   = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'api_key');
    $mcClient = new Mailchimp($apiKey);
    //CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils mailchimp $mcClient', $mcClient);
    return $mcClient;
  }

  /**
   * Check all mapped groups' lists.
   *
   * Nb. this does not output anything itself so we can test it works. It is
   * used by the settings page.
   *
   * @param null|Array $groups array of membership groups to check, or NULL to
   *                   check all.
   *
   * @return Array of message strings that should be output with CRM_Core_Error
   * or such.
   *
   */
  public static function checkGroupsConfig($groups=NULL) {
    if ($groups === NULL) {
      $groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), null, $membership_only = TRUE);
    }
    if (!is_array($groups)) {
      throw new InvalidArgumentException("expected array argument, if provided");
    }
    $api = CRM_Mailchimp_Utils::getMailchimpApi();

    $warnings = [];
    // Check all our groups do not have the sources:API set in the webhook, and
    // that they do have the webhook set.
    foreach ($groups as $group_id => $details) {

      $group_settings_link = "<a href='/civicrm/group?reset=1&action=update&id=$group_id' >"
        . htmlspecialchars($details['civigroup_title']) . "</a>";

      $message_prefix = ts('CiviCRM group "%1" (Mailchimp list %2): ',
        [1 => $group_settings_link, 2 => $details['list_id']]);

      try {
        $test_warnings = CRM_Mailchimp_Utils::configureList($details['list_id'], $dry_run=TRUE);
        foreach ($test_warnings as $_) {
          $warnings []= $message_prefix . $_;
        }
      }
      catch (CRM_Mailchimp_NetworkErrorException $e) {
        $warnings []= $message_prefix . ts("Problems (possibly temporary) fetching details from Mailchimp. ") . $e->getMessage();
      }
      catch (CRM_Mailchimp_RequestErrorException $e) {
        $message = $e->getMessage();
        if ($e->response->http_code == 404) {
          // A little more helpful than "resource not found".
          $warnings []= $message_prefix . ts("The Mailchimp list that this once worked with has "
            ."been deleted on Mailchimp. Please edit the CiviCRM group settings to "
            ."either specify a different Mailchimp list that exists, or to remove "
            ."the Mailchimp integration for this group.");
        }
        else {
          $warnings []= $message_prefix . ts("Problems fetching details from Mailchimp. ") . $e->getMessage();
        }
      }
    }

    if ($warnings) {
      CRM_Core_Error::debug_log_message('Mailchimp list check warnings' . var_export($warnings,1));
    }
    return $warnings;
  }
  /**
   * Configure webhook with Mailchimp.
   *
   * Returns a list of messages to display to the user.
   *
   * @param string $list_id Mailchimp List Id.
   * @param bool $dry_run   If set no changes are made.
   * @return array
   */
  public static function configureList($list_id, $dry_run = FALSE) {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();
    $expected = [
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
    ];
    $verb = $dry_run ? 'Need to change ' : 'Changed ';
    try {
      $result = $api->get("/lists/$list_id/webhooks");
      $webhooks = $result->data->webhooks;
      //$webhooks = $api->get("/lists/$list_id/webhooks")->data->webhooks;

      if (empty($webhooks)) {
        $messages []= ts(($dry_run ? 'Need to create' : 'Created') .' a webhook at Mailchimp');
      }
      else {
        // Existing webhook(s) - check thoroughly.
        if (count($webhooks) > 1) {
          // Unusual case, leave it alone.
          $messages [] = "Mailchimp list $list_id has more than one webhook configured. This is unusual, and so CiviCRM has not made any changes. Please ensure the webhook is set up correctly.";
          return $messages;
        }

        // Got a single webhook, check it looks right.
        $messages = [];
        // Correct URL?
        if ($webhooks[0]->url != $expected['url']) {
          $messages []= ts($verb . 'webhook URL from %1 to %2', [1 => $webhooks[0]->url, 2 => $expected['url']]);
        }
        // Correct sources?
        foreach ($expected['sources'] as $source => $expected_value) {
          if ($webhooks[0]->sources->$source != $expected_value) {
            $messages []= ts($verb . 'webhook source %1 from %2 to %3', [1 => $source, 2 => (int) $webhooks[0]->sources->$source, 3 => (int)$expected_value]);
          }
        }
        // Correct events?
        foreach ($expected['events'] as $event => $expected_value) {
          if ($webhooks[0]->events->$event != $expected_value) {
            $messages []= ts($verb . 'webhook event %1 from %2 to %3', [1 => $event, 2 => (int) $webhooks[0]->events->$event, 3 => (int) $expected_value]);
          }
        }

        if (empty($messages)) {
          // All fine.
          return;
        }

        if (!$dry_run) {
          // As of May 2016, there doesn't seem to be an update method for
          // webhooks, so we just delete this and add another.
          $api->delete("/lists/$list_id/webhooks/" . $webhooks[0]->id);
        }
      }
      if (!$dry_run) {
        // Now create the proper one.
        $result = $api->post("/lists/$list_id/webhooks", $expected);
      }

    }
    catch (CRM_Mailchimp_RequestErrorException $e) {
      if ($e->request->method == 'GET' && $e->response->http_code == 404) {
        $messages [] = ts("The Mailchimp list that this once worked with has been deleted");
      }
      else {
        $messages []= ts("Problems updating or fetching from Mailchimp. Please manually check the configuration. ") . $e->getMessage();
      }
    }
    catch (CRM_Mailchimp_NetworkErrorException $e) {
      $messages []= ts("Problems (possibly temporary) talking to Mailchimp. ") . $e->getMessage();
    }

    return $messages;
  }
  /**
   * Look up an array of CiviCRM groups linked to Maichimp groupings.
   *
   * Indexed by CiviCRM groupId, including:
   *
   * - list_id    (MC)
   *
   * - grouping_id(MC) // deprecate
   * - grouping_name (MC) // deprecate
   * - group_id   (MC) // deprecate
   * - group_name (MC) // deprecate
   *
   * - category_id(MC)
   * - category_name (MC)
   * - interest_id   (MC)
   * - interest_name (MC)
   * - is_mc_update_grouping (bool) - is the subscriber allowed to update this via MC interface?
   * - civigroup_title
   * - civigroup_uses_cache boolean
   *
   * @param $groupIDs mixed array of CiviCRM group Ids to fetch data for; or empty to return ALL mapped groups.
   * @param $mc_list_id mixed Fetch for a specific Mailchimp list only, or null.
   * @param $membership_only bool. Only fetch mapped membership groups (i.e. NOT linked to a MC grouping).
   *
   */
  static function getGroupsToSync($groupIDs = array(), $mc_list_id = null, $membership_only = FALSE) {

    $params = $groups = $temp = array();
    $groupIDs = array_filter(array_map('intval',$groupIDs));

    if (!empty($groupIDs)) {
      $groupIDs = implode(',', $groupIDs);
      $whereClause = "entity_id IN ($groupIDs)";
    } else {
      $whereClause = "1 = 1";
    }

    $whereClause .= " AND mc_list_id IS NOT NULL AND mc_list_id <> ''";

    if ($mc_list_id) {
      // just want results for a particular MC list.
      $whereClause .= " AND mc_list_id = %1 ";
      $params[1] = array($mc_list_id, 'String');
    }

    if ($membership_only) {
      $whereClause .= " AND (mc_grouping_id IS NULL OR mc_grouping_id = '')";
    }

    $query  = "
      SELECT  entity_id, mc_list_id, mc_grouping_id, mc_group_id, is_mc_update_grouping, cg.title as civigroup_title, cg.saved_search_id, cg.children
 FROM    civicrm_value_mailchimp_settings mcs
      INNER JOIN civicrm_group cg ON mcs.entity_id = cg.id
      WHERE $whereClause";
    $dao = CRM_Core_DAO::executeQuery($query, $params);
    while ($dao->fetch()) {
      $list_name = CRM_Mailchimp_Utils::getMCListName($dao->mc_list_id);
      $interest_name = CRM_Mailchimp_Utils::getMCInterestName($dao->mc_list_id, $dao->mc_grouping_id, $dao->mc_group_id);
      $category_name = CRM_Mailchimp_Utils::getMCCategoryName($dao->mc_list_id, $dao->mc_grouping_id);
      $groups[$dao->entity_id] =
        array(
          // Details about Mailchimp
          'list_id'               => $dao->mc_list_id,
          'list_name'             => $list_name,
          'category_id'           => $dao->mc_grouping_id,
          'category_name'         => $category_name,
          'interest_id'           => $dao->mc_group_id,
          'interest_name'         => $interest_name,
          // Details from CiviCRM
          'is_mc_update_grouping' => $dao->is_mc_update_grouping,
          'civigroup_title'       => $dao->civigroup_title,
          'civigroup_uses_cache'    => (bool) (($dao->saved_search_id > 0) || (bool) $dao->children),

          // Deprecated from Mailchimp.
          'grouping_id'           => $dao->mc_grouping_id,
          'grouping_name'         => $category_name,
          'group_id'              => $dao->mc_group_id,
          'group_name'            => $interest_name,
        );
    }

    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getGroupsToSync $groups', $groups);

    return $groups;
  }

  static function getGroupIDsToSync() {
    $groupIDs = self::getGroupsToSync();
    return array_keys($groupIDs);
  }

  static function getMemberCountForGroupsToSync($groupIDs = array()) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getGroupsToSync $groupIDs', $groupIDs);
    $group = new CRM_Contact_DAO_Group();
    foreach ($groupIDs as $key => $value) {
    $group->id  = $value;      
    }
    $group->find(TRUE);
    
    if (empty($groupIDs)) {
      $groupIDs = self::getGroupIDsToSync();
    }
    if(!empty($groupIDs) && $group->saved_search_id){
      $groupIDs = implode(',', $groupIDs);
      $smartGroupQuery = " 
                  SELECT count(*)
                  FROM civicrm_group_contact_cache smartgroup_contact
                  WHERE smartgroup_contact.group_id IN ($groupIDs)";
      $count = CRM_Core_DAO::singleValueQuery($smartGroupQuery);
      CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getMemberCountForGroupsToSync $count', $count);
      return $count;
				  
      
    }
    else if (!empty($groupIDs)) {
      $groupIDs = implode(',', $groupIDs);
      $query    = "
        SELECT  count(*)
        FROM    civicrm_group_contact
        WHERE   status = 'Added' AND group_id IN ($groupIDs)";
      $count = CRM_Core_DAO::singleValueQuery($query);
      CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getMemberCountForGroupsToSync $count', $count);
      return $count;
    }
    return 0;
  }

  /**
   * Return the name at mailchimp for the given Mailchimp list id.
   *
   * @return string.
   */
  public static function getMCListName($list_id) {
    if (!isset(static::$mailchimp_lists)) {
      static::$mailchimp_lists[$list['id']] = [];
      $api = CRM_Mailchimp_Utils::getMailchimpApi();
      $lists = $api->get('/lists', ['fields' => 'lists.id,lists.name'])->data->lists;
      foreach ($lists as $list) {
        static::$mailchimp_lists[$list->id] = $list->name;
      }
    }

    if (!isset(static::$mailchimp_lists[$list_id])) {
      // Return ZLS if not found.
      return '';
    }
    return static::$mailchimp_lists[$list_id];
  }

  /**
   * return the group name for given list, grouping and group
   *
   */
  static function getMCInterestName($listID, $category_id, $interest_id) {
    CRM_Mailchimp_Utils::checkDebug(__FUNCTION__ . " called for list $listID, category $category_id, interest $interest_id");
    $info = static::getMCInterestGroupings($listID);

    // Check list, grouping, and group exist
    if (empty($info[$category_id]['interests'][$interest_id])) {
      $name = null;
    }
    else {
      $name = $info[$category_id]['interests'][$interest_id]['name'];
    }
    CRM_Mailchimp_Utils::checkDebug('End-' . __FUNCTION__, $name);
    return $name;
  }

  /**
   * Return the grouping name for given list, grouping MC Ids.
   */
  static function getMCCategoryName($listID, $category_id) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getMCCategoryName $listID', $listID);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getMCCategoryName $category_id', $category_id);

    $info = static::getMCInterestGroupings($listID);

    // Check list, grouping, and group exist
    $name = NULL;
    if (!empty($info[$category_id])) {
      $name = $info[$category_id]['name'];
    }
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getMCCategoryName $name ', $name);
    return $name;
  }

  /**
   * Get interest groupings for given ListID (cached).
   *
   * Nb. general API function used by several other helper functions.
   *
   * Returns an array like {
   *   [category_id] => array(
   *     'id' => [category_id],
   *     'interests' => array(
   *        [interest_id] => array(
   *          'id' => [interest_id],
   *          'bit' => ..., ?
   *          'name' => ...,
   *          'display_order' => ...,
   *          'subscribers' => ..., ?
   *          ),
   *        ...
   *        ),
   *   ...
   *   )
   *
   */
  static function getMCInterestGroupings($listID) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getMCInterestGroupings $listID', $listID);

    if (empty($listID)) {
      return NULL;
    }

    $mapper = &static::$mailchimp_interest_details;

    if (!array_key_exists($listID, $mapper)) {
      $mapper[$listID] = array();

      try {
        // Get list name.
        $api = CRM_Mailchimp_Utils::getMailchimpApi();
        $categories = $api->get("/lists/$listID/interest-categories",
            ['fields' => 'categories.id,categories.title','count'=>10000])
          ->data->categories;
      }
      catch (CRM_Mailchimp_RequestErrorException $e) {
        if ($e->response->http_code == 404) {
          // Controlled response
          CRM_Core_Error::debug_log_message("Mailchimp error: List $listID is not found.");
          return NULL;
        }
        else {
          CRM_Core_Error::debug_log_message('Unhandled Mailchimp error: ' . $e->getMessage());
          throw $e;
        }
      }
      catch (CRM_Mailchimp_NetworkErrorException $e) {
        CRM_Core_Error::debug_log_message('Unhandled Mailchimp network error: ' . $e->getMessage());
        throw $e;
        return NULL;
      }
      // Re-map $categories from this:
      //    id = (string [10]) `f192c59e0d`
      //    title = (string [7]) `CiviCRM`

      foreach ($categories as $category) {
        // Need to look up interests for this category.
        $interests = CRM_Mailchimp_Utils::getMailchimpApi()
          ->get("/lists/$listID/interest-categories/$category->id/interests",
            ['fields' => 'interests.id,interests.name','count'=>10000])
          ->data->interests;

        $mapper[$listID][$category->id] = [
          'id' => $category->id,
          'name' => $category->title,
          'interests' => [],
          ];
        foreach ($interests as $interest) {
          $mapper[$listID][$category->id]['interests'][$interest->id] =
            ['id' => $interest->id, 'name' => $interest->name];
        }
      }
    }
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getMCInterestGroupings $mapper', $mapper[$listID]);
    return $mapper[$listID];
  }

  /**
   * Get Mailchimp group ID group name
   */
  static function getMailchimpGroupIdFromName($listID, $groupName) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getMailchimpGroupIdFromName $listID', $listID);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getMailchimpGroupIdFromName $groupName', $groupName);

    if (empty($listID) || empty($groupName)) {
      return NULL;
    }

    $mcLists = new Mailchimp_Lists(CRM_Mailchimp_Utils::mailchimp());
    try {
      $results = $mcLists->interestGroupings($listID);
    } 
    catch (Exception $e) {
      return NULL;
    }
    
    foreach ($results as $grouping) {
      foreach ($grouping['groups'] as $group) {
        if ($group['name'] == $groupName) {
          CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getMailchimpGroupIdFromName= ', $group['id']);
          return $group['id'];
        }
      }
    }
  }
  
  static function getGroupIdForMailchimp($listID, $groupingID, $groupID) {

    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getGroupIdForMailchimp $listID', $listID);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getGroupIdForMailchimp $groupingID', $groupingID);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getGroupIdForMailchimp $groupID', $groupID);

    if (empty($listID)) {
      return NULL;
    }
    
    if (!empty($groupingID) && !empty($groupID)) {
      $whereClause = "mc_list_id = %1 AND mc_grouping_id = %2 AND mc_group_id = %3";
    } else {
      $whereClause = "mc_list_id = %1";
    }

    $query  = "
      SELECT  entity_id
      FROM    civicrm_value_mailchimp_settings mcs
      WHERE   $whereClause";
    $params = 
        array(
          '1' => array($listID , 'String'),
          '2' => array($groupingID , 'String'),
          '3' => array($groupID , 'String'),
        );
    $dao = CRM_Core_DAO::executeQuery($query, $params);
    if ($dao->fetch()) {
      CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getGroupIdForMailchimp $dao->entity_id', $dao->entity_id);
      return $dao->entity_id;
    }
    
  }
  


  static function getContactFromEmail($email, $primary = TRUE) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getContactFromEmail $email', $email);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getContactFromEmail $primary', $primary);

    $primaryEmail  = 1;
    if(!$primary) {
     $primaryEmail = 0;
    }
    $contactids = array();
    $query = "
      SELECT `contact_id` FROM civicrm_email ce
      INNER JOIN civicrm_contact cc ON ce.`contact_id` = cc.id
      WHERE ce.email = %1 AND ce.is_primary = {$primaryEmail} AND cc.is_deleted = 0 ";
    $dao   = CRM_Core_DAO::executeQuery($query, array( '1' => array($email, 'String'))); 
    while($dao->fetch()) {
      $contactids[] = $dao->contact_id;
    }
    
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getContactFromEmail $contactids', $contactids);

    return $contactids;
  }
  
  static function updateParamsExactMatch($contactids = array(), $params) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils updateParamsExactMatch $params', $params);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils updateParamsExactMatch $contactids', $contactids);


    $contactParams =
        array(
          'version'       => 3,
          'contact_type'  => 'Individual',
          'first_name'    => $params['FNAME'],
          'last_name'     => $params['LNAME'],
          'email'         => $params['EMAIL'],
        );
    if(count($contactids) == 1) {
        $contactParams['id'] = $contactids[0];
        unset($contactParams['contact_type']);
        // Don't update firstname/lastname if it was empty
        if(empty($params['FNAME']))
          unset($contactParams['first_name']);
        if(empty($params['LNAME']))
          unset ($contactParams['last_name']);
      }
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils updateParamsExactMatch $contactParams', $contactParams);

    return $contactParams;
  }
  /**
   * Function to get the associated CiviCRM Groups IDs for the Grouping array
   * sent from Mialchimp Webhook.
   *
   * Note: any groupings from Mailchimp that do not map to CiviCRM groups are
   * silently ignored. Also, if a subscriber has no groupings, this function
   * will not return any CiviCRM groups (because all groups must be mapped to
   * both a list and a grouping).
   */
  static function getCiviGroupIdsforMcGroupings($listID, $mcGroupings) {

    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getCiviGroupIdsforMcGroupings $listID', $listID);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getCiviGroupIdsforMcGroupings $mcGroupings', $mcGroupings);

    if (empty($listID) || empty($mcGroupings)) {
      return array();
    }
    $civiGroups = array();
    foreach ($mcGroupings as $key => $mcGrouping) {
      if(!empty($mcGrouping['groups'])) {
        $mcGroups = @explode(',', $mcGrouping['groups']);
        foreach ($mcGroups as $mcGroupKey => $mcGroupName) {
          // Get Mailchimp group ID from group name. Only group name is passed in by Webhooks
          $mcGroupID = self::getMailchimpGroupIdFromName($listID, trim($mcGroupName));
          // Mailchimp group ID is unavailable
          if (empty($mcGroupID)) {
            // Try the next one.
            continue;
          }

          // Find the CiviCRM group mapped with the Mailchimp List and Group
          $civiGroupID = self::getGroupIdForMailchimp($listID, $mcGrouping['id'] , $mcGroupID);
          if (!empty($civiGroupID)) {
            $civiGroups[] = $civiGroupID;
          }
        }
      }
    }
 
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getCiviGroupIdsforMcGroupings $civiGroups', $civiGroups);
    return $civiGroups;
  }

  /**
   * Function to get CiviCRM Groups for the specific Mailchimp list in which the Contact is Added to
   */
  static function getGroupSubscriptionforMailchimpList($listID, $contactID) {

    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getGroupSubscriptionforMailchimpList $listID', $listID);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getGroupSubscriptionforMailchimpList $contactID', $contactID);

    if (empty($listID) || empty($contactID)) {
      return NULL;
    }
    
    $civiMcGroups = array();
    $query  = "
      SELECT  entity_id
      FROM    civicrm_value_mailchimp_settings mcs
      WHERE   mc_list_id = %1";
    $params = array('1' => array($listID, 'String'));
    
    $dao = CRM_Core_DAO::executeQuery($query ,$params);
    while ($dao->fetch()) {
      $groupContact = new CRM_Contact_BAO_GroupContact();
      $groupContact->group_id = $dao->entity_id;
      $groupContact->contact_id = $contactID;
      $groupContact->whereAdd("status = 'Added'");
      $groupContact->find();
      if ($groupContact->fetch()) {
        $civiMcGroups[] = $dao->entity_id;
      }
    }
  
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getGroupSubscriptionforMailchimpList $civiGroups', $civiGroups);

    return $civiMcGroups;
  }
  
  
   /**
   * Function to call syncontacts with smart groups and static groups
   *
   * Returns object that can iterate over a slice of the live contacts in given group.
   */
  static function getGroupContactObject($groupID, $start = null) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getGroupContactObject $groupID', $groupID);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getGroupContactObject $start', $start);

    $group           = new CRM_Contact_DAO_Group();
    $group->id       = $groupID;
    $group->find();

    if($group->fetch()){
      //Check smart groups (including parent groups, which function as smart groups).
      if($group->saved_search_id || $group->children){
        $groupContactCache = new CRM_Contact_BAO_GroupContactCache();
        $groupContactCache->group_id = $groupID;
        if ($start !== null) {
          $groupContactCache->limit($start, CRM_Mailchimp_Form_Sync::BATCH_COUNT);
        }
        $groupContactCache->find();
        CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getGroupContactObject $groupContactCache', $groupContactCache);
        return $groupContactCache;
      }
      else {
        $groupContact = new CRM_Contact_BAO_GroupContact();
        $groupContact->group_id = $groupID;
        $groupContact->whereAdd("status = 'Added'");
        if ($start !== null) {
          $groupContact->limit($start, CRM_Mailchimp_Form_Sync::BATCH_COUNT);
        }
        $groupContact->find();
        CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getGroupContactObject $groupContact', $groupContact);
        return $groupContact;
      }
    }
    return FALSE;
  }
   /**
   * Function to call syncontacts with smart groups and static groups xxx delete
   *
   * Returns object that can iterate over a slice of the live contacts in given group.
   */
  static function getGroupMemberships($groupIDs) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getGroupMemberships $groupIDs', $groupIDs);


    $group           = new CRM_Contact_DAO_Group();
    $group->id       = $groupID;
    $group->find();

    if($group->fetch()){
      //Check smart groups
      if($group->saved_search_id){
        $groupContactCache = new CRM_Contact_BAO_GroupContactCache();
        $groupContactCache->group_id = $groupID;
        if ($start !== null) {
          $groupContactCache->limit($start, CRM_Mailchimp_Form_Sync::BATCH_COUNT);
        }
        $groupContactCache->find();
        CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getGroupMemberships $groupContactCache', $groupContactCache);
        return $groupContactCache;
      }
      else {
        $groupContact = new CRM_Contact_BAO_GroupContact();
        $groupContact->group_id = $groupID;
        $groupContact->whereAdd("status = 'Added'");
        if ($start !== null) {
          $groupContact->limit($start, CRM_Mailchimp_Form_Sync::BATCH_COUNT);
        }
        $groupContact->find();
        CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getGroupMemberships $groupContact', $groupContact);
        return $groupContact;
      }
    }
    
    return FALSE;
  }
  
  /**
   * Function to subscribe/unsubscribe civicrm contact in Mailchimp list
	 *
	 * $groupDetails - Array
	 *	(
	 *			[list_id] => ec641f8988
	 *		  [grouping_id] => 14397
	 *			[group_id] => 35609
	 *			[is_mc_update_grouping] => 
	 *			[group_name] => 
	 *			[grouping_name] => 
	 *			[civigroup_title] => Easter Newsletter
	 *			[civigroup_uses_cache] => 
	 * )
	 * 
	 * $action - subscribe/unsubscribe
   */
  static function subscribeOrUnsubsribeToMailchimpList($groupDetails, $contactID, $action) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils subscribeOrUnsubsribeToMailchimpList $groupDetails', $groupDetails);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils subscribeOrUnsubsribeToMailchimpList $contactID', $contactID);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils subscribeOrUnsubsribeToMailchimpList $action', $action);

    if (empty($groupDetails) || empty($contactID) || empty($action)) {
      return NULL;
    }
    
    // We need to get contact's email before subscribing in Mailchimp
		$contactParams = array(
			'version'       => 3,
			'id'  					=> $contactID,
		);

		$contactResult = civicrm_api('Contact' , 'get' , $contactParams);
		// This is the primary email address of the contact
		$email = $contactResult['values'][$contactID]['email'];
		
		if (empty($email)) {
			// Its possible to have contacts in CiviCRM without email address
			// and add to group offline
			return;
		}
		
		// Optional merges for the email (FNAME, LNAME)
		$merge = array(
			'FNAME' => $contactResult['values'][$contactID]['first_name'],
			'LNAME' => $contactResult['values'][$contactID]['last_name'],
		);
	
		$listID = $groupDetails['list_id'];
		$grouping_id = $groupDetails['grouping_id'];
		$group_id = $groupDetails['group_id'];
		if (!empty($grouping_id) AND !empty($group_id)) {
			$merge_groups[$grouping_id] = array('id'=> $groupDetails['grouping_id'], 'groups'=>array());
			$merge_groups[$grouping_id]['groups'][] = CRM_Mailchimp_Utils::getMCInterestName($listID, $grouping_id, $group_id);
			
			// remove the significant array indexes, in case Mailchimp cares.
			$merge['groupings'] = array_values($merge_groups);
		}
		
		// Send Mailchimp Lists API Call.
		$list = new Mailchimp_Lists(CRM_Mailchimp_Utils::mailchimp());
		switch ($action) {
			case "subscribe":
				// http://apidocs.mailchimp.com/api/2.0/lists/subscribe.php
				try {
					$result = $list->subscribe($listID, array('email' => $email), $merge, $email_type='html', $double_optin=FALSE, $update_existing=FALSE, $replace_interests=TRUE, $send_welcome=FALSE);
				}
				catch (Exception $e) {
          // Don't display if the error is that we're already subscribed.
          $message = $e->getMessage();
          if ($message !== $email . ' is already subscribed to the list.') {
            CRM_Core_Session::setStatus($message);
          }
				}
				break;
			case "unsubscribe":
				// https://apidocs.mailchimp.com/api/2.0/lists/unsubscribe.php
				try {
					$result = $list->unsubscribe($listID, array('email' => $email), $delete_member=false, $send_goodbye=false, $send_notify=false);
				}
				catch (Exception $e) {
					CRM_Core_Session::setStatus($e->getMessage());
				}
				break;
		}
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils subscribeOrUnsubsribeToMailchimpList $groupDetails', $groupDetails);
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils subscribeOrUnsubsribeToMailchimpList $contactID', $contactID);
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils subscribeOrUnsubsribeToMailchimpList $action', $action);
  }

  /**
   * Log a message and optionally a variable, if debugging is enabled.
   */
  public static function checkDebug($description, $variable='VARIABLE_NOT_PROVIDED') {
    $debugging = CRM_Core_BAO_Setting::getItem(self::MC_SETTING_GROUP, 'enable_debugging', NULL, FALSE);

    if ($debugging == 1) {
      if ($variable === 'VARIABLE_NOT_PROVIDED') {
        // Simple log message.
        CRM_Core_Error::debug_log_message($description, FALSE, 'mailchimp');
      }
      else {
        // Log a variable.
        CRM_Core_Error::debug_var(
          $description,
          $variable,
          $print = FALSE,
          $log = TRUE,
          // Use a separate component for our logfiles in ConfigAndLog
          $comp = 'mailchimp' 
        );
      }
    }
  }
}
