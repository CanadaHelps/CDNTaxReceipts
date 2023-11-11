<?php

require_once 'cdntaxreceipts.civix.php';
require_once 'cdntaxreceipts.functions.inc';
require_once 'cdntaxreceipts.db.inc';

use CRM_Cdntaxreceipts_ExtensionUtil as E;

define('CDNTAXRECEIPTS_MODE_BACKOFFICE', 1);
define('CDNTAXRECEIPTS_MODE_PREVIEW', 2);
define('CDNTAXRECEIPTS_MODE_WORKFLOW', 3);
define('CDNTAXRECEIPTS_MODE_API', 4);

function cdntaxreceipts_civicrm_buildForm( $formName, &$form ) {
  if (is_a( $form, 'CRM_Contribute_Form_ContributionView')) {
    // add "Issue Tax Receipt" button to the "View Contribution" page
    // if the Tax Receipt has NOT yet been issued -> display a white maple leaf icon
    // if the Tax Receipt has already been issued -> display a red maple leaf icon

    CRM_Core_Resources::singleton()->addStyleFile('org.civicrm.cdntaxreceipts', 'css/civicrm_cdntaxreceipts.css');

    $contributionId = $form->get('id');
    $buttons = array(
      array(
        'type' => 'cancel',
        'name' => ts('Done'),
        'spacing' => '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;',
        'isDefault' => TRUE,
      )
    );
    $subName = 'view_tax_receipt';

    // Advantage fields
    $form->assign('isView', TRUE);
    cdntaxreceipts_advantage($contributionId, NULL, $defaults, TRUE);
    if (!empty($defaults['advantage_description'])) {
      $form->assign('advantage_description', $defaults['advantage_description']);
    }
    CRM_Core_Region::instance('page-body')->add(array(
      'template' => 'CRM/Cdntaxreceipts/Form/AddAdvantage.tpl',
    ));

    if ( isset($contributionId) && cdntaxreceipts_eligibleForReceipt($contributionId) ) {
      list($issued_on, $receipt_id) = cdntaxreceipts_issued_on($contributionId);
      $is_original_receipt = empty($issued_on);

      if ($is_original_receipt) {
        $subName = 'issue_tax_receipt';
      }

      $buttons[] = array(
        'type'      => 'submit',
        'subName'   => $subName,
        'name'      => E::ts('Tax Receipt'),
        'isDefault' => FALSE,
        'icon'      => 'fa-check-square',
      );
      $form->addButtons($buttons);
    }
  }
  if (is_a($form, 'CRM_Contribute_Form_Contribution') && in_array($form->_action, [CRM_Core_Action::ADD, CRM_Core_Action::UPDATE])) {
    $form->add('text', 'non_deductible_amount', ts('Advantage Amount'), NULL);
    $form->add('text', 'advantage_description', ts('Advantage Description'), NULL);
    if ($form->_action & CRM_Core_Action::UPDATE) {
      cdntaxreceipts_advantage($form->_id, NULL, $defaults, TRUE);
      $form->setDefaults($defaults);
    }

    CRM_Core_Region::instance('page-body')->add(array(
      'template' => 'CRM/Cdntaxreceipts/Form/AddAdvantage.tpl',
    ));
  }
}

/**
 * Implements hook_civicrm_validateForm().
 *
 * @param string $formName
 * @param array $fields
 * @param array $files
 * @param CRM_Core_Form $form
 * @param array $errors
 */
function cdntaxreceipts_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors) {

  // Require description for advantage amount if advantage amount is filled in.
  if (is_a($form, 'CRM_Contribute_Form_Contribution')
    && (CRM_Utils_Array::value('non_deductible_amount', $fields) > 0) && !CRM_Utils_Array::value('advantage_description', $fields)) {
    $errors['advantage_description'] = ts('Please enter a description for advantage amount');
  }
  if (is_a($form, 'CRM_Contribute_Form_Contribution')) {
    // Limit number of characters to 50 for description of advantage.
    if (CRM_Utils_Array::value('advantage_description', $fields)) {
      if (strlen(CRM_Utils_Array::value('advantage_description', $fields)) > 80) {
        $errors['advantage_description'] = ts('Advantage Description should not be more than 80 characters');
      }
    }
    if (!empty($fields['financial_type_id'])) {
      $ftName = civicrm_api3('FinancialType', 'getvalue', [
        'return' => "name",
        'id' => $fields['financial_type_id'],
      ]);
      if ($ftName  == "In-kind" || $ftName == "In Kind") {
        $customFields = [
          60 => "Appraised by",
          80 => "Description of property",
          60 => "Address of Appraiser",
        ];
        $groupTitle = 'In Kind donation fields';
        foreach ($customFields as $length => $name) {
          $id = CRM_Core_BAO_CustomField::getCustomFieldID($name, $groupTitle);
          foreach ($fields as $key => $value) {
            if (strpos($key, 'custom_' . $id) !== false && !empty($value)) {
              if (strlen($value) > $length) {
                $errors[$key] = ts('%1 should not be more than %2 characters', [1 => $name, 2 => $length]);
              }
            }
          }
        }
      }
    }
  }
}

