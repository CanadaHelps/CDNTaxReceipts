<?php

require_once 'cdntaxreceipts.civix.php';
require_once 'cdntaxreceipts.functions.inc';
require_once 'cdntaxreceipts.db.inc';

use CRM_Cdntaxreceipts_ExtensionUtil as E;

define('CDNTAXRECEIPTS_MODE_BACKOFFICE', 1);
define('CDNTAXRECEIPTS_MODE_PREVIEW', 2);
define('CDNTAXRECEIPTS_MODE_WORKFLOW', 3);
define('CDNTAXRECEIPTS_MODE_API', 4);

/**
 * Implements hook_civicrm_buildForm().
 */
function cdntaxreceipts_civicrm_buildForm($formName, &$form) {

  // CH Customization: CRM-1168 Incorrect pop-up message appears when a tax receipt is issued after previewing it
  // Displays notification message right after clicking on "Preview"
  // postProcess for preview was disabled and replaced with logic below
  // civicrm/ajax/makePreviewWork (added via cdntaxreceipts_civicrm_alterMenu) calls CRM_Canadahelps_ExtensionUtils::singleTaxReceiptPreview
  if ($formName == 'CRM_Cdntaxreceipts_Task_IssueSingleTaxReceipts') {
    Civi::resources()->addVars('receipts', array('contributionIds' => $form->getVar('_contributionIds')));
  }

  // CH Customization: CRM-1235 DMS - After Signature/Logo is uploaded in Receipt Settings, page continues to display "No File Chosen"
  if (is_a( $form, 'CRM_Cdntaxreceipts_Form_Settings')) {
    cdntaxreceipts_checkReceiptImages($formName);
  }
  
  if (is_a($form, 'CRM_Contribute_Form_ContributionView')) {
    // add "Issue Tax Receipt" button to the "View Contribution" page
    // if the Tax Receipt has NOT yet been issued -> display a white maple leaf icon
    // if the Tax Receipt has already been issued -> display a red maple leaf icon
    $contributionId = $form->get('id');

    if (isset($contributionId) && cdntaxreceipts_eligibleForReceipt($contributionId)) {
      Civi::resources()->addStyleFile('org.civicrm.cdntaxreceipts', 'css/civicrm_cdntaxreceipts.css');
      list($issued_on, $receipt_id) = cdntaxreceipts_issued_on($contributionId);
      $is_original_receipt = empty($issued_on);
      $subName = 'view_tax_receipt';

      if ($is_original_receipt) {
        $subName = 'issue_tax_receipt';
      }

      $buttons = [
        [
          'type' => 'cancel',
          'name' => ts('Done'),
          'spacing' => '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;',
          'isDefault' => TRUE,
        ],
        [
          'type'      => 'submit',
          'subName'   => $subName,
          'name'      => E::ts('Tax Receipt'),
          'isDefault' => FALSE,
          'icon'      => 'fa-check-square',
        ],
      ];

      $form->addButtons($buttons);
    }
  }

  // CH Customization:
  // @todo move css to /sass/taxreceipts.scss
  if (is_a( $form, 'CRM_Contribute_Form_Task_Result')) {
    $data = &$form->controller->container();
    if(isset($data['valid'])){
      if( isset($data['valid']['IssueAggregateTaxReceipts']) ) {
        CRM_Core_Resources::singleton()->addStyle('
          .crm-button {
            float: left !important;
            border: none 0 !important;
            text-shadow: none !important;
            margin-bottom: 20px !important;
          }
          .crm-form-submit i {
            display: none !important;
          }
          button {
            background: #1466A9 !important;
            color: #fff;
            font-family: "Lato" !important;
            font-size: 14px !important;
            text-transform: none !important;
            line-height: 1;
            text-shadow: none !important;
            vertical-align: middle;
            box-shadow: 0px 0px 3px 0 rgba(0,0,0,0.2) !important;
            padding: 10px 20px !important;
          }
        ');
      }
    }
  }
}


/**
 * Implementation of hook_civicrm_postProcess().
 *
 * Called when a form comes back for processing. Basically, we want to process
 * the button we added in cdntaxreceipts_civicrm_buildForm().
 */

function cdntaxreceipts_civicrm_postProcess($formName, &$form) {
  // CH Customization: CRM-1203 User should be notified that an annual tax receipt has been already issued for a contact
  if (is_a( $form, 'CRM_Cdntaxreceipts_Task_IssueAnnualTaxReceipts')) {
    $submitValue = $form->getVar('_submitValues');
    if(isset($submitValue['receipt_year'])) {
      $receipt_year = $submitValue['receipt_year'];
      if (!empty($receipt_year)) {
        $receiptYear  = substr($receipt_year, strlen('issue_')); // e.g. issue_2012
      }
      $contactIDS = $form->getVar('_contactIds');
      if(count($contactIDS) == 1 && !empty($receiptYear)) {
        $contactId = $contactIDS[0];
        list( $issuedOn, $receiptId ) = cdntaxreceipts_annual_issued_on($contactId, $receiptYear);
        $contributions = cdntaxreceipts_contributions_not_receipted($contactId, $receiptYear);
        if ( !empty($issuedOn) && count($contributions) > 0 ) {
          $contact = civicrm_api3('Contact', 'get', [
            'sequential' => 1,
            'return' => ['first_name','last_name','display_name'],
            'id' => $contactId,
          ]);
          if($contact['values']) {
            $display_name = $contact['values'][0]['display_name'];
            $first_name = $contact['values'][0]['first_name'];
            $last_name = $contact['values'][0]['last_name'];
          }
          $contactlink = CRM_Utils_System::url('dms/contact/view', "reset=1&cid=$contactId");
          if (empty($display_name)) {
            $noticeDisplayName = $first_name.''.$last_name;
          } else {
            $noticeDisplayName = $display_name;
          }
          $displayData = ' <a href="'.$contactlink.'">'.$noticeDisplayName.'</a>';
          $status = ts("An annual tax receipt for %2 contributions by %1 has been already been issued, and cannot be re-issued.", array(1=>$displayData,2=>$receiptYear, 'domain' => 'org.civicrm.cdntaxreceipts'));
          CRM_Core_Session::setStatus($status, '', 'info');
        }
      }
    }
  }


  // First check whether I really need to process this form
  if (!is_a($form, 'CRM_Contribute_Form_ContributionView')) {
    return;
  }

  // Is it one of our tax receipt buttons?
  $buttonName = $form->controller->getButtonName();
  if ($buttonName !== '_qf_ContributionView_submit_issue_tax_receipt' && $buttonName !== '_qf_ContributionView_submit_view_tax_receipt') {
    return;
  }

  // the tax receipt button has been pressed.  redirect to the tax receipt 'view' screen, preserving context.
  $contributionId = $form->get('id');
  $contactId = $form->get('cid');

  $session = CRM_Core_Session::singleton();
  $session->pushUserContext(CRM_Utils_System::url('civicrm/contact/view/contribution',
    "reset=1&id=$contributionId&cid=$contactId&action=view&context=contribution&selectedChild=contribute"
  ));

  CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/cdntaxreceipts/view', "reset=1&id=$contributionId&cid=$contactId"));
}

/**
 * Implements hook_civicrm_searchTasks().
 *
 * For users with permission to issue tax receipts, give them the ability to do it
 * as a batch of search results.
 */
function cdntaxreceipts_civicrm_searchTasks($objectType, &$tasks) {
  $isReceiptSettingValidated = (bool) Civi::settings()->get('settings_validated_taxreceipts');
  if (empty($isReceiptSettingValidated)) $isReceiptSettingValidated = 0;
      
  if ($objectType == 'contribution' && CRM_Core_Permission::check('issue cdn tax receipts')) {
    $single_in_list = FALSE;
    $aggregate_in_list = FALSE;
    foreach ($tasks as $key => $task) {
      if ($task['class'] == 'CRM_Cdntaxreceipts_Task_IssueSingleTaxReceipts') {
        $single_in_list = TRUE;
      }
    }
    foreach ($tasks as $key => $task) {
      if ($task['class'] == 'CRM_Cdntaxreceipts_Task_IssueAggregateTaxReceipts') {
        $aggregate_in_list = TRUE;
      }
    }

    // CH Customization: CRM-1918-Disable 'Issue Separate Tax Receipts' action from contributions tab if receipts settings not validated  
    if ($isReceiptSettingValidated) {
      if (!$single_in_list) {
        $tasks[] = [
          'title' => E::ts('Issue Separate Tax Receipts'),
          'class' => 'CRM_Cdntaxreceipts_Task_IssueSingleTaxReceipts',
          'result' => TRUE,
        ];
      }
      if (!$aggregate_in_list) {
        $tasks[] = [
          'title' => ts('Issue Aggregated Tax Receipts'),
          'class' => 'CRM_Cdntaxreceipts_Task_IssueAggregateTaxReceipts',
          'result' => TRUE,
        ];
      }

    }
  }
  elseif ($objectType == 'contact' && CRM_Core_Permission::check('issue cdn tax receipts')) {
    $annual_in_list = FALSE;
    foreach ($tasks as $key => $task) {
      if($task['class'] == 'CRM_Cdntaxreceipts_Task_IssueAnnualTaxReceipts') {
        $annual_in_list = TRUE;
      }
    }
    // CH Customization: CRM-1918-Disable 'Issue Annual Tax Receipts' action from contacts tab if receipts settings not validated
    if ($isReceiptSettingValidated) {
      if (!$annual_in_list) {
        $tasks[] = [
          'title' => E::ts('Issue Annual Tax Receipts'),
          'class' => 'CRM_Cdntaxreceipts_Task_IssueAnnualTaxReceipts',
          'result' => TRUE,
        ];
      }
    }
  }
}

/**
 * Implements hook_civicrm_permission().
 */
function cdntaxreceipts_civicrm_permission( &$permissions ) {
  $prefix = E::ts('CiviCRM CDN Tax Receipts') . ': ';
  $permissions += [
    'issue cdn tax receipts' => $prefix . E::ts('Issue Tax Receipts'),
  ];
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
 * Implements hook_civicrm_install().
 */
function cdntaxreceipts_civicrm_install() {
  // copy tables civicrm_cdntaxreceipts_log and civicrm_cdntaxreceipts_log_contributions IF they already exist
  // Issue: #1
  return _cdntaxreceipts_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 */
function cdntaxreceipts_civicrm_enable() {
  CRM_Core_Session::setStatus(E::ts('Configure the Tax Receipts extension at Administer >> CiviContribute >> CDN Tax Receipts.'));
  return _cdntaxreceipts_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * Add entries to the navigation menu, automatically removed on uninstall
 */
function cdntaxreceipts_civicrm_navigationMenu(&$params) {
  // Check that our item doesn't already exist
  $cdntax_search = ['url' => 'civicrm/cdntaxreceipts/settings?reset=1'];
  $cdntax_item = [];
  CRM_Core_BAO_Navigation::retrieve($cdntax_search, $cdntax_item);

  if (!empty($cdntax_item)) {
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
          $params[$parent_key]['child'][$child_key]['child'][$navId] = [
            'attributes' => [
              'label' => ts('CDN Tax Receipts',array('domain' => 'org.civicrm.cdntaxreceipts')),
              'name' => 'CDN Tax Receipts',
              'url' => 'civicrm/cdntaxreceipts/settings?reset=1',
              'permission' => 'access CiviContribute,administer CiviCRM',
              'operator' => 'AND',
              'separator' => 2,
              'parentID' => $child_key,
              'navID' => $navId,
              'active' => 1
            ],
          ];
        }
      }
    }
  }
}

function cdntaxreceipts_civicrm_validate($formName, &$fields, &$files, &$form) {
  if ($formName == 'CRM_Cdntaxreceipts_Form_Settings') {
    $errors = [];
    $allowed = ['gif', 'png', 'jpg', 'pdf'];
    foreach ($files as $key => $value) {
      if (CRM_Utils_Array::value('name', $value)) {
        $ext = pathinfo($value['name'], PATHINFO_EXTENSION);
        if (!in_array($ext, $allowed)) {
          $errors[$key] = E::ts('Please upload a valid file. Allowed extensions are (.gif, .png, .jpg, .pdf)');
        }
      }
    }
    return $errors;
  }
}

/**
 * Implements hook_civicrm_alterMailParams().
 */
function cdntaxreceipts_civicrm_alterMailParams(&$params, $context) {
  /*
    When CiviCRM core sends receipt email using CRM_Core_BAO_MessageTemplate, this hook was invoked twice:
    - once in CRM_Core_BAO_MessageTemplate::sendTemplate(), context "messageTemplate"
    - once in CRM_Utils_Mail::send(), which is called by CRM_Core_BAO_MessageTemplate::sendTemplate(), context "singleEmail"

    Hence, cdntaxreceipts_issueTaxReceipt() is called twice, sending 2 receipts to archive email.

    To avoid this, only execute this hook when context is "messageTemplate"
  */
  if ($context != 'messageTemplate') {
    return;
  }

  $msg_template_types = ['contribution_online_receipt', 'contribution_offline_receipt'];

  // Both of these are replaced by the same value of 'workflow' in 5.47
  $groupName = isset($params['groupName']) ? $params['groupName'] : (isset($params['workflow']) ? $params['workflow'] : '');
  $valueName = isset($params['valueName']) ? $params['valueName'] : (isset($params['workflow']) ? $params['workflow'] : '');
  if (($groupName == 'msg_tpl_workflow_contribution' || $groupName == 'contribution_online_receipt' || $groupName == 'contribution_offline_receipt')
      && in_array($valueName, $msg_template_types)) {

    // get the related contribution id for this message
    if (isset($params['tplParams']['contributionID'])) {
      $contribution_id = $params['tplParams']['contributionID'];
    }
    elseif (isset($params['contributionId'])) {
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



/**************************************
* CH Custom Functions
***************************************/



/**
 * Implements hook_civicrm_validateForm().
 *
 * @param string $formName
 * @param array $fields
 * @param array $files
 * @param CRM_Core_Form $form
 * @param array $errors
 */

function cdntaxreceipts_checkReceiptImages($formName) {
  //CRM-1860 If receipt logo is not set pass empty value
  $receipt_logo = Civi::settings()->get('receipt_logo');
  $receipt_logo_url = '';
  if(!empty($receipt_logo)) {
    $receipt_logo_type = pathinfo($receipt_logo, PATHINFO_EXTENSION);
    // If existing value has relative path in it keep it as is otherwise prepand upload directory path
    $imagePath = $receipt_logo;
    if ( substr($imagePath, 0, 1) != "/" )
      $imagePath = CRM_Core_Config::singleton()->customFileUploadDir . $imagePath;
    $receipt_logo_data = file_get_contents($imagePath);
    $receipt_logo_url = 'data:image/' . $receipt_logo_type . ';base64,' . base64_encode($receipt_logo_data);
  }
  //CRM-1860 If receipt signature is not set pass empty value
  $receipt_signature = Civi::settings()->get('receipt_signature');
  $receipt_signature_url= '';
  if(!empty($receipt_signature)) {
    $receipt_signature_type = pathinfo($receipt_signature, PATHINFO_EXTENSION);
    $imagePath = $receipt_signature;
    if ( substr($imagePath, 0, 1) != "/" )
      $imagePath = CRM_Core_Config::singleton()->customFileUploadDir . $imagePath;
    $receipt_signature_data = file_get_contents($imagePath);
    $receipt_signature_url = 'data:image/' . $receipt_signature_type . ';base64,' . base64_encode($receipt_signature_data);
  }
  //CRM-1860 passing receiptLogo and receiptSignature value to javascript
  CRM_Core_Resources::singleton()->addScript(
    "app.initForm(
      '$formName',
      {receiptLogo: '$receipt_logo_url', receiptSignature: '$receipt_signature_url'}
    );
  ");
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
}
