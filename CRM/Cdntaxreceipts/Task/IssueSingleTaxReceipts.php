<?php

require_once('CRM/Contribute/Form/Task.php');

/**
 * This class provides the common functionality for issuing CDN Tax Receipts for
 * one or a group of contact ids.
 */
class CRM_Cdntaxreceipts_Task_IssueSingleTaxReceipts extends CRM_Contribute_Form_Task {

  const MAX_RECEIPT_COUNT = 1000;

  private $_receipts;

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

    $receipts = array( 'original'  => array('email' => 0, 'print' => 0, 'data' => 0),
                       'duplicate' => array('email' => 0, 'print' => 0, 'data' => 0), );

    // count and categorize contributions
    $receiptList = [];
    foreach ( $this->_contributionIds as $id ) {
      if ( cdntaxreceipts_eligibleForReceipt($id) ) {
        list($issued_on, $receipt_id) = cdntaxreceipts_issued_on($id);
        $key = empty($issued_on) ? 'original' : 'duplicate';
        list( $method, $email ) = cdntaxreceipts_sendMethodForContribution($id);
        $receipts[$key][$method]++;
      } else {
        $key = 'ineligibles';
      }
      $result = civicrm_api3('Contribution', 'get', [
        'sequential' => 1,
        'return' => ["contribution_source", "contribution_status_id", "payment_instrument_id", "total_amount", "financial_type_id", "contribution_page_id", "receive_date"],
        'id' => $id,
      ]);
      if($result['values'][0]['receive_date']) {
        $result['values'][0]['receive_time'] = date("h:i A", strtotime($result['values'][0]['receive_date']));
        $result['values'][0]['receive_year'] = date("Y", strtotime($result['values'][0]['receive_date']));
        $result['values'][0]['receive_date'] = date("F jS, Y", strtotime($result['values'][0]['receive_date']));
      }
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
      $result['values'][0]['eligible'] = true;
      if($key !== 'original') {
        $result['values'][0]['eligible'] = false;
        if($key == 'duplicate') {
          $result['values'][0]['eligibility_reason'] = '(Duplicate)';
        }
      }
      if($result['values'][0]['contact_id']) {
        $contact_details = civicrm_api3('Contact', 'getsingle', [
          'sequential' => 1,
          'id' => $result['values'][0]['contact_id'],
          'return' => ["display_name"],
        ]);
      }
      if($contact_details) {
        $receiptList[$key]['contact_ids'][$result['values'][0]['contact_id']]['display_name'] = $contact_details['display_name'];
        $receiptList[$key]['contact_ids'][$result['values'][0]['contact_id']]['contributions'][$result['values'][0]['id']] = $result['values'][0];
      }
    }
    $this->_receiptList = $receiptList;
    // print_r(json_encode($receiptList));die;
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

    //CRM-918: Add Custom Stylesheet to pages as well
    CRM_Core_Resources::singleton()->addStyleFile('org.civicrm.cdntaxreceipts', 'css/receipt_module.css');

    CRM_Utils_System::setTitle(ts('Issue Tax Receipts', array('domain' => 'org.civicrm.cdntaxreceipts')));

    // assign the counts
    $receipts = $this->_receipts;
    $originalTotal = $receipts['original']['print'] + $receipts['original']['email'] + $receipts['original']['data'];
    $duplicateTotal = $receipts['duplicate']['print'] + $receipts['duplicate']['email'] + $receipts['duplicate']['data'];
    $receiptTotal = $originalTotal + $duplicateTotal;
    $this->assign('receiptCount', $receipts);
    $this->assign('originalTotal', $originalTotal);
    $this->assign('duplicateTotal', $duplicateTotal);
    $this->assign('receiptTotal', $receiptTotal);
    $this->assign('receiptList', $this->_receiptList);
    $this->assign('receipt_type', 'single');

    $delivery_method = Civi::settings()->get('delivery_method') ?? CDNTAX_DELIVERY_PRINT_ONLY;
    $this->assign('deliveryMethod', $delivery_method);

    // add radio buttons
    $this->add('checkbox', 'receipt_option', ts('Also re-issue duplicates', array('domain' => 'org.civicrm.cdntaxreceipts')));

    if ($delivery_method != CDNTAX_DELIVERY_DATA_ONLY) {
      $this->add('checkbox', 'is_preview', ts('Run in preview mode?', array('domain' => 'org.civicrm.cdntaxreceipts')));
    }

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

    // Add Receipt Types
    $receiptTypes = ['original', 'duplicate', 'ineligibles'];
    $this->assign('receiptTypes', $receiptTypes);

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
    $this->add('checkbox', 'thankyou_email', ts('Send a custom Thank You Email Message', array('domain' => 'org.civicrm.cdntaxreceipts')));


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

    //CRM-921: Integrate WYSWIG Editor on the form
    CRM_Contribute_Form_Task_PDFLetterCommon::buildQuickForm($this);
    if($this->elementExists('from_email_address')) {
      $this->removeElement('from_email_address');
    }
    $from_email_address = current(CRM_Core_BAO_Domain::getNameAndEmail(FALSE, TRUE));
    $this->add('text', 'from_email_address', ts('From Email Address'), $from_email_address, TRUE);
    $this->add('text', 'email_options', ts('Print and Email Options'), 'email', FALSE);
    $this->add('text', 'group_by_separator', ts('Group By Seperator'), 'comma', FALSE);
    $defaults = [
      'margin_left' => 0.75,
      'margin_right' => 0.75,
      'margin_top' => 0.75,
      'margin_bottom' => 0.75,
      'email_options' => 'email',
      'from_email_address' => $from_email_address,
      'group_by_separator' => 'comma',
      'thankyou_date' => 1,
      'receipt_option' => 0,
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

    // lets get around the time limit issue if possible
    if ( ! ini_get( 'safe_mode' ) ) {
      set_time_limit( 0 );
    }

    $params = $this->controller->exportValues($this->_name);

    $originalOnly = TRUE;
    if ($params['receipt_option']) {
      $originalOnly = FALSE;
    }

    $previewMode = FALSE;
    $buttonName = $this->controller->getButtonName();
    if($buttonName == '_qf_IssueSingleTaxReceipts_submit') {
      $previewMode = TRUE;
    }

    if (isset($params['is_preview']) && $params['is_preview'] == 1 ) {
      $previewMode = TRUE;
    }

    /**
     * Drupal module include
     */
    //module_load_include('.inc','civicrm_cdntaxreceipts','civicrm_cdntaxreceipts');
    //module_load_include('.module','civicrm_cdntaxreceipts','civicrm_cdntaxreceipts');

    // start a PDF to collect receipts that cannot be emailed
    $receiptsForPrinting = cdntaxreceipts_openCollectedPDF();

    $emailCount = 0;
    $printCount = 0;
    $dataCount = 0;
    $failCount = 0;

    foreach ($this->_contributionIds as $item => $contributionId) {
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
        CRM_Core_Error::fatal( "CDNTaxReceipts: Could not find corresponding contribution id." );
      }

      // 2. If Contribution is eligible for receipting, issue the tax receipt.  Otherwise ignore.
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
                    $thankyou_html = CRM_Cdntaxreceipts_Task_PDFLetterCommon::postProcess($this, $params);
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
          list( $ret, $method ) = cdntaxreceipts_issueTaxReceipt( $contribution, $receiptsForPrinting, $previewMode );
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
    cdntaxreceipts_sendCollectedPDF($receiptsForPrinting, 'Receipts-To-Print-' . (int) $_SERVER['REQUEST_TIME'] . '.pdf');  // EXITS.
  }
}

