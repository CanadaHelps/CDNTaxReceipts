<?php

require_once('CRM/Core/Form.php');

class CRM_Cdntaxreceipts_Form_ViewTaxReceipt extends CRM_Core_Form {

  protected  $_reissue;
  protected $_receipt;
  protected $_method;
  protected $_sendTarget;
  protected $_pdfFile;
  protected $_isCancelled;

  /**
   * build all the data structures needed to build the form
   *
   * @return void
   * @access public
   */
  function preProcess() {

    //check for permission to view contributions
    if (!CRM_Core_Permission::check('access CiviContribute')) {
      CRM_Core_Error::fatal(ts('You do not have permission to access this page'));
    }
    parent::preProcess();
    $contributionId = CRM_Utils_Array::value('id', $_GET);
    $contactId = CRM_Utils_Array::value('cid', $_GET);

    if ( isset($contributionId) && isset($contactId) ) {
      $this->set('contribution_id', $contributionId);
      $this->set('contact_id', $contactId);
    }
    else {
      $contributionId = $this->get('contribution_id');
      $contactId = $this->get('contact_id');
    }

    // Force recheck receipt settings validation
    // in case invalidated post issuance
    $receiptSettingValidateVal = (bool) Civi::settings()->get('settings_validated_taxreceipts');
    Civi::resources()->addVars('receipts', array('receiptSettingsValidated' => $receiptSettingValidateVal));

    list($issuedOn, $receiptId) = cdntaxreceipts_issued_on($contributionId);

    if (isset($receiptId)) {
      $existingReceipt = cdntaxreceipts_load_receipt($receiptId);

      // Force re-check eligibility, in case it changed after issuance
      $contribObject = json_decode(json_encode($existingReceipt['contributions'][0]));
      $contribObject->id = $contribObject->contribution_id;
      $isEligible = canadahelps_isContributionEligibleForReceipting($contribObject, true);
      Civi::resources()->addVars('receipts', array('receiptIsEligible' => $isEligible));

      //CRM-1821 Show replaced receipt info on the Tax Receipt details page after receipt being replaced successfully
      if ($existingReceipt['receipt_status'] == 'issued') {
        list($receipt_number, $receipt_id) = CRM_Canadahelps_TaxReceipts_Receipt::receiptNumber($contributionId,TRUE);
        if(isset($receipt_number))
        $existingReceipt['cancelled_receipt_number'] = $receipt_number;
      }
      $this->_receipt = $existingReceipt;
      $this->_reissue = 1;

      if ($existingReceipt['receipt_status'] == 'cancelled') {
        $this->_isCancelled = 1;
      }
      else {
        $this->_isCancelled = 0;
      }
    }
    else {
      $this->_receipt = array();
      $this->_reissue = 0;
      $this->_isCancelled = 0;
    }

    // might be callback to retrieve the downloadable PDF file
    $download = CRM_Utils_Array::value('download', $_GET);
    if ( $download == 1 ) {
      $this->sendFile($contributionId, $contactId); // exits
    } 
    list($method, $email) = cdntaxreceipts_sendMethodForContact($contactId);
    if ($this->_isCancelled == 1 && $method != 'data') {
      $method = 'print';
    }
    $this->_method = $method;

    if ($method == 'email') {
      $this->_sendTarget = $email;
    }

    // may need to offer a PDF file for download, if returning from form submission.
    // this sets up the form with proper JS to download the file, it doesn't actually send the file.
    // see ?download=1 for sending the file.
    $pdfDownload = CRM_Utils_Array::value('file', $_GET);
    if ($pdfDownload == 1) {
      $this->_pdfFile = 1;
    }

  }