function cdntaxreceipts_civicrm_post($op, $objectName, $objectId, &$objectRef) {

  // Handle saving of description of advantage
  if ($objectName == "Contribution" && ($op == 'create' || $op == 'edit')) {
    if (CRM_Utils_Array::value('advantage_description', $_POST)) {
      cdntaxreceipts_advantage($objectId, $_POST['advantage_description']);
    }
  }
}

/**
 * Implementation of hook_civicrm_postProcess().
 *
 * Called when a form comes back for processing. Basically, we want to process
 * the button we added in cdntaxreceipts_civicrm_buildForm().
 */

function cdntaxreceipts_civicrm_postProcess( $formName, &$form ) {

  // first check whether I really need to process this form
  if ( ! is_a( $form, 'CRM_Contribute_Form_ContributionView' ) ) {
    return;
  }

  $types = array('issue_tax_receipt','view_tax_receipt');
  $action = '';
  foreach($types as $type) {
    $post = '_qf_ContributionView_submit_'.$type;
    if (isset($_POST[$post])) {
      if ($_POST[$post] == E::ts('Tax Receipt') || $_POST[$post] == 1) {
        $action = $post;
      }
    }
  }
  if (empty($action)) {
    return;
  }

  // the tax receipt button has been pressed.  redirect to the tax receipt 'view' screen, preserving context.
  $contributionId = $form->get( 'id' );
  $contactId = $form->get( 'cid' );

  $session = CRM_Core_Session::singleton();
  $session->pushUserContext(CRM_Utils_System::url('civicrm/contact/view/contribution',
    "reset=1&id=$contributionId&cid=$contactId&action=view&context=contribution&selectedChild=contribute"
  ));

  $urlParams = array('reset=1', 'id='.$contributionId, 'cid='.$contactId);
  CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/cdntaxreceipts/view', implode('&',$urlParams)));
}

/**
 * Implementation of hook_civicrm_searchTasks().
 *
 * For users with permission to issue tax receipts, give them the ability to do it
 * as a batch of search results.
 */

function cdntaxreceipts_civicrm_searchTasks($objectType, &$tasks ) {
  if ( $objectType == 'contribution' && CRM_Core_Permission::check( 'issue cdn tax receipts' ) ) {
    $single_in_list = FALSE;
    $aggregate_in_list = FALSE;
    foreach ($tasks as $key => $task) {
      if($task['class'] == 'CRM_Cdntaxreceipts_Task_IssueSingleTaxReceipts') {
        $single_in_list = TRUE;
      }
    }
    foreach ($tasks as $key => $task) {
      if($task['class'] == 'CRM_Cdntaxreceipts_Task_IssueAggregateTaxReceipts') {
        $aggregate_in_list = TRUE;
      }
    }
    if (!$single_in_list) {
      $tasks[] = array (
        'title' => ts('Issue Tax Receipts (Separate Receipt for Each Contribution)', array('domain' => 'org.civicrm.cdntaxreceipts')),
        'class' => 'CRM_Cdntaxreceipts_Task_IssueSingleTaxReceipts',
        'result' => TRUE);
    }
    if (!$aggregate_in_list) {
      $tasks[] = array (
        'title' => ts('Issue Tax Receipts (Combined Receipt with Total Contributed)'),
        'class' => 'CRM_Cdntaxreceipts_Task_IssueAggregateTaxReceipts',
        'result' => TRUE);
    }
  }
  elseif ( $objectType == 'contact' && CRM_Core_Permission::check( 'issue cdn tax receipts' ) ) {
    $annual_in_list = FALSE;
    foreach ($tasks as $key => $task) {
      if($task['class'] == 'CRM_Cdntaxreceipts_Task_IssueAnnualTaxReceipts') {
        $annual_in_list = TRUE;
      }
    }
    if (!$annual_in_list) {
      $tasks[] = array (
        'title' => ts('Issue Annual Tax Receipts'),
        'class' => 'CRM_Cdntaxreceipts_Task_IssueAnnualTaxReceipts',
        'result' => TRUE);
    }
  }
}

/**
 * Implementation of hook_civicrm_permission().
 */
function cdntaxreceipts_civicrm_permission( &$permissions ) {
  $prefix = ts('CiviCRM CDN Tax Receipts') . ': ';
  $permissions += array(
    'issue cdn tax receipts' => $prefix . ts('Issue Tax Receipts', array('domain' => 'org.civicrm.cdntaxreceipts')),
  );
}


