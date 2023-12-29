<?php

/**
 * This class provides the common functionality for issuing Aggregate Tax Receipts for
 * a group of Contribution ids.
 */
class CRM_Cdntaxreceipts_Task_IssueAggregateTaxReceipts extends CRM_Contribute_Form_Task {

  const MAX_RECEIPT_COUNT = 1000;

  private $_contributions_status;
  private $_issue_type;
  private $_receipts;
  private $_years;
  public $_receiptList = [];

  /**
   * build all the data structures needed to build the form
   *
   * @return void
   * @access public
   */
  function preProcess() {

    //check for permission to edit contributions
    if ( ! CRM_Core_Permission::check('issue cdn tax receipts') ) {
      throw new CRM_Core_Exception("You do not have permission to access this page");
    }

    parent::preProcess();
    $this->_contributions_status = array();
    $this->_issue_type = array('original' , 'duplicate');
    $this->_receipts = array();
    $this->_years = array();

    $receipts = array('totals' =>
      array(
        'total_contrib' => 0,
        'loading_errors' => 0,
        'total_contacts' => 0,
        'original' => 0,
        'duplicate' => 0,
        'ineligibles' => 0
      ),
    );

    $this->_contributions_status = cdntaxreceipts_contributions_get_status($this->_contributionIds);

    // Get the number of years selected
    foreach ($this->_contributions_status as $contrib_status) {
      $this->_years[$contrib_status['receive_year']] = $contrib_status['receive_year'];
    }

    foreach ( $this->_years as $year ) {
      foreach ($this->_issue_type as $issue_type) {
        $receipts[$issue_type][$year] = array(
          'total_contrib' => 0,
          'total_amount' => 0,
          'email' => array('contribution_count' => 0, 'receipt_count' => 0,),
          'print' => array('contribution_count' => 0, 'receipt_count' => 0,),
          'data' => array('contribution_count' => 0, 'receipt_count' => 0,),
          'total_contacts' => 0,
          'total_eligible_amount' => 0,
          'not_eligible' => 0,
          'not_eligible_amount' => 0,
          'contact_ids' => array(),
        );
      }
    }

    // Count and categorize contributions
    foreach ($this->_contributionIds as $id) {
      $status = isset($this->_contributions_status[$id]) ? $this->_contributions_status[$id] : NULL;
      if (is_array($status)) {
        $year = $status['receive_year'];
        // check if most recent is cancelled, and mark as "replace" then add that contribution to 'original' receipt array
        $cancelledReceipt = CRM_Canadahelps_TaxReceipts_Receipt::retrieveReceiptDetails($id, true);
        $issue_type = (empty($status['receipt_id']) || ($cancelledReceipt[0] != NULL && $status['receipt_id'] == $cancelledReceipt[1])) ? 'original' : 'duplicate';
        $receipts[$issue_type][$year]['total_contrib']++;
        // Note: non-deductible amount has already had hook called in cdntaxreceipts_contributions_get_status
        $receipts[$issue_type][$year]['total_amount'] += ($status['total_amount']);
        $receipts[$issue_type][$year]['not_eligible_amount'] += $status['non_deductible_amount'];
        if ($status['eligible']) {
          list( $method, $email ) = cdntaxreceipts_sendMethodForContact($status['contact_id']);
          $receipts[$issue_type][$year][$method]['contribution_count']++;
          if (!isset($receipts[$issue_type][$year]['contact_ids'][$status['contact_id']])) {
            $receipts[$issue_type][$year]['contact_ids'][$status['contact_id']] = array(
              'issue_method' => $method,
              'contributions' => array(),
            );
            $receipts[$issue_type][$year][$method]['receipt_count']++;
          }
          // Here we store all the contribution details for each contact_id
          $receipts[$issue_type][$year]['contact_ids'][$status['contact_id']]['contributions'][$id] = $status;
        }
        else {
          $receipts[$issue_type][$year]['not_eligible']++;
          // $receipts[$issue_type][$year]['not_eligible_amount'] += $status['total_amount'];
          $issue_type = 'ineligibles';
        }

        // temporary set array with type of receipt for each contribution
        // so that we can use in setReceiptsList
        $this->_receiptList[$id] = $issue_type;

        // Global totals
        $receipts['totals']['total_contrib']++;
        $receipts['totals'][$issue_type]++;
        if ($status['contact_id']) {
          $receipts['totals']['total_contacts']++;
        }
      }
      else {
        $receipts['totals']['loading_errors']++;
      }
    }

    foreach ($this->_issue_type as $issue_type) {
      foreach ($this->_years as $year) {
        $receipts[$issue_type][$year]['total_contacts'] = count($receipts[$issue_type][$year]['contact_ids']);
      }
    }

    $this->_receipts = $receipts;
    list($this->_years, $this->_receiptList) = CRM_Cdntaxreceipts_Task_IssueSingleTaxReceipts::getReceiptsList($this->_contributionIds, $this->_receiptList, true);

  }