  /**
   * Build the form
   *
   * @access public
   *
   * @return void
   */
  function buildQuickForm() {

    //CRM-917: Add Custom Stylesheet to pages as well
    CRM_Core_Resources::singleton()->addStyleFile('org.civicrm.cdntaxreceipts', 'css/receipt_module.css');

    if ($this->_reissue) {
      $receipt_contributions = array();
      foreach ( $this->_receipt['contributions'] as $c ) {
        $receipt_contributions[] = $c['contribution_id'];
      }

      CRM_Utils_System::setTitle('Tax Receipt');
      $buttonLabel = ts('Replace Receipt', array('domain' => 'org.civicrm.cdntaxreceipts'));
      $this->assign('reissue', 1);
      $this->assign('receipt', $this->_receipt);
      $this->assign('contact_id', $this->_receipt['contact_id']);
      $this->assign('contribution_id', $this->get('contribution_id'));
      $this->assign('receipt_contributions', $receipt_contributions);
      $this->assign('isCancelled', $this->_isCancelled);
    }
    else {
      CRM_Utils_System::setTitle('Tax Receipt');
      $buttonLabel = ts('Issue Tax Receipt', array('domain' => 'org.civicrm.cdntaxreceipts'));
      $this->assign('reissue', 0);
      $this->assign('isCancelled', 0);
    }

    $buttons = array();

    $buttons[] = array(
      'type' => 'cancel',
      'name' => ts('Back', array('domain' => 'org.civicrm.cdntaxreceipts')),
    );

    if (CRM_Core_Permission::check( 'issue cdn tax receipts' ) ) {

      // Void Button (when already issued)
      if ($this->_reissue && !$this->_isCancelled) {
        $buttons[] = array(
          'type' => 'submit',
          'name' => ts('Void Receipt', array('domain' => 'org.civicrm.cdntaxreceipts')),
          'isDefault' => FALSE,
          'class' => 'void-receipt',
        );

      // Issue Button
      } else {
         // @todo $buttonLabel "Issue Tax Receipt" or "Replace Receipt"
        $buttons[] = array(
          'type' => 'next',
          'name' => $buttonLabel,
          'isDefault' => TRUE,
          'class' => 'issue-receipt' . ( ($this->_isCancelled) ? ' replace-receipt' : ''),
        );
      }

      // Add Preview Button (when void or nor yet issued)
      if ($this->_isCancelled || !$this->_reissue) {
        $buttons[] = array(
          'type' => 'submit',
          'name' => ts('Preview', array('domain' => 'org.civicrm.cdntaxreceipts')),
          'isDefault' => FALSE,
          'class' => 'preview-receipt',
        );
      }

      // Download Button (when already issued)
      // @todo: changed button type to process -> need to adjust other code pieces
      if ($this->_reissue) {
        $buttons[] = array(
          'type' => 'upload',
          'name' => ts('Download Receipt', array('domain' => 'org.civicrm.cdntaxreceipts')),
          'isDefault' => TRUE,
          'class' => 'download-receipt',
        );
      }
    }
    $this->addButtons($buttons);

    $this->assign('buttonLabel', $buttonLabel);

    $this->assign('method', $this->_method);

    //CRM-921: Add delivery Method to form
    $delivery_method = CRM_Core_BAO_Setting::getItem(CDNTAX_SETTINGS, 'delivery_method');
    $delivery_placeholder = null;
    $delivery_options = [];

    $delivery_options[CDNTAX_DELIVERY_PRINT_ONLY] = 'Print';
    $delivery_options[CDNTAX_DELIVERY_PRINT_EMAIL] = 'Email';
    $this->add('select',
      'delivery_method',
      ts('Method'),
      $delivery_options,
      FALSE,
      ['class' => 'crm-select2']
    );

    // Add Thank-you Setting block
    $this->add('checkbox', 'thankyou_date', ts('Mark Contribution as thanked', array('domain' => 'org.civicrm.cdntaxreceipts')));
    $this->add('checkbox', 'thankyou_email', ts('Send a custom Thank You Message', array('domain' => 'org.civicrm.cdntaxreceipts')));

    if ( $this->_method == 'email' ) {
      $this->assign('receiptEmail', $this->_sendTarget);
    }

    if ( isset($this->_pdfFile) ) {
      $this->assign('pdf_file', $this->_pdfFile);
    }

    //CRM-921: Integrate WYSWIG Editor on the form
    CRM_Contact_Form_Task_PDFLetterCommon::buildQuickForm($this);
    if($this->elementExists('from_email_address')) {
      $this->removeElement('from_email_address');
    }
    $from_email_address = current(CRM_Core_BAO_Domain::getNameAndEmail(FALSE, TRUE));
    //CRM-1596 "From Email Address" value being passed as attributes
    $this->add('text', 'from_email_address', ts('From Email Address'), array('value' => $from_email_address), TRUE);
    $this->add('text', 'email_options', ts('Print and Email Options'), array('value' => 'email'), FALSE);
    $this->add('text', 'group_by_separator', ts('Group By Seperator'), array('value' => 'comma'), FALSE);
    $defaults = [
      'margin_left' => 0.75,
      'margin_right' => 0.75,
      'margin_top' => 0.75,
      'margin_bottom' => 0.75,
      'email_options' => 'email',
      'from_email_address' => $from_email_address,
      'group_by_separator' => 'comma',
      'thankyou_date' => 1
    ];
    $this->setDefaults($defaults);
    $this->addButtons($buttons);

    //Add Tokens
    $tokens = CRM_Cdntaxreceipts_Task_PDFLetterCommon::listTokens();
    $this->assign('tokens', CRM_Utils_Token::formatTokensForDisplay($tokens));

    $templates = CRM_Core_BAO_MessageTemplate::getMessageTemplates(FALSE);
    if($this->elementExists('template')) {
      $this->removeElement('template');
      $this->assign('templates', TRUE);
      $this->add('select', "template", ts('Use Template'),
        ['default' => 'Default Message'] + $templates + ['0' => ts('Other Custom')], FALSE,
        ['onChange' => "selectValue( this.value, '');"]
      );
    }
  }

