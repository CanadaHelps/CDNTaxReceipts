<?php

require_once('CRM/Contribute/Form/Task.php');

/**
 * This class provides the common functionality for issuing CDN Tax Receipts for
 * one or a group of contact ids.
 */
class CRM_Cdntaxreceipts_Task_IssueSingleTaxReceipts extends CRM_Contribute_Form_Task {

  const MAX_RECEIPT_COUNT = 1000;

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

    $receipts = array( 'original'  => array('email' => 0, 'print' => 0, 'data' => 0),
                       'duplicate' => array('email' => 0, 'print' => 0, 'data' => 0), );

    // count and categorize contributions
    foreach ( $this->_contributionIds as $id ) {
      $key = 'ineligibles';
      if ( cdntaxreceipts_eligibleForReceipt($id) ) {
        list($issued_on, $receipt_id, $receipt_status) = cdntaxreceipts_issued_on($id);
        $key = (empty($issued_on) || $receipt_status == 'cancelled') ? 'original' : 'duplicate';
        list( $method, $email ) = cdntaxreceipts_sendMethodForContribution($id);
        $receipts[$key][$method]++;
      }

      // temporary set array with type of receipt for each contribution
      // so that we can use in setReceiptsList
      $this->_receiptList[$id] = $key;

    }

    $this->_receipts = $receipts;
    list($this->_years, $this->_receiptList) = CRM_Cdntaxreceipts_Task_IssueSingleTaxReceipts::getReceiptsList($this->_contributionIds, $this->_receiptList);
  }

  /**
   * Build the form
   *
   * @access public
   *
   * @return void
   */
  function buildQuickForm() {

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

    $delivery_method = Civi::settings()->get('delivery_method') ?? CDNTAX_DELIVERY_PRINT_ONLY;
    $this->assign('deliveryMethod', $delivery_method);

    // add radio buttons
    $this->addElement('radio', 'receipt_option', NULL, ts('Issue tax receipts for the %1 unreceipted contributions only.', array(1=>$originalTotal, 'domain' => 'org.civicrm.cdntaxreceipts')), 'original_only');
    $this->addElement('radio', 'receipt_option', NULL, ts('Issue tax receipts for all %1 contributions. Previously-receipted contributions will be marked \'duplicate\'.', array(1=>$receiptTotal, 'domain' => 'org.civicrm.cdntaxreceipts')), 'include_duplicates');
    $this->addRule('receipt_option', ts('Selection required', array('domain' => 'org.civicrm.cdntaxreceipts')), 'required');

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

    // Hide PREVIEW button for already receipted
    if (($receiptTotal === $duplicateTotal)&&!empty($duplicateTotal) &&  empty($originalTotal)) {
      unset($buttons[1]);
    }

    //CRM-921: Integrate WYSWIG Editor on the form
    CRM_Contact_Form_Task_PDFLetterCommon::buildQuickForm($this);

    $this->addButtons($buttons);

    // CH Customization
    $this->customizeForm();
  }

  function setDefaultValues() {
    $from_email_address = current(CRM_Core_BAO_Domain::getNameAndEmail(FALSE, TRUE));
    return array(
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

    // Preview mode ?
    $previewMode = FALSE;
    $buttonName = $this->controller->getButtonName();
    if($buttonName == '_qf_IssueSingleTaxReceipts_submit') {
      $previewMode = TRUE;
    }

    if (isset($params['is_preview']) && $params['is_preview'] == 1 ) {
      $previewMode = TRUE;
    }

    //CRM-1819-Disable preview function for already issued tax receipts
    if($previewMode && !$originalOnly) {
      $originalOnly = TRUE;
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
        throw new CRM_Core_Exception("CDNTaxReceipts: Could not find corresponding contribution id.");
      }

      // Only process Contributions of selected Year
      if($contribution->receive_date) {
        $receive_year = 'issue_'.date("Y", strtotime($contribution->receive_date));
        if($receive_year !== $params['receipt_year']) {
          continue;
        }
      }

      // 2. If Contribution is eligible for receipting, issue the tax receipt.  Otherwise ignore.
      if ( cdntaxreceipts_eligibleForReceipt($contribution->id) ) {

        list($issued_on, $receipt_id) = cdntaxreceipts_issued_on($contribution->id);

        // check if most recent is cancelled, and mark as "replace"
        $cancelledReceipt = CRM_Canadahelps_TaxReceipts_Receipt::retrieveReceiptDetails($contribution->id, true);
        if ($cancelledReceipt[0] != NULL && $receipt_id == $cancelledReceipt[1]) {
          if ($cancelledReceipt[2] == 'cancelled' && $cancelledReceipt[4] == 'aggregate') {
            $cancelledReceiptContribIds = $cancelledReceipt[3];
             //CRM-1993 if aggregate receipt has only single contribution in that case for issuing seperate or manage receipt 'cancel and replace receipt number' text should be visible.
             if(count($cancelledReceiptContribIds) == 1 && $cancelledReceiptContribIds[0] == $contribution->id )
             $contribution->cancelled_replace_receipt_number  = $cancelledReceipt[0];
           }else{
            $contribution->cancelled_replace_receipt_number  = $cancelledReceipt[0];
          }
          $contribution->replace_receipt  = 1;
          $issued_on = '';
        }
        if ( empty($issued_on) || ! $originalOnly ) {
          //CRM-920: Thank-you Email Tool
          if ($sendThankYouEmail) {
            $params['contactID'] = $contribution->contact_id;
            $thankyou_html = $this->getThankYouHTML([$contribution->id], $from_email_address, $params);
            if ($thankyou_html != NULL)
              $contribution->thankyou_html = $thankyou_html;
          }

          list( $ret, $method ) = cdntaxreceipts_issueTaxReceipt( $contribution, $receiptsForPrinting, $previewMode );
          if( $ret !== 0 && !$previewMode ) {
            //CRM-918: Mark Contribution as thanked if checked
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

    // 3. Set session status
    $receiptCount = [];
    if(!$previewMode) {
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
    cdntaxreceipts_sendCollectedPDF($receiptsForPrinting, 'Receipts-To-Print-' . (int) $_SERVER['REQUEST_TIME'] . '.pdf', $receiptCount);  // EXITS.
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

  /**
   * build receipt data structures needed to show contrib details
   *
   * @return void
   */
  static function getReceiptsList(array $contributionIds, array $receipts, bool $isAggregate = false): array {

    $years = array();
    $receiptList = [
      'original' => [],
      'duplicate' => [],
      'ineligibles' => [],
      'totals' => []
    ];
    $eligible_contact_ids = [];
    $contributionsDetails = CRM_Cdntaxreceipts_Task_IssueSingleTaxReceipts::getContributionsDetails($contributionIds);
    $indexOfContributionsIds = $contributionsDetails->column('id');

    foreach ( $receipts as $id => $receiptType ) {
      // Search for details within api results
      $indexAt = array_search($id, $indexOfContributionsIds);
      if ($indexAt !== false) {
        $result     = $contributionsDetails->itemAt($indexAt);
        $year       = $result['receive_year'];
        $contact_id = $result['contact_id'];

        // Init year arrays
        if (!array_key_exists($year, $receiptList[$receiptType])) {
          $receiptList[$receiptType][$year] = [
            'total_contrib'  => 0,
            'total_amount'   => 0,
            'total_contacts' => 0,
            'duplicates'    => 0
          ];
        }

        // Add year
        $years[$year] = $year;

        // Eligible?
        $result['eligible'] = ($receiptType != 'ineligibles');

        // In-Kind
        if ($isAggregate && $result['inkind']) {
          $result['eligibility_reason'] = '(InKind)';
          $result['ineligible_reason']  = 'InKind';
          $result['eligibility_fix']    = "Separate tax receipts are issued for In Kind donations";
        }

        // Duplicate
        if ($receiptType == 'duplicate') {
          $result['eligibility_reason'] = '(Duplicate)';
          $result['ineligible_reason'] = 'Duplicate';

        // Ineligible Reason
        } else if (!$result['eligible']) {
          $contribObject = json_decode(json_encode($result));
          $result['ineligible_reason'] =  canadahelps_isContributionEligibleForReceipting($contribObject);
          if (!empty($result['ineligible_reason'])) {
            $result['eligibility_reason'] = '('.$result['ineligible_reason'].')';
            switch ($result['ineligible_reason']) {
               //CRM-2005
              case 'Non Deductible Amount':
                $result['eligibility_fix'] = "Advantage amount is 100% of contribution.";
                break;

              case 'Incomplete Address':
                $result['eligibility_fix'] = "The donor's address is required. Please <a href=\"/dms/contact/view?reset=1&cid=$contact_id\">update</a> to issue a tax receipt.";
                break;

              case 'Incomplete Name':
                $result['eligibility_fix'] = "The donor's name is required. Please <a href=\"/dms/contact/view?reset=1&cid=$contact_id\">update</a> to issue a tax receipt.";
                break;

              case 'Receipted Outside of DMS':
                $result['eligibility_fix'] = "Receipted Outside of DMS.";
                break;

              case 'Currency Not Supported':
                $result['eligibility_fix'] = "Please convert currency to ".CRM_Core_Config::singleton()->defaultCurrency." to issue tax receipt.";
                break;
            }
          }
        }

        // Contact
        if ( $contact_id ) {
          $receiptList[$receiptType][$year]['contact_ids'][$contact_id]['display_name'] = $result['contact_id.display_name'];
          $receiptList[$receiptType][$year]['contact_ids'][$contact_id]['contributions'][$id] = $result;
        }

        // Count the totals
        // By type + year
        $receiptList[$receiptType][$year]['total_contrib']++;
        //CRM-2133 updated total_amount with Eligible contribution amount (considering non deductable amount) not total amount
        $receiptList[$receiptType][$year]['total_amount'] += cdntaxreceipts_eligibleAmount($id);
        $receiptList[$receiptType][$year]['total_amount']   = round($receiptList[$receiptType][$year]['total_amount'], 2);
        $receiptList[$receiptType][$year]['total_contacts'] = count($receiptList[$receiptType][$year]['contact_ids']);
        if ($receiptType == 'duplicate')
          $receiptList[$receiptType][$year]['duplicates']++;
      }
    }

    // Count the totals
    // By year
    foreach ( $years as $year ) {

      foreach ($receiptList as $receiptType => $receiptTypeArray) {
        if ($receiptType != "totals" && isset($receiptTypeArray[$year])) {

          if (!array_key_exists($year, $receiptList['totals'])) {
            $receiptList['totals'][$year] = [
              'total_contrib'     => 0,
              'total_amount'      => 0,
              'total_contacts'    => 0,
              'eligible_contrib'  => 0,
              'eligible_amount'   => 0,
              'eligible_contacts' => 0,
              'duplicate_contrib' => 0
            ];
          }

          // All selected
          $receiptList['totals'][$year]['total_contrib']  += $receiptTypeArray[$year]['total_contrib'];
          $receiptList['totals'][$year]['total_amount'] += $receiptTypeArray[$year]['total_amount'];
          $receiptList['totals'][$year]['total_contacts'] += $receiptTypeArray[$year]['total_contacts'];

          // Eligible only (original)
          if ($receiptType == 'original') {
            $receiptList['totals'][$year]['eligible_contrib']  += $receiptTypeArray[$year]['total_contrib'];
            $receiptList['totals'][$year]['eligible_amount'] += $receiptTypeArray[$year]['total_amount'];
            $receiptList['totals'][$year]['eligible_contacts'] += $receiptTypeArray[$year]['total_contacts'];

          // Duplicates only
          } else if ($receiptType == 'duplicate') {
            $receiptList['totals'][$year]['duplicate_contrib']  += $receiptTypeArray[$year]['duplicates'];
          }
        }
      }
      $receiptList['totals'][$year]['skipped_contrib'] = $receiptList['totals'][$year]['total_contrib'] - $receiptList['totals'][$year]['eligible_contrib'] - $receiptList['totals'][$year]['duplicate_contrib'];
    }

    // Override our array w/ updated data
    return [$years, $receiptList];
  }

  static function getContributionsDetails(array $contributionIds) {
    $contributions = \Civi\Api4\Contribution::get()
      ->addSelect('financial_type_id', 'contribution_page_id', 'contact_id', 'source', 'contribution_status_id', 'payment_instrument_id', 'total_amount', 'financial_type_id', 'receive_date', 'payment_instrument_id:label', 'contribution_status_id:label', 'contribution_page_id:label', 'financial_type_id:label', 'contact_id.display_name')
      ->addWhere('id', 'IN', $contributionIds)
      ->setLimit(count($contributionIds))
      ->execute();

    // Edit results as needed
    foreach ($contributions as &$result) {
      $year       = 0;

      // Format receive date + Add year
      if ($result['receive_date']) {
        $timestamp  = strtotime($result['receive_date']);
        $year       =  date("Y", $timestamp);
        $result['receive_time'] = date("h:i A", $timestamp);
        $result['receive_year'] = $year;
        $result['receive_date'] = date("F jS, Y", $timestamp);
      }

      // Backward Compatibility
      $result['contribution_source']  = $result['source'];
      $result['payment_instrument']   = $result['payment_instrument_id:label'];
      $result['contribution_status']  = $result['contribution_status_id:label'];
      $result['contribution_id']      = $result['id'];

      // Fund, Campaign
      $result['fund']     = $result['financial_type_id:label'];
      $result['campaign'] = $result['contribution_page_id:label'];

      // InKind
      $clean_fund = preg_replace("/[^a-zA-Z0-9]+/", "", $result['fund']);
      $result['inkind'] = ( stripos($clean_fund,"inkind") !== false);

    }

    return $contributions;
  }

  private function customizeForm() {

    //CRM-918: Add Custom Stylesheet to pages as well
    CRM_Core_Resources::singleton()->addStyleFile('org.civicrm.cdntaxreceipts', 'css/receipt_module.css');

    $this->assign('receiptList', $this->_receiptList);
    Civi::resources()->addVars('receipts', ['receiptList' => $this->_receiptList]);
    $this->assign('receipt_type', 'single');

    // Add Receipt Types
    $receiptTypes = ['original', 'duplicate', 'ineligibles'];
    $this->assign('receiptTypes', $receiptTypes);
    Civi::resources()->addVars('receipts', ['receiptTypes' => $receiptTypes]);

    // Duplicates?
    if ($this->elementExists('receipt_option')) {
      $this->removeElement('receipt_option', true);
      $this->removeElement('receipt_option', true); // need it twice because added twice
    }
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

  /**
   * Mark contribution as thanked and update receipt date
   *
   * @return void
   */
  static function markContributionAsReceipted(int $contributionId, $thanked = TRUE, $updateReceiptDate = TRUE): void {
    $contribution = new CRM_Contribute_DAO_Contribution();
    $contribution->id = $contributionId;
    if ( ! $contribution->find( TRUE ) ) {
      throw new CRM_Core_Exception("CDNTaxReceipts: Could not find corresponding contribution id.");
    }
    if ($thanked) {
      $contribution->thankyou_date = date('Y-m-d H:i:s', CRM_Utils_Time::time());
    }
    if ($updateReceiptDate) {
      $contributionReceiptDate = cdnaxreceipts_getReceiptDate($contribution->id);
      if ($contributionReceiptDate && !empty($contributionReceiptDate)) {
        $contribution->receipt_date = $contributionReceiptDate;
      }
    }
    $contribution->save();
  }
}