  /**
   * Build the form
   *
   * @access public
   *
   * @return void
   */
  function buildQuickForm() {

    CRM_Utils_System::setTitle(ts('Issue Aggregate Tax Receipts', array('domain' => 'org.civicrm.cdntaxreceipts')));

    CRM_Core_Resources::singleton()->addStyleFile('org.civicrm.cdntaxreceipts', 'css/civicrm_cdntaxreceipts.css');

    $this->assign('receiptList', $this->_receipts);
    $this->assign('receiptYears', $this->_years);

    $delivery_method = Civi::settings()->get('delivery_method') ?? CDNTAX_DELIVERY_PRINT_ONLY;
    $this->assign('deliveryMethod', $delivery_method);

    // add radio buttons
    // TODO: It might make sense to issue for multiple years here so switch to checkboxes
    foreach ( $this->_years as $year ) {
      $this->addElement('radio', 'receipt_year', NULL, $year, 'issue_' . $year);
    }
    if (count($this->_years) > 0)
      $this->addRule('receipt_year', ts('Selection required', array('domain' => 'org.civicrm.cdntaxreceipts')), 'required');

    if ($delivery_method != CDNTAX_DELIVERY_DATA_ONLY) {
      $this->add('checkbox', 'is_preview', ts('Run in preview mode?', array('domain' => 'org.civicrm.cdntaxreceipts')));
    }

    $buttons = array(
      array(
        'type' => 'cancel',
        'name' => ts('Back', array('domain' => 'org.civicrm.cdntaxreceipts')),
      ),
      array(
        'type' => 'submit',
        'name' => ts('Preview', array('domain' => 'org.civicrm.cdntaxreceipts')),
        'isDefault' => FALSE,
      ),
      array(
        'type' => 'next',
        'name' => 'Issue Tax Receipts',
        'isDefault' => TRUE,
        'submitOnce' => FALSE,
      ),
    );

    //CRM-920: Integrate WYSWIG Editor on the form
    CRM_Contact_Form_Task_PDFLetterCommon::buildQuickForm($this);

    $this->addButtons($buttons);

    // CH Customization
    $this->customizeForm();
  }

  function setDefaultValues() {
    $from_email_address = current(CRM_Core_BAO_Domain::getNameAndEmail(FALSE, TRUE));
    return array(
      'receipt_year' => ($this->_years) ? 'issue_' . array_values($this->_years)[0] : 'issue_' . (date("Y") - 1), // TODO: Handle case where year -1 was not an option
      'margin_left' => 0.75,
      'margin_right' => 0.75,
      'margin_top' => 0.75,
      'margin_bottom' => 0.75,
      'email_options' => 'email',
      'from_email_address' => $from_email_address,
      'group_by_separator' => 'comma',
      'thankyou_date' => 1,
      'receipt_option' => 0,
    );
  }

  /**
   * process the form after the input has been submitted and validated
   *
   * @access public
   *
   * @return None
   */