  /**
   * process the form after the input has been submitted and validated
   *
   * @access public
   *
   * @return None
   */

  function postProcess() {

    // ensure the user has permission to cancel or issue the tax receipt.
    if ( ! CRM_Core_Permission::check( 'issue cdn tax receipts' ) ) {
      CRM_Core_Error::fatal(ts('You do not have permission to access this page', array('domain' => 'org.civicrm.cdntaxreceipts')));
    }

    $method = '';

    // load the contribution
    $contributionId = $this->get('contribution_id');
    $contactId = $this->get('contact_id');

    $contribution =  new CRM_Contribute_DAO_Contribution();
    $contribution->id = $contributionId;
    
    if ( ! $contribution->find( TRUE ) ) {
      CRM_Core_Error::fatal( "CDNTaxReceipts: Could not retrieve details for this contribution" );
    }
   
    $buttonName = $this->controller->getButtonName();

    //CRM-1820 Once the receipt has been cancelled and user wants to preview or issue "Replace Receipt"
    if ($this->_reissue && $this->_isCancelled && ($buttonName == '_qf_ViewTaxReceipt_submit' || $buttonName == '_qf_ViewTaxReceipt_next')){
      list($receipt_number, $receipt_id) = CRM_Canadahelps_TaxReceipts_Receipt::receiptNumber($contribution->id,TRUE);
       //CRM-1977 if receipt status is 'cancelled' and receipt type is aggregated
      list($issuedOn, $receiptId) = cdntaxreceipts_issued_on($contribution->id);
      $existingReceipt = cdntaxreceipts_load_receipt($receipt_id);
      if ($existingReceipt['receipt_status'] == 'cancelled' && $existingReceipt['issue_type'] == 'aggregate') {
        $aggregatedReceiptContributionList = array_column($existingReceipt['contributions'],'contribution_id');
        //CRM-1993 if aggregate receipt has only single contribution in that case for issuing seperate or manage receipt 'cancel and replace receipt number' text should be visible.
        if(count($aggregatedReceiptContributionList) == 1 && $aggregatedReceiptContributionList[0] == $contribution->id )
        {
          $contribution->cancelled_replace_receipt_number  = $receipt_number;
          $contribution->replace_receipt  = 1;
        }else{
          $contribution->replace_receipt  = 1;
        }  
      }else{
        $contribution->cancelled_replace_receipt_number  = $receipt_number;
        $contribution->replace_receipt  = 1;
      }   
    }
    
    // If we are cancelling the tax receipt (or preview)
    if ($buttonName == '_qf_ViewTaxReceipt_submit') {

      // Preview
      if (!$this->_reissue || $this->_isCancelled) {
        $receiptsForPrinting = cdntaxreceipts_openCollectedPDF();
        $previewMode = TRUE;
        list($result, $method, $pdf) = cdntaxreceipts_issueTaxReceipt( $contribution,  $receiptsForPrinting, $previewMode );
        if ($result == TRUE) {
          cdntaxreceipts_sendCollectedPDF($receiptsForPrinting, 'Receipt-To-Print-' . (int) $_SERVER['REQUEST_TIME'] . '.pdf');
        } else {
          $statusMsg = ts('Encountered an error. Tax receipt has not been issued.', array('domain' => 'org.civicrm.cdntaxreceipts'));
          CRM_Core_Session::setStatus($statusMsg, '', 'error');
        }
        // exits
        return;
      }

      // Void Receipt
      // Get the Tax Receipt that has already been issued previously for this Contribution
      list($issued_on, $receipt_id) = cdntaxreceipts_issued_on($contribution->id);

      $result = cdntaxreceipts_cancel($receipt_id);

      if ($result == TRUE) {
        //CRM-1907-Generate Cancelled receipt PDF
        //CRM-1864 if receipt logo / signature is not uploaded while void receipt issuance, system won't issue receipt.
        $voidReceiptStatus = cdnaxreceipts_manageVoidPDF($receipt_id,$contributionId);
        if($voidReceiptStatus === FALSE){
          $statusMsg = ts('Tax Receipt has been cancelled but tax receipt can not be generated.', array('domain' => 'org.civicrm.cdntaxreceipts'));
        }else{
          $statusMsg = ts('Tax Receipt has been cancelled.', array('domain' => 'org.civicrm.cdntaxreceipts'));
        }
      }
      else {
        $statusMsg = ts('Encountered an error. Tax receipt has not been cancelled.', array('domain' => 'org.civicrm.cdntaxreceipts'));
      }
      CRM_Core_Session::setStatus($statusMsg, '', 'error');
      // refresh the form, with file stored in session if we need it.
      $urlParams = array('reset=1', 'cid='.$contactId, 'id='.$contributionId);
      //CRM-1907 - After cancelling recipt download automatically
      if($result == TRUE && $voidReceiptStatus !== FALSE)
      {
        $urlParams[] = 'file=1';
      }

    // Issue Receipt
    } else {
      // issue tax receipt, or report error if ineligible
      if ( ! cdntaxreceipts_eligibleForReceipt($contribution->id) ) {
        $statusMsg = ts('This contribution is not tax deductible and/or not completed. No receipt has been issued.', array('domain' => 'org.civicrm.cdntaxreceipts'));
        CRM_Core_Session::setStatus($statusMsg, '', 'error');
      } else {
        $params = $this->controller->exportValues($this->_name);

        if($this->getElement('thankyou_email')->getValue()) {
          if($this->getElement('html_message')->getValue()) {
            if(isset($params['template'])) {
              if($params['template'] !== 'default') {
                $this->_contributionIds = [$contribution->id];
                $from_email_address = current(CRM_Core_BAO_Domain::getNameAndEmail(FALSE, TRUE));
                if($from_email_address) {
                  $data = &$this->controller->container();
                  $data['values']['ViewTaxReceipt']['from_email_address'] = $from_email_address;
                  $data['values']['ViewTaxReceipt']['subject'] = $this->getElement('subject')->getValue();
                  $data['values']['ViewTaxReceipt']['html_message'] = $this->getElement('html_message')->getValue();
                  $thankyou_html = CRM_Cdntaxreceipts_Task_PDFLetterCommon::postProcessForm($this, $params);
                  if($thankyou_html) {
                    if(is_array($thankyou_html)) {
                      $contribution->thankyou_html = array_values($thankyou_html)[0];
                    } else {
                      $contribution->thankyou_html = $thankyou_html;
                    }
                  }
                }
              }
            }
          }
        }
  
        list($result, $method, $pdf) = cdntaxreceipts_issueTaxReceipt( $contribution );

        if ($result == TRUE) {
          //CRM-921: Mark Contribution as thanked if checked
          if($this->getElement('thankyou_date')->getValue()) {
            $contribution->thankyou_date = date('Y-m-d H:i:s', CRM_Utils_Time::time());
          }
          //CRM-1959
          $contributionReceiptDate = cdnaxreceipts_getReceiptDate($contributionId);
          if($contributionReceiptDate && !empty($contributionReceiptDate))
          {
            $contribution->receipt_date = $contributionReceiptDate;
            $contribution->save();
          }

          if ($method == 'email') {
            $statusMsg = ts('Tax Receipt has been emailed to the contributor.', array('domain' => 'org.civicrm.cdntaxreceipts'));
          }
          else if ($method == 'print') {
            $statusMsg = ts('Tax Receipt has been generated for printing.', array('domain' => 'org.civicrm.cdntaxreceipts'));
          }
          else if ($method == 'data') {
            $statusMsg = ts('Tax Receipt data is available in the Tax Receipts Issued report.', array('domain' => 'org.civicrm.cdntaxreceipts'));
          }
          CRM_Core_Session::setStatus($statusMsg, '', 'success');
        }
        else {
          $statusMsg = ts('Encountered an error. Tax receipt has not been issued.', array('domain' => 'org.civicrm.cdntaxreceipts'));
          CRM_Core_Session::setStatus($statusMsg, '', 'error');
          unset($pdf);
        }
      }
      // refresh the form, with file stored in session if we need it.
      $urlParams = array('reset=1', 'cid='.$contactId, 'id='.$contributionId);

      if ( $method == 'print' && isset($pdf) ) {
        $session = CRM_Core_Session::singleton();
        $session->set("pdf_file_". $contributionId . "_" . $contactId, $pdf, 'cdntaxreceipts');
        $urlParams[] = 'file=1';
      }
    }

    // Forward back to our page
    $url = CRM_Utils_System::url(CRM_Utils_System::currentPath(), implode('&', $urlParams));
    CRM_Utils_System::redirect($url);
  }