/**
 * API should use the CDN Tax Receipts permission.
 */
function cdntaxreceipts_civicrm_alterAPIPermissions($entity, $action, &$params, &$permissions) {
  $permissions['cdntaxreceipts']['generate'] = ['issue cdn tax receipts'];
}

/**
 * Implements hook_civicrm_config().
 */
function cdntaxreceipts_civicrm_config(&$config) {
  _cdntaxreceipts_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 */
function cdntaxreceipts_civicrm_xmlMenu(&$files) {
  _cdntaxreceipts_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 */
function cdntaxreceipts_civicrm_install() {
  // copy tables civicrm_cdntaxreceipts_log and civicrm_cdntaxreceipts_log_contributions IF they already exist
  // Issue: #1
  return _cdntaxreceipts_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 */
function cdntaxreceipts_civicrm_postInstall() {
  _cdntaxreceipts_civix_civicrm_postInstall();
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function cdntaxreceipts_civicrm_uninstall() {
  return _cdntaxreceipts_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 */
function cdntaxreceipts_civicrm_enable() {
  CRM_Core_Session::setStatus(ts('Configure the Tax Receipts extension at Administer >> CiviContribute >> CDN Tax Receipts.', array('domain' => 'org.civicrm.cdntaxreceipts')));
  return _cdntaxreceipts_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 */
function cdntaxreceipts_civicrm_disable() {
  return _cdntaxreceipts_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 */
function cdntaxreceipts_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _cdntaxreceipts_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function cdntaxreceipts_civicrm_managed(&$entities) {
  return _cdntaxreceipts_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_caseTypes
 */
function cdntaxreceipts_civicrm_caseTypes(&$caseTypes) {
  _cdntaxreceipts_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules
 */
function cdntaxreceipts_civicrm_angularModules(&$angularModules) {
  _cdntaxreceipts_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsFolders
 */
function cdntaxreceipts_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _cdntaxreceipts_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
 */
function cdntaxreceipts_civicrm_entityTypes(&$entityTypes) {
  _cdntaxreceipts_civix_civicrm_entityTypes($entityTypes);

  $entityTypes = array_merge($entityTypes, [
    'CRM_Cdntaxreceipts_DAO_CdnAdvantage' => [
      'name' => 'CdnAdvantage',
      'class' => 'CRM_Cdntaxreceipts_DAO_CdnAdvantage',
      'table' => 'cdntaxreceipts_advantage',
    ],
  ]);
}

/**
 * Implements hook_civicrm_themes().
 */
function cdntaxreceipts_civicrm_themes(&$themes) {
  _cdntaxreceipts_civix_civicrm_themes($themes);
}

/**
 * Implementation of hook_civicrm_navigationMenu
 *
 * Add entries to the navigation menu, automatically removed on uninstall
 */

function cdntaxreceipts_civicrm_navigationMenu(&$params) {

  // Check that our item doesn't already exist
  $cdntax_search = array('url' => 'civicrm/cdntaxreceipts/settings?reset=1');
  $cdntax_item = array();
  CRM_Core_BAO_Navigation::retrieve($cdntax_search, $cdntax_item);

  if ( ! empty($cdntax_item) ) {
    return;
  }

  // Get the maximum key of $params using method mentioned in discussion
  // https://issues.civicrm.org/jira/browse/CRM-13803
  $navId = CRM_Core_DAO::singleValueQuery("SELECT max(id) FROM civicrm_navigation");
  if (is_integer($navId)) {
    $navId++;
  }
  // Find the Memberships menu
  foreach($params as $key => $value) {
    if ('Administer' == $value['attributes']['name']) {
      $parent_key = $key;
      foreach($value['child'] as $child_key => $child_value) {
        if ('CiviContribute' == $child_value['attributes']['name']) {
          $params[$parent_key]['child'][$child_key]['child'][$navId] = array (
            'attributes' => array (
              'label' => ts('CDN Tax Receipts',array('domain' => 'org.civicrm.cdntaxreceipts')),
              'name' => 'CDN Tax Receipts',
              'url' => 'civicrm/cdntaxreceipts/settings?reset=1',
              'permission' => 'access CiviContribute,administer CiviCRM',
              'operator' => 'AND',
              'separator' => 2,
              'parentID' => $child_key,
              'navID' => $navId,
              'active' => 1
            )
          );
        }
      }
    }
  }
}

function cdntaxreceipts_civicrm_validate( $formName, &$fields, &$files, &$form ) {
  if ($formName == 'CRM_Cdntaxreceipts_Form_Settings') {
    $errors = array();
    $allowed = array('gif', 'png', 'jpg', 'pdf');
    foreach ($files as $key => $value) {
      if (CRM_Utils_Array::value('name', $value)) {
        $ext = pathinfo($value['name'], PATHINFO_EXTENSION);
        if (!in_array($ext, $allowed)) {
          $errors[$key] = ts('Please upload a valid file. Allowed extensions are (.gif, .png, .jpg, .pdf)');
        }
      }
    }
    return $errors;
  }
}

function cdntaxreceipts_civicrm_alterMailParams(&$params, $context) {
  /*
    When CiviCRM core sends receipt email using CRM_Core_BAO_MessageTemplate, this hook was invoked twice:
    - once in CRM_Core_BAO_MessageTemplate::sendTemplate(), context "messageTemplate"
    - once in CRM_Utils_Mail::send(), which is called by CRM_Core_BAO_MessageTemplate::sendTemplate(), context "singleEmail"

    Hence, cdntaxreceipts_issueTaxReceipt() is called twice, sending 2 receipts to archive email.

    To avoid this, only execute this hook when context is "messageTemplate"
  */
  if( $context != 'messageTemplate'){
    return;
  }

  $msg_template_types = array('contribution_online_receipt', 'contribution_offline_receipt', 'membership_online_receipt', 'membership_offline_receipt');

  // Both of these are replaced by the same value of 'workflow' in 5.47
  $groupName = isset($params['groupName']) ? $params['groupName'] : (isset($params['workflow']) ? $params['workflow'] : '');
  $valueName = isset($params['valueName']) ? $params['valueName'] : (isset($params['workflow']) ? $params['workflow'] : '');

  if (($groupName == 'msg_tpl_workflow_contribution' || $groupName == 'contribution_online_receipt' || $groupName == 'contribution_offline_receipt' || $groupName == 'membership_online_receipt')
      && in_array($valueName, $msg_template_types)) {

    // get the related contribution id for this message
    if (isset($params['tplParams']['contributionID'])) {
      $contribution_id = $params['tplParams']['contributionID'];
    }
    else if( isset($params['contributionId'])) {
      $contribution_id = $params['contributionId'];
    }
    else {
      return;
    }

    // is the extension configured to send receipts attached to automated workflows?
    if (!Civi::settings()->get('attach_to_workflows')) {
      return;
    }

    // is this particular donation receiptable?
    if (!cdntaxreceipts_eligibleForReceipt($contribution_id)) {
      return;
    }

    $contribution = new CRM_Contribute_DAO_Contribution();
    $contribution->id = $contribution_id;
    $contribution->find(TRUE);

    $nullVar = NULL;
    list($ret, $method, $pdf_file) = cdntaxreceipts_issueTaxReceipt(
      $contribution,
      $nullVar,
      CDNTAXRECEIPTS_MODE_WORKFLOW
    );

    if ($ret) {
      $attachment = [
        'fullPath' => $pdf_file,
        'mime_type' => 'application/pdf',
        'cleanName' => basename($pdf_file),
      ];
      $params['attachments'] = [$attachment];
    }

  }

}

/**
 * On upgrade to 1.9 we try to determine the financial type for in-kind, but if
 * they had changed the name we won't be able to. So direct them to the settings
 * page to pick it manually.
 */
function cdntaxreceipts_civicrm_check(&$messages, $statusNames = [], $includeDisabled = FALSE) {
  if (!empty($statusNames) && !isset($statusNames['cdntaxreceiptsInkind'])) {
    return;
  }
  if (!$includeDisabled) {
    $disabled = \Civi\Api4\StatusPreference::get()
      ->setCheckPermissions(FALSE)
      ->addWhere('is_active', '=', FALSE)
      ->addWhere('domain_id', '=', 'current_domain')
      ->addWhere('name', '=', 'cdntaxreceiptsInkind')
      ->execute()->count();
    if ($disabled) {
      return;
    }
  }
  // If we know the financial type, we're good.
  if (Civi::settings()->get('cdntaxreceipts_inkind')) {
    return;
  }
  // If the custom fields don't exist, then inkind was never set up, so we're
  // good.
  $inkind_custom = \Civi\Api4\CustomGroup::get(FALSE)
    ->addSelect('id')
    ->addWhere('name', '=', 'In_kind_donation_fields')
    ->execute()->first();
  if (empty($inkind_custom['id'])) {
    return;
  }
  $messages[] = new CRM_Utils_Check_Message(
    'cdntaxreceiptsInkind',
    '<p>'
    . E::ts('In-kind receipts appear to have been configured, but the financial type may have changed and it can not be determined automatically. Please visit <a %1>the settings page</a> and select the financial type being used for In-kind.', [1 => 'href="' . CRM_Utils_System::url('civicrm/cdntaxreceipts/settings', 'reset=1') . '"'])
    . '</p>',
    E::ts('In-kind financial type is unknown'),
    \Psr\Log\LogLevel::ERROR,
    'fa-money'
  );
}