  function postProcess() {

    // lets get around the time limit issue if possible
    if ( ! ini_get( 'safe_mode' ) ) {
      set_time_limit( 0 );
    }

    $params = $this->controller->exportValues($this->_name);

    // Should we issue duplicates ?
    $originalOnly = TRUE;
    if ( isset($params['receipt_option']) && $params['receipt_option']) {
      $originalOnly = FALSE;
    }

    $year = $params['receipt_year'];
    if ( $year ) {
      $year = substr($year, strlen('issue_')); // e.g. issue_2012
    }

    // Preview mode ?
    $previewMode = FALSE;
    $buttonName = $this->controller->getButtonName();
    if($buttonName == '_qf_IssueAggregateTaxReceipts_submit') {
      $previewMode = TRUE;
    }

    if (isset($params['is_preview']) && $params['is_preview'] == 1 ) {
      $previewMode = TRUE;
    }

    // start a PDF to collect receipts that cannot be emailed
    $receiptsForPrintingPDF = cdntaxreceipts_openCollectedPDF();

    $emailCount = 0;
    $printCount = 0;
    $dataCount = 0;
    $failCount = 0;

    //CRM-920: Thank-you Email Tool
    $sendThankYouEmail = false;
    if ($this->getElement('thankyou_email')->getValue()
      && ($this->getElement('html_message_en')->getValue() || $this->getElement('html_message_fr')->getValue())
      && ((isset($params['template']) && $params['template'] !== 'default') || 
          (isset($params['template_FR']) && $params['template_FR'] !== 'default') )) {

      $from_email_address = current(CRM_Core_BAO_Domain::getNameAndEmail(FALSE, TRUE));
      if ($from_email_address) {
        $sendThankYouEmail = true;
      }
    }

    // loop through original receipts only
    foreach ($this->_receipts['original'][$year]['contact_ids'] as $contact_id => $contribution_status) {
      $params['contactID'] = $contact_id;
      if ( $emailCount + $printCount + $failCount >= self::MAX_RECEIPT_COUNT ) {
        // limit email, print receipts as the pdf generation and email-to-archive consume
        // server resources. don't limit data-type receipts.
        $status = ts('Maximum of %1 tax receipt(s) were sent. Please repeat to continue processing.',
          array(1=>self::MAX_RECEIPT_COUNT, 'domain' => 'org.civicrm.cdntaxreceipts'));
        CRM_Core_Session::setStatus($status, '', 'info');
        break;
      }

      $contributions = $contribution_status['contributions'];
      foreach($contributions as $k => $contri) {
        if ( isset($contri['receive_date_original']) ) {
          $contributions[$k]['receive_date'] = $contri['receive_date_original'];
        }
        //To Replace receipt we need to add extra parameters to contribution array
        $cancelledReceipt = CRM_Canadahelps_TaxReceipts_Receipt::retrieveReceiptDetails($contri['contribution_id'], true);
        if ($cancelledReceipt[0] != NULL && $contri['receipt_id'] == $cancelledReceipt[1]) {
          //CRM-1977
          $cancelledReceiptContribIds = $cancelledReceipt[3];
          $receiptContribIds = array_column($contributions,'contribution_id');
          //CRM-1977-1-If Cancelled contribution Receipt List exactly matches with List of contributions List in that case only add 'cancelled_replace_receipt_number'
          sort($receiptContribIds);
          sort($cancelledReceiptContribIds);
          if ($receiptContribIds === $cancelledReceiptContribIds){
            $contributions[$k]['cancelled_replace_receipt_number']  = $cancelledReceipt[0];
          } 
          $contributions[$k]['replace_receipt']  = 1;
          $contributions[$k]['receipt_id']  = 0;
        }
      }
      // $method = $contribution_status['issue_method'];
      $method = 'print';
      if($params['delivery_method']) {
        require_once 'CRM/Contact/BAO/Contact.php';
        list($displayname, $email, $doNotEmail, $onHold) = CRM_Contact_BAO_Contact::getContactDetails($contact_id);
        if ( isset($email) ) {
          if ( ! $doNotEmail && ! $onHold ) {
            $method = 'email';
          }
        }
      }
      //CRM-1470 Create separate In Kind contributions array and unset from combined tax receipt contributions array
      $contributionsInKind = array();
      foreach($contributions as $key => $contribution) {
        if ($contribution['inkind']) {
          $contributionsInKind[$key] = $contribution;
          unset($contributions[$key]);
        }
      }

      if ( (empty($issuedOn) && count($contributions) > 0) ) {

        //CRM-920: Thank-you Email Tool
        $thankyou_html = NULL;
        if ($sendThankYouEmail) {
          $thankyou_html = $this->getThankYouHTML(array_column($contributions, 'contribution_id'), $from_email_address, $params);
        }

        $ret = cdntaxreceipts_issueAggregateTaxReceipt($contact_id, $year, $contributions, $method,
          $receiptsForPrintingPDF, $previewMode, $thankyou_html);

        if ( $ret !== 0 && !$previewMode ) {
            //CRM-920: Mark Contribution as thanked if checked
            foreach($contributions as $contributionIds) {
              CRM_Cdntaxreceipts_Task_IssueSingleTaxReceipts::markContributionAsReceipted(
                $contributionIds['contribution_id'],
                $this->getElement('thankyou_date')->getValue()
              );
            }
        }

        if ( $ret == 0 ) {
          $failCount++;
        }
        elseif ( $method == 'email' ) {
          $emailCount++;
        }
        elseif ( $method == 'print' ) {
          $printCount++;
        }
        elseif ( $method == 'data' ) {
          $dataCount++;
        }
      }

      //CRM-1470 Generate individual In Kind contributions receipts
      foreach ($contributionsInKind as $inkind_key => $inkind_value) {
        $contribution = new CRM_Contribute_DAO_Contribution();
        $contribution->id = $inkind_value['contribution_id'];
        if ( ! $contribution->find( TRUE ) ) {
          throw new CRM_Core_Exception("CDNTaxReceipts: Could not find corresponding contribution id.");
        }
        if ( cdntaxreceipts_eligibleForReceipt($contribution->id) ) {
          list($issued_on, $receipt_id) = cdntaxreceipts_issued_on($contribution->id);
          //CRM-1990-Receipt not getting replaced for a cancelled In Kind donation through Aggregate Tax Receipt method
          // check if most recent is cancelled, and mark as "replace"
          $cancelledInKindReceipt = CRM_Canadahelps_TaxReceipts_Receipt::retrieveReceiptDetails($contribution->id, true);
          if ($cancelledInKindReceipt[0] != NULL && $receipt_id == $cancelledInKindReceipt[1]) {
            $contribution->cancelled_replace_receipt_number  = $cancelledInKindReceipt[0];
            $contribution->replace_receipt  = 1;
            $issued_on = '';
          }
          if ( empty($issued_on) || ! $originalOnly ) {

            //CRM-920: Thank-you Email Tool
            if ($sendThankYouEmail) {
              $thankyou_html = $this->getThankYouHTML([$contribution->id], $from_email_address, $params);
              if ($thankyou_html != NULL)
                $contribution->thankyou_html = $thankyou_html;
            }

            list( $ret, $method ) = cdntaxreceipts_issueTaxReceipt( $contribution, $receiptsForPrintingPDF, $previewMode );
            if( $ret !== 0 && !$previewMode) {
              //CRM-920: Mark Contribution as thanked if checked   
              CRM_Cdntaxreceipts_Task_IssueSingleTaxReceipts::markContributionAsReceipted(
                $contribution->id,
                $this->getElement('thankyou_date')->getValue()
              );
            }
            if ( $ret == 0 ) {
              $failCount++;
            }
            elseif ( $method == 'email' ) {
              $emailCount++;
            }
            elseif ( $method == 'print' ) {
              $printCount++;
            }
            elseif ( $method == 'data' ) {
              $dataCount++;
            }
          }
        }
      }
    }

    // loop through duplicate receipts only (but not if preview)
    if (!$originalOnly && !$previewMode) {
      $duplicateReceipts = [];
      foreach ($this->_receipts['duplicate'][$year]['contact_ids'] as $contact_id => $contact_data) {
        foreach ($contact_data['contributions'] as $contributionId => $contribution) {

          // don't process duplicate if already processed
          if (!in_array($contribution['receipt_id'], $duplicateReceipts)) {
            $duplicateReceipts[] = $contribution['receipt_id'];

            if ( $emailCount + $printCount + $failCount >= self::MAX_RECEIPT_COUNT ) {
              // limit email, print receipts as the pdf generation and email-to-archive consume
              // server resources. don't limit data-type receipts.
              $status = ts('Maximum of %1 tax receipt(s) were sent. Please repeat to continue processing.', array(1=>self::MAX_RECEIPT_COUNT, 'domain' => 'org.civicrm.cdntaxreceipts'));
              CRM_Core_Session::setStatus($status, '', 'info');
              break;
            }

            // 1. Load Contribution information
            $contribution = new CRM_Contribute_DAO_Contribution();
            $contribution->id = $contributionId;
            if ( ! $contribution->find( TRUE ) ) {
              throw new CRM_Core_Exception("CDNTaxReceipts: Could not find corresponding contribution id.");
            }

            //CRM-920: Thank-you Email Tool
            if ($sendThankYouEmail) {
              $thankyou_html = $this->getThankYouHTML([$contribution->id], $from_email_address, $params);
              if ($thankyou_html != NULL)
                $contribution->thankyou_html = $thankyou_html;
            }

            list( $ret, $method ) = cdntaxreceipts_issueTaxReceipt( $contribution, $receiptsForPrintingPDF, $previewMode );
            if( $ret !== 0 && !$previewMode) {
              //CRM-918: Mark Contribution as thanked if checked
              CRM_Cdntaxreceipts_Task_IssueSingleTaxReceipts::markContributionAsReceipted(
                $contribution->id,
                $this->getElement('thankyou_date')->getValue(),
                FALSE
              );
            }

            if ( $ret == 0 ) {
              $failCount++;
            }
            elseif ( $method == 'email' ) {
              $emailCount++;
            }
            elseif ( $method == 'print' ) {
              $printCount++;
            }
            elseif ( $method == 'data' ) {
              $dataCount++;
            }
          }
        }
      }
    }

    // 3. Set session status
    $receiptCount = [];
    if ( $previewMode ) {
      $status = ts('%1 tax receipt(s) have been previewed.  No receipts have been issued.', array(1=>$printCount, 'domain' => 'org.civicrm.cdntaxreceipts'));
      CRM_Core_Session::setStatus($status, '', 'success');
    }
    else {
      if ($emailCount > 0) {
        $status = ts('%1 tax receipt(s) were sent by email.', array(1=>$emailCount, 'domain' => 'org.civicrm.cdntaxreceipts'));
        CRM_Core_Session::setStatus($status, '', 'success');
        $receiptCount['email'] = $emailCount;
      }
      if ($printCount > 0) {
        $status = ts('%1 tax receipt(s) need to be printed.', array(1=>$printCount, 'domain' => 'org.civicrm.cdntaxreceipts'));
        CRM_Core_Session::setStatus($status, '', 'success');
        $receiptCount['print'] = $printCount;
      }
      if ($dataCount > 0) {
        $status = ts('Data for %1 tax receipt(s) is available in the Tax Receipts Issued report.', array(1=>$dataCount, 'domain' => 'org.civicrm.cdntaxreceipts'));
        CRM_Core_Session::setStatus($status, '', 'success');
      }
    }

    if ( $failCount > 0 ) {
      $status = ts('%1 tax receipt(s) failed to process.', array(1=>$failCount, 'domain' => 'org.civicrm.cdntaxreceipts'));
      CRM_Core_Session::setStatus($status, '', 'error');
    }

    // 4. send the collected PDF for download
    // NB: This exits if a file is sent.
    cdntaxreceipts_sendCollectedPDF($receiptsForPrintingPDF, 'Receipts-To-Print-' . CRM_Cdntaxreceipts_Utils_Time::time() . '.pdf', $receiptCount);  // EXITS.
  }



