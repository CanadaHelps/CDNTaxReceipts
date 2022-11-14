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

  /**
   * build all the data structures needed to build the form
   *
   * @return void
   * @access public
   */
  function preProcess() {

    //check for permission to edit contributions
    if ( ! CRM_Core_Permission::check('issue cdn tax receipts') ) {
      CRM_Core_Error::fatal(ts('You do not have permission to access this page', array('domain' => 'org.civicrm.cdntaxreceipts')));
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
      ),
    );

    $this->_contributions_status = cdntaxreceipts_contributions_get_status_inkind($this->_contributionIds);
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
        $issue_type = empty($status['receipt_id']) ? 'original' : 'duplicate';
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
          $result = civicrm_api3('Contribution', 'get', [
            'sequential' => 1,
            'return' => ["contribution_source", "contribution_status_id", "payment_instrument_id", "total_amount", "financial_type_id", "contribution_page_id"],
            'id' => $status['contribution_id'],
          ]);
          if($result['values'][0]['financial_type_id']) {
            $fund = civicrm_api3('FinancialType', 'get', [
              'sequential' => 1,
              'id' => $result['values'][0]['financial_type_id'],
            ]);
            if($fund['values']) {
              $result['values'][0]['fund'] = $fund['values'][0]['name'];
            }
          }
          if($result['values'][0]['contribution_page_id']) {
            $campaign = civicrm_api3('ContributionPage', 'get', [
              'sequential' => 1,
              'id' => $result['values'][0]['contribution_page_id'],
            ]);
            if($campaign['values']) {
              $result['values'][0]['campaign'] = $campaign['values'][0]['title'];
            }
          }
          if($status['receive_date']) {
            $status['receive_time'] = date("h:i A", strtotime($status['receive_date']));
            $status['receive_date'] = date("F jS, Y", strtotime($status['receive_date']));
          }
          $contact_details = civicrm_api3('Contact', 'getsingle', [
            'sequential' => 1,
            'id' => $status['contact_id'],
            'return' => ["display_name"],
          ]);

          $receipts['ineligibles'][$year]['contact_ids'][$status['contact_id']]['display_name'] = $contact_details['display_name'];
          $receipts['values'][0]['receipt_type'] = $receiptType;
          $receipts['ineligibles'][$year]['contact_ids'][$status['contact_id']]['contributions'][$status['contribution_id']] = array_merge($status, $result['values'][0]);
          // $receipts[$issue_type][$year]['not_eligible_amount'] += $status['total_amount'];
        }
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
    if($receipts) {
      foreach($receipts['original'] as $original_receipts) {
        $receipts['totals']['total_ineligibles_contrib'] += $original_receipts['not_eligible'];
      }
      $receipts['totals']['total_eligibles_contrib'] = $receipts['totals']['original'] - $receipts['totals']['total_ineligibles_contrib'];
    }
    $this->_receipts = $receipts;
  }

  /**
   * Build the form
   *
   * @access public
   *
   * @return void
   */
  function buildQuickForm() {

    //CRM-920: Add Custom Stylesheet to pages as well
    CRM_Core_Resources::singleton()->addStyleFile('org.civicrm.cdntaxreceipts', 'css/receipt_module.css');

    CRM_Utils_System::setTitle(ts('Issue Aggregate Tax Receipts', array('domain' => 'org.civicrm.cdntaxreceipts')));

    CRM_Core_Resources::singleton()->addStyleFile('org.civicrm.cdntaxreceipts', 'css/civicrm_cdntaxreceipts.css');

    $this->assign('receiptYears', $this->_years);
    // Re-calculte total amount
    $inkind_counter = 0;
    $receiptTypes = ['original', 'duplicate'];
    foreach($receiptTypes as $receiptType) {
      if($this->_receipts[$receiptType]) {
        foreach($this->_receipts[$receiptType] as $receipt_original_year => $receipts_originals) {
          if($receipts_originals['contact_ids']) {
            foreach($receipts_originals['contact_ids'] as $contact_id => $receipt_contacts) {
              $contact_details = civicrm_api3('Contact', 'getsingle', [
                'sequential' => 1,
                'id' => $contact_id,
                'return' => ["display_name"],
              ]);

              $this->_receipts[$receiptType][$receipt_original_year]['contact_ids'][$contact_id]['display_name'] = $contact_details['display_name'];
              if($receiptType == 'original') {
                $this->_receipts['totals']['total_eligible_amount'][$receipt_original_year] += array_sum(array_column($receipt_contacts['contributions'], 'total_amount'));
              }
              foreach($receipt_contacts['contributions'] as $contribution_id => $contribution) {
                $result = civicrm_api3('Contribution', 'get', [
                  'sequential' => 1,
                  'return' => ["contribution_source", "contribution_status_id", "payment_instrument_id", "total_amount", "financial_type_id", "contribution_page_id"],
                  'id' => $contribution['contribution_id'],
                ]);
                if($result['values'][0]['financial_type_id']) {
                  $fund = civicrm_api3('FinancialType', 'get', [
                    'sequential' => 1,
                    'id' => $result['values'][0]['financial_type_id'],
                  ]);
                  if($fund['values']) {
                    $result['values'][0]['fund'] = $fund['values'][0]['name'];
                    //CRM-1470 Conter to identify the number of eligible In Kind receipts
                    $fund_name_check = preg_replace("/[^a-zA-Z0-9]+/", "", $fund['values'][0]['name']);
                    if ( stripos($fund_name_check,"inkind") !== false && $receiptType == 'original') {
                      $inkind_counter++ ;
                    }
                  }
                }
                if($result['values'][0]['contribution_page_id']) {
                  $campaign = civicrm_api3('ContributionPage', 'get', [
                    'sequential' => 1,
                    'id' => $result['values'][0]['contribution_page_id'],
                  ]);
                  if($campaign['values']) {
                    $result['values'][0]['campaign'] = $campaign['values'][0]['title'];
                  }
                }
                if($contribution['receive_date']) {
                  $contribution['receive_date_original'] = $contribution['receive_date'];
                  $contribution['receive_time'] = date("h:i A", strtotime($contribution['receive_date']));
                  $contribution['receive_date'] = date("F jS, Y", strtotime($contribution['receive_date']));
                }
                if($receiptType !== 'original') {
                  $result['values'][0]['eligible'] = false;
                  if($receiptType == 'duplicate') {
                    $result['values'][0]['eligibility_reason'] = '(Duplicate)';
                  }
                }
                $receipt['values'][0]['receipt_type'] = $receiptType;
                $this->_receipts[$receiptType][$receipt_original_year]['contact_ids'][$contact_id]['contributions'][$contribution_id] = array_merge($contribution, $result['values'][0]);
              }
            }
          } else {
            if($receiptType == 'original') {
              $this->_receipts['totals']['total_eligible_amount'][$receipt_original_year] = 0;
            }
          }
        }
      }
    }
    //CRM-1470 Warning on the top right mentioning number of In Kind donations selected 
    if ($inkind_counter > 0) {
      CRM_Core_Session::setStatus(ts("You have selected $inkind_counter \"In Kind\" donations. The CRA requires a separate receipt for each non-cash donation. An individual tax receipt will be issued for each In Kind donation, in addition to any combined tax receipts for eligible cash gifts."), ts('In Kind Receipts'), 'alert');
    }
    // Add ineligibles to it as well
    $receiptTypes[] = 'ineligibles';
    $this->assign('receiptTypes', $receiptTypes);
    foreach($receiptTypes as $rtype) {
      foreach($this->_years as $year) {
        if(empty($this->_receipts[$rtype][$year])) {
          $this->_receipts[$rtype][$year]['total_contacts'] = 0;
          $this->_receipts[$rtype][$year]['total_contrib'] = 0;
          $this->_receipts[$rtype][$year]['total_amount'] = 0;
        }
      }
    }
    $this->assign('receiptList', $this->_receipts);
    $this->assign('receipt_type', 'aggregate');
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
    }
    if($this->_years) {
      $this->assign('defaultYear', array_values($this->_years)[0]);
    }

    $this->add('select', 'receipt_year',
      ts('Tax Year'),
      $tax_year,
      FALSE,
      array('class' => 'crm-select')
    );

    $delivery_method = Civi::settings()->get('delivery_method') ?? CDNTAX_DELIVERY_PRINT_ONLY;
    $this->assign('deliveryMethod', $delivery_method);

    $this->addRule('receipt_year', ts('Selection required', array('domain' => 'org.civicrm.cdntaxreceipts')), 'required');

    if ($delivery_method != CDNTAX_DELIVERY_DATA_ONLY) {
      $this->add('checkbox', 'is_preview', ts('Run in preview mode?', array('domain' => 'org.civicrm.cdntaxreceipts')));
    }

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
      'thankyou_date' => 1,
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

  function setDefaultValues() {
    // TODO: Handle case where year -1 was not an option
    if($this->_years) {
      return array('receipt_year' => 'issue_' . array_values($this->_years)[0]);
    } else {
      return array('receipt_year' => 'issue_' . (date("Y") - 1),);
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

    // lets get around the time limit issue if possible
    if ( ! ini_get( 'safe_mode' ) ) {
      set_time_limit( 0 );
    }

    $params = $this->controller->exportValues($this->_name);
    $year = $params['receipt_year'];
    if ( $year ) {
      $year = substr($year, strlen('issue_')); // e.g. issue_2012
    }

    $previewMode = FALSE;
    if (isset($params['is_preview']) && $params['is_preview'] == 1 ) {
      $previewMode = TRUE;
    }

    $buttonName = $this->controller->getButtonName();
    if($buttonName == '_qf_IssueAggregateTaxReceipts_submit') {
      $previewMode = TRUE;
    }

    // start a PDF to collect receipts that cannot be emailed
    $receiptsForPrintingPDF = cdntaxreceipts_openCollectedPDF();

    $emailCount = 0;
    $printCount = 0;
    $dataCount = 0;
    $failCount = 0;

    foreach ($this->_receipts['original'][$year]['contact_ids'] as $contact_id => $contribution_status) {
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
        if($contri['receive_date_original']) {
          $contributions[$k]['receive_date'] = $contri['receive_date_original'];
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
      foreach($contributions as $contri_key => $contrivalue)
      {
        $fund_name_check = preg_replace("/[^a-zA-Z0-9]+/", "", $contrivalue['fund']);
        if ( stripos($fund_name_check,"inkind") !== false) {
          $contributionsInKind[$contri_key] = $contrivalue;
          unset($contributions[$contri_key]);
        }
      }
      if ( empty($issuedOn) && count($contributions) > 0 ) {
        //CRM-920: Thank-you Email Tool
        if($this->getElement('thankyou_email')->getValue()) {
          if($this->getElement('html_message')->getValue()) {
            if(isset($params['template'])) {
              if($params['template'] !== 'default') {
                $this->_contributionIds = array_column($contributions, 'contribution_id');
                $from_email_address = current(CRM_Core_BAO_Domain::getNameAndEmail(FALSE, TRUE));
                if($from_email_address) {
                  $data = &$this->controller->container();
                  $data['values']['ViewTaxReceipt']['from_email_address'] = $from_email_address;
                  $data['values']['ViewTaxReceipt']['subject'] = $this->getElement('subject')->getValue();
                  $data['values']['ViewTaxReceipt']['html_message'] = $this->getElement('html_message')->getValue();
                  $thankyou_html = CRM_Cdntaxreceipts_Task_PDFLetterCommon::postProcessForm($this, $params);
                  if($thankyou_html) {
                    if(is_array($thankyou_html)) {
                      $thankyou_html = array_values($thankyou_html)[0];
                    } else {
                      $thankyou_html = $thankyou_html;
                    }
                  }
                }
              }
            }
          }
        }
        $ret = cdntaxreceipts_issueAggregateTaxReceipt($contact_id, $year, $contributions, $method,
          $receiptsForPrintingPDF, $previewMode, $thankyou_html);

        if( $ret !== 0 ) {
          //CRM-920: Mark Contribution as thanked if checked
          if($this->getElement('thankyou_date')->getValue()) {
            foreach($contributions as $contributionIds) {
              $contribution = new CRM_Contribute_DAO_Contribution();
              $contribution->id = $contributionIds['contribution_id'];
              if ( ! $contribution->find( TRUE ) ) {
                CRM_Core_Error::fatal( "CDNTaxReceipts: Could not find corresponding contribution id." );
              }
              $contribution->thankyou_date = date('Y-m-d H:i:s', CRM_Utils_Time::time());
              $contribution->save();
            }
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
      $originalOnly = TRUE;
      if ($params['receipt_option']) {
        $originalOnly = FALSE;
      }
      foreach ($contributionsInKind as $inkind_key => $inkind_value)
      {
        $contribution = new CRM_Contribute_DAO_Contribution();
        $contribution->id = $inkind_value['contribution_id'];
        if ( ! $contribution->find( TRUE ) ) {
          CRM_Core_Error::fatal( "CDNTaxReceipts: Could not find corresponding contribution id." );
        }
        if ( cdntaxreceipts_eligibleForReceipt($contribution->id) ) {
          list($issued_on, $receipt_id) = cdntaxreceipts_issued_on($contribution->id);
          if ( empty($issued_on) || ! $originalOnly ) {
            //CRM-918: Thank-you Email Tool
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
            list( $ret, $method ) = cdntaxreceipts_issueTaxReceipt( $contribution, $receiptsForPrintingPDF, $previewMode );
            if( $ret !== 0 ) {
              //CRM-918: Mark Contribution as thanked if checked
              if($this->getElement('thankyou_date')->getValue()) {
                $contribution->thankyou_date = date('Y-m-d H:i:s', CRM_Utils_Time::time());
                $contribution->save();
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
        }
      }
    }

    // 3. Set session status
    if ( $previewMode ) {
      $status = ts('%1 tax receipt(s) have been previewed.  No receipts have been issued.', array(1=>$printCount, 'domain' => 'org.civicrm.cdntaxreceipts'));
      CRM_Core_Session::setStatus($status, '', 'success');
    }
    else {
      if ($emailCount > 0) {
        $status = ts('%1 tax receipt(s) were sent by email.', array(1=>$emailCount, 'domain' => 'org.civicrm.cdntaxreceipts'));
        CRM_Core_Session::setStatus($status, '', 'success');
      }
      if ($printCount > 0) {
        $status = ts('%1 tax receipt(s) need to be printed.', array(1=>$printCount, 'domain' => 'org.civicrm.cdntaxreceipts'));
        CRM_Core_Session::setStatus($status, '', 'success');
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
    cdntaxreceipts_sendCollectedPDF($receiptsForPrintingPDF, 'Receipts-To-Print-' . (int) $_SERVER['REQUEST_TIME'] . '.pdf');  // EXITS.
  }
}

