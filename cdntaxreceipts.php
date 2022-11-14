<?php

require_once 'cdntaxreceipts.civix.php';
require_once 'cdntaxreceipts.functions.inc';
require_once 'cdntaxreceipts.db.inc';

define('CDNTAXRECEIPTS_MODE_BACKOFFICE', 1);
define('CDNTAXRECEIPTS_MODE_PREVIEW', 2);
define('CDNTAXRECEIPTS_MODE_WORKFLOW', 3);

function cdntaxreceipts_civicrm_buildForm( $formName, &$form ) {
  if (is_a( $form, 'CRM_Cdntaxreceipts_Form_Settings')) {
    //CRM-1235 DMS - After Signature/Logo is uploaded in Receipt Settings, page continues to display "No File Chosen" 
    $receipt_logo = Civi::settings()->get('receipt_logo');
    $receipt_logo_type = pathinfo($receipt_logo, PATHINFO_EXTENSION);
    $receipt_logo_data = file_get_contents($receipt_logo);
    $receipt_logo_url = 'data:image/' . $receipt_logo_type . ';base64,' . base64_encode($receipt_logo_data);
   
    $receipt_signature = Civi::settings()->get('receipt_signature');
    $receipt_signature_type = pathinfo($receipt_signature, PATHINFO_EXTENSION);
    $receipt_signature_data = file_get_contents($receipt_signature);
    $receipt_signature_url = 'data:image/' . $receipt_signature_type . ';base64,' . base64_encode($receipt_signature_data);
     CRM_Core_Resources::singleton()->addScript(
      "CRM.$(function($) {
        $( '.crm-form-file' ).change(function() {
         var attr_name = this.id;
         $('#'+attr_name).next('.previewImageName').remove();
         $('#'+attr_name).css('color','transparent');
         const file = this.files[0];
         if (file){
          //CRM-1456 No Logo or Signature displaying on Tax Receipts despite Images being uploaded
          var fileType = file['type'];
          var extensionTypes = [];
          extensionTypes['image/png'] = ['png'];
          extensionTypes['image/jpeg'] = ['jpg','jpeg','jfif','pjpeg','pjp'];
          var options = [];
          options.unique = true;
          options.expires = 10000;
          if(fileType in extensionTypes)
          {
            var fileExtensionName = fileType.split('/').pop().toLowerCase();
            if ($.inArray(fileExtensionName, extensionTypes[fileType]) < 0) {
              CRM.alert(\"Image extension doesn't match with file type\", 'Incompatible Image','error' ,options);
            }
          }else 
          {
            CRM.alert(\"This image type is not supported\", 'Invalid Image','error' ,options);
          }
          var fileName = [file.name.split('.').shift(),file.name.split('.').pop().toLowerCase()].join('.');
          let reader = new FileReader();
          reader.onload = function(event){
           var ImagePreviewObj = $('#'+attr_name).parent().find('img').attr('id');
            $('#'+ImagePreviewObj).attr('src', event.target.result);
            $('#'+ImagePreviewObj).parent().find('span').hide();
            $('<p>'+fileName+'</p>').addClass('previewImageName').insertAfter($('#'+attr_name));
          }
          reader.readAsDataURL(file);
         }
        });
        $( document ).ready(function() {
          var receiptLogo = '$receipt_logo_url';
          var receiptSignature = '$receipt_signature_url';
          if(!receiptLogo || receiptLogo.length > 0)
          {
            $('#ReceiptLogoPreview').attr('src', receiptLogo );
            $('#receipt_logo').css('color','transparent');
            $('#receipt_logo').attr('title', ' ' );
          }
          if(!receiptSignature || receiptSignature.length > 0)
          {
            $('#ReceiptSignaturePreview').attr('src', receiptSignature);
            $('#receipt_signature').css('color','transparent');
            $('#receipt_signature').attr('title', ' ' );
          }
        });
      });
    ");
    CRM_Core_Resources::singleton()->addStyle('
    .preview_image {
      max-width: 100px;
      max-height: 100px;
      min-width: 100px;
      min-height: 100px;
    }');
  }
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
        'name'      => ts('Tax Receipt', array('domain' => 'org.civicrm.cdntaxreceipts')),
        'isDefault' => FALSE
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

  if (is_a( $form, 'CRM_Contribute_Form_Task_Result')) {
    $data = &$form->controller->container();
    if(isset($data['valid'])){
      if($data['valid']['IssueAggregateTaxReceipts']) {
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
  if (is_a( $form, 'CRM_Cdntaxreceipts_Form_Settings')) {
    //CRM-1235 DMS - After Signature/Logo is uploaded in Receipt Settings, page continues to display "No File Chosen"
    $receipt_logo = Civi::settings()->get('receipt_logo');
    $receipt_logo_type = pathinfo($receipt_logo, PATHINFO_EXTENSION);
    $receipt_logo_data = file_get_contents($receipt_logo);
    $receipt_logo_url = 'data:image/' . $receipt_logo_type . ';base64,' . base64_encode($receipt_logo_data);
   
    $receipt_signature = Civi::settings()->get('receipt_signature');
    $receipt_signature_type = pathinfo($receipt_signature, PATHINFO_EXTENSION);
    $receipt_signature_data = file_get_contents($receipt_signature);
    $receipt_signature_url = 'data:image/' . $receipt_signature_type . ';base64,' . base64_encode($receipt_signature_data);
    
    CRM_Core_Resources::singleton()->addScript(
      "CRM.$(function($) {
        $( document ).ready(function() {
          var receiptLogo = '$receipt_logo_url';
          var receiptSignature = '$receipt_signature_url';
          if(!receiptLogo || receiptLogo.length > 0)
          {
            $('#ReceiptLogoPreview').attr('src', receiptLogo);
            $('#ReceiptLogoPreview').parent().find('span').hide();
          }
          if(!receiptSignature || receiptSignature.length > 0)
          {
            $('#ReceiptSignaturePreview').attr('src',receiptSignature);
            $('#ReceiptSignaturePreview').parent().find('span').hide();
          }
        });
      });
    ");
  
  }
  // first check whether I really need to process this form
  if ( ! is_a( $form, 'CRM_Contribute_Form_ContributionView' ) ) {
    return;
  }
  $types = array('issue_tax_receipt','view_tax_receipt');
  $action = '';
  foreach($types as $type) {
    $post = '_qf_ContributionView_submit_'.$type;
    if (isset($_POST[$post])) {
      if ($_POST[$post] == ts('Tax Receipt', array('domain' => 'org.civicrm.cdntaxreceipts'))) {
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
 * Implementation of hook_civicrm_config
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
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_entityTypes
 */
function cdntaxreceipts_civicrm_entityTypes(&$entityTypes) {
  _cdntaxreceipts_civix_civicrm_entityTypes($entityTypes);
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
 * Implementation of hook_civicrm_managed
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

  $msg_template_types = array('contribution_online_receipt', 'contribution_offline_receipt');

  if (isset($params['groupName'])
      && $params['groupName'] == 'msg_tpl_workflow_contribution'
      && isset($params['valueName'])
      && in_array($params['valueName'], $msg_template_types)) {

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
      $last_in_path = strrpos($pdf_file, '/');
      $clean_name = substr($pdf_file, $last_in_path);
      $attachment = array(
        'fullPath' => $pdf_file,
        'mime_type' => 'application/pdf',
        'cleanName' => $clean_name,
      );
      $params['attachments'] = array($attachment);
    }

  }

}