  /**************************************
  * CH Custom Functions
  ***************************************/
  
  //CRM-920: Thank-you Email Tool
  private function getThankYouHTML(array $contributionIds, $sender, $params) {
    //CRM-2124 choose html_message section according to contact's preferred language
    $preferred_language = _cdntaxreceipts_userPreferredLanguage($params['contactID']);
    $html_message = ($preferred_language == 'fr_CA') ? 'html_message_fr' : 'html_message_en';
    $this->_contributionIds = $contributionIds;
    $data = &$this->controller->container();
    $data['values']['ViewTaxReceipt']['from_email_address'] = $sender;
    $data['values']['ViewTaxReceipt']['subject'] = $this->getElement('subject')->getValue();
    $data['values']['ViewTaxReceipt']['html_message'] = $this->getElement($html_message)->getValue();
    $params['html_message'] = $this->getElement($html_message)->getValue();
    //CRM-1792 Adding 'group_by' parameter for token processor to process grouped contributions
    if (count($contributionIds) > 1) {
      $params['group_by'] = 'contact_id';
    }

    $thankyou_html = CRM_Cdntaxreceipts_Task_PDFLetterCommon::postProcessForm($this, $params);
    if ($thankyou_html) {
      if (is_array($thankyou_html)) {
        $thankyou_html = array_values($thankyou_html)[0];
      } else {
        $thankyou_html = $thankyou_html;
      }
      return $thankyou_html;
    }

    return NULL;
  }

