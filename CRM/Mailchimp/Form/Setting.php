<?php

class CRM_Mailchimp_Form_Setting extends CRM_Core_Form {

  const 
    MC_SETTING_GROUP = 'MailChimp Preferences';

   /**
   * Function to pre processing
   *
   * @return None
   * @access public
   */
  function preProcess() { 
    $currentVer = CRM_Core_BAO_Domain::version(TRUE);
    //if current version is less than 4.4 dont save setting
    if (version_compare($currentVer, '4.4') < 0) {
      CRM_Core_Session::setStatus("You need to upgrade to version 4.4 or above to work with extension Mailchimp","Version:");
    }
  }  

  public static function formRule($params){
    $currentVer = CRM_Core_BAO_Domain::version(TRUE);
    $errors = array();
    if (version_compare($currentVer, '4.4') < 0) {        
      $errors['version_error'] = " You need to upgrade to version 4.4 or above to work with extension Mailchimp";
    }
    return empty($errors) ? TRUE : $errors;
  }

  /**
   * Function to actually build the form
   *
   * @return None
   * @access public
   */
  public function buildQuickForm() {
    $this->addFormRule(array('CRM_Mailchimp_Form_Setting', 'formRule'), $this);

    CRM_Core_Resources::singleton()->addStyleFile('uk.co.vedaconsulting.mailchimp', 'css/mailchimp.css');

    $webhook_url = CRM_Utils_System::url('civicrm/mailchimp/webhook', 'reset=1',  TRUE, NULL, FALSE, TRUE);
    $this->assign( 'webhook_url', 'Webhook URL - '.$webhook_url);

    // Add the API Key Element
    $this->addElement('text', 'api_key', ts('API Key'), array(
      'size' => 48,
    ));    

    // Add the User Security Key Element    
    $this->addElement('text', 'security_key', ts('Security Key'), array(
      'size' => 24,
    ));

    // Add Enable or Disable Debugging
    $enableOptions = array(1 => ts('Yes'), 0 => ts('No'));
    $this->addRadio('enable_debugging', ts('Enable Debugging'), $enableOptions, NULL);

    // Create the Submit Button.
    $buttons = array(
      array(
        'type' => 'submit',
        'name' => ts('Save & Test'),
      ),
    );
    $groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), null, $membership_only = TRUE);

    // Check all our groups do not have the sources:API set in the webhook, and
    // that they do have the webhook set.
    foreach ($groups as $group_id => $details) {

      $group_settings_link = "<a href='/civicrm/group?reset=1&action=update&id=$group_id' >"
        . htmlspecialchars($details['civigroup_title']) . "</a>";

      try {
        $response = CRM_Mailchimp_Utils::getMailchimpApi()->get("/lists/$details[list_id]/webhooks");
        if (empty($response->data->webhooks)) {
          throw new UnexpectedValueException('This list does not have the required webhooks configured at the Mailchimp end. Please do this.');
        }
        if ($response->data->webhooks[0]->sources->api) {
          CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Form_Setting - API is set in Webhook setting for listID and it should not be - risk of recusion!', $details['list_id']);
          throw new UnexpectedValueException('"API" must NOT be listed in the "sources" settings for the webhook - please reconfigure the webhook on Mailchimp ASAP to avoid trouble.');
        }
      }
      catch (CRM_Mailchimp_NetworkErrorException $e) {
        CRM_Core_Session::setStatus(
          ts('Error fetching details for CiviCRM group %1 (Mailchimp list %2): %3',
            [1 => $group_settings_link, 2 => $details['list_id'], 3 => $e->getMessage()]),
          ts('Error'), 'error');
      }
      catch (CRM_Mailchimp_RequestErrorException $e) {
        $message = $e->getMessage();
        if ($e->response->http_code == 404) {
          // A little more helpful than "resource not found".
          $message = "The Mailchimp list that %1 once worked with has
            been deleted on Mailchimp. Please edit the CiviCRM group settings to
            either specify a different Mailchimp list that exists, or to remove
            the Mailchimp integration for this group.";
        }
        CRM_Core_Session::setStatus(
          ts('Error fetching details for CiviCRM group %1 (Mailchimp list %2): %3',
            [1 => $group_settings_link, 2 => $details['list_id'], 3 => $e->getMessage()]),
          ts('Error'), 'error');
      }
      catch (UnexpectedValueException $e) {
        CRM_Core_Session::setStatus(
          ts('Error on CiviCRM group %1 (Mailchimp list %2): %3',
            [1 => $group_settings_link, 2 => $details['list_id'], 3 => $e->getMessage()]),
          ts('Error'), 'error');
      }
    }
    // Add the Buttons.
    $this->addButtons($buttons);
  }

  public function setDefaultValues() {
    $defaults = $details = array();

    $apiKey = CRM_Core_BAO_Setting::getItem(self::MC_SETTING_GROUP,
      'api_key', NULL, FALSE
    );

    $securityKey = CRM_Core_BAO_Setting::getItem(self::MC_SETTING_GROUP,
      'security_key', NULL, FALSE
    );

    $enableDebugging = CRM_Core_BAO_Setting::getItem(self::MC_SETTING_GROUP,
      'enable_debugging', NULL, FALSE
    );
    $defaults['api_key'] = $apiKey;
    $defaults['security_key'] = $securityKey;
    $defaults['enable_debugging'] = $enableDebugging;

    return $defaults;
  }

  /**
   * Function to process the form
   *
   * @access public
   *
   * @return None
   */
  public function postProcess() {
    // Store the submitted values in an array.
    $params = $this->controller->exportValues($this->_name);    

    // Save the API Key & Save the Security Key
    if (CRM_Utils_Array::value('api_key', $params) || CRM_Utils_Array::value('security_key', $params)) {
      CRM_Core_BAO_Setting::setItem($params['api_key'],
        self::MC_SETTING_GROUP,
        'api_key'
      );

      // Not sure we have a security key in apiv3... @todo
      CRM_Core_BAO_Setting::setItem($params['security_key'],
        self::MC_SETTING_GROUP,
        'security_key'
      );

      CRM_Core_BAO_Setting::setItem($params['enable_debugging'], self::MC_SETTING_GROUP, 'enable_debugging');

      try {
        $mcClient = CRM_Mailchimp_Utils::getMailchimpApi();
        $response  = $mcClient->get('/');
        if (empty($response->data->account_name)) {
          throw new Exception("Could not retrieve account details, although a response was received. Somthing's not right.");
        }

      } catch (Exception $e) {
        CRM_Core_Session::setStatus($e->getMessage());
        return FALSE;
      }

      $message = "Following is the account information received from API callback:<br/>
      <table class='mailchimp-table'>
      <tr><td>Account Name:</td><td>" . htmlspecialchars($response->data->account_name) . "</td></tr>
      <tr><td>Account Email:</td><td>" . htmlspecialchars($response->data->email) . "</td></tr>
      </table>";

      CRM_Core_Session::setStatus($message);
    }
  }
}