  function sendFile($contributionId, $contactId) {

    $session = CRM_Core_Session::singleton();
    $filename = $session->get("pdf_file_" . $contributionId . "_" . $contactId, 'cdntaxreceipts');

    if ( $filename && file_exists($filename) ) {
      //CRM-1822 Fetching receipt status isCancelled and reissue for unlinking process.
      $receiptStatus = CRM_Canadahelps_TaxReceipts_Receipt::isEligibleForUnlink($contributionId);
      // set up headers and stream the file
      header('Content-Description: File Transfer');
      header('Content-Type: application/octet-stream');
      header('Content-Disposition: attachment; filename='.basename($filename));
      header('Content-Transfer-Encoding: binary');
      header('Expires: 0');
      header('Cache-Control: must-revalidate');
      header('Pragma: public');
      header('Content-Length: ' . filesize($filename));
      ob_clean();
      flush();
      readfile($filename);

      // clean up -- not cleaning up session and file because IE may reload the page
      // after displaying a security warning for the download. otherwise I would want
      // to delete the file once it has been downloaded.  hook_cron() cleans up after us
      // for now.
      //CRM-1819 - unlinking duplicate receipt for delivery method 'Print'
      
      if(!$receiptStatus['_isCancelled'] && (isset($receiptStatus['_reissue'])) )
      { $findString   = '-duplicate';
        if (strpos($filename, $findString) !== false) {
          $session->set('pdf_file', NULL, 'cdntaxreceipts');
          $session->set("pdf_file_" . $contributionId . "_" . $contactId, NULL, 'cdntaxreceipts');
          unlink($filename);
        }
      }

      // $session->set('pdf_file', NULL, 'cdntaxreceipts');
      // unlink($filename);
      CRM_Utils_System::civiExit();
    }
    else {
      $statusMsg = ts('File has expired. Please retrieve receipt from the email archive.', array('domain' => 'org.civicrm.cdntaxreceipts'));
      CRM_Core_Session::setStatus( $statusMsg, '', 'error' );
    }
  }
}