  private function customizeForm() {

    //CRM-918: Add Custom Stylesheet to pages as well
    CRM_Core_Resources::singleton()->addStyleFile('org.civicrm.cdntaxreceipts', 'css/receipt_module.css');

    $this->assign('receiptList', $this->_receiptList);
    Civi::resources()->addVars('receipts', ['receiptList' => $this->_receiptList]);
    $this->assign('receipt_type', 'aggregate');

    // Add Receipt Types
    $receiptTypes = ['original', 'duplicate', 'ineligibles'];
    $this->assign('receiptTypes', $receiptTypes);
    Civi::resources()->addVars('receipts', ['receiptTypes' => $receiptTypes]);

    // Duplicates?
    $this->add('checkbox', 'receipt_option', ts('Also re-issue duplicates', array('domain' => 'org.civicrm.cdntaxreceipts')));

    //Add ColumnHeaders for Table of Users Section
    $columnHeaders = ['Received',
      'Name',
      'Amount',
      'Fund',
      'Campaign',
      'Source',
      'Method',
      'Status',
      'Eligibility',
    ];
    $this->assign('columnHeaders', $columnHeaders);

    // Add tax year as select box
    krsort($this->_years);
    foreach( $this->_years as $year ) {
      $tax_year['issue_'.$year] = $year;
      $this->removeElement('receipt_year', true);
    }
    $this->assign('defaultYear', array_values($this->_years)[0]);

    $this->add('select', 'receipt_year',
      ts('Tax Year'),
      $tax_year,
      FALSE,
      array('class' => 'crm-select')
    );

    //CRM-920: Add delivery Method to form
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

    //CRM-1596 "From Email Address" value being passed as attributes
    if ($this->elementExists('from_email_address')) {
      $this->removeElement('from_email_address');
    }
    $from_email_address = current(CRM_Core_BAO_Domain::getNameAndEmail(FALSE, TRUE));
    $this->add('text', 'from_email_address', ts('From Email Address'), array('value' => $from_email_address), TRUE);
    $this->add('text', 'email_options', ts('Print and Email Options'), array('value' => 'email'), FALSE);
    $this->add('text', 'group_by_separator', ts('Group By Seperator'), array('value' => 'comma'), FALSE);

    //Add Tokens
    $tokens = CRM_Cdntaxreceipts_Task_PDFLetterCommon::listTokens();
    $this->assign('tokens', CRM_Utils_Token::formatTokensForDisplay($tokens));

    $templates = CRM_Core_BAO_MessageTemplate::getMessageTemplates(FALSE);
    if($this->elementExists('template')) {
      $this->removeElement('template');
    }
    $this->assign('templates', TRUE);
    //Adding English template
    $this->add('select', 'template', ts('English'),
      ['default' => 'Default Message'] + $templates + ['0' => ts('Other Custom')], FALSE,
      ['onChange' => "selectTemplateValue( this.value, 'EN');"]
    );
    //Adding English HTML message section
    $this->add('wysiwyg', 'html_message_en',
      ts('HTML English Format'),
      [
        'cols' => '80',
        'rows' => '8',
        'onkeyup' => "return verify(this)",
      ]
    );
    //Adding french template
    $this->add('select', 'template_FR', ts('French'),
      ['default' => 'Default Message'] + $templates + ['0' => ts('Other Custom')], FALSE,
      ['onChange' => "selectTemplateValue( this.value, 'FR');"]
    );
    //Adding French HTML message section
    $this->add('wysiwyg', 'html_message_fr',
      ts('HTML French Format'),
      [
        'cols' => '80',
        'rows' => '8',
        'onkeyup' => "return verify(this)",
      ]
    );
  }
}
