<?php

use CRM_Canadahelps_ExtensionUtils as EU;

require_once 'tcpdf/tcpdf.php';
require_once('FPDI/fpdi.php');

  class PDF extends FPDI {
    /**
     * "Remembers" the template id of the imported page
     */
    var $_tplIdx;

    /**
     * include a background template for every page
     */
    function Header() {
      $pdf_template_file = _cdntaxreceipts_get_image_filepath('receipt_pdftemplate');
      if (!empty($pdf_template_file)) {

        if (is_null($this->_tplIdx)) {
          $this->setSourceFile($pdf_template_file);
          $this->_tplIdx = $this->importPage(1);
        }
        $this->useTemplate($this->_tplIdx);
      }
      else {
        $this->parsers = array();
      }
    }

    function Footer() {}
  }



  const CDNTAX_SETTINGS = 'CDNTaxReceipts';
  const CDNTAX_DELIVERY_PRINT_ONLY = 0;
  const CDNTAX_DELIVERY_PRINT_EMAIL = 1;
  const CDNTAX_DELIVERY_DATA_ONLY = 2;

/**
 * processTaxReceipt()
 * Accepts an associative array containing receipt variables, and:
 * - generates a PDF file using the provided variables
 * - sends a copy of the receipt to the email archive
 * - emails the receipt to the donor if issue_method='email'
 * - logs the receipt to the audit log
 *
 * This method is common to both single and annual receipts.
 */
function cdntaxreceipts_processTaxReceipt($receipt, &$collectedPdf = NULL, $mode = NULL, $method = NULL) {

  // Get contact details
  list($displayname, $email) = CRM_Contact_BAO_Contact::getContactDetails($receipt['contact_id']);

  // add support for preferred language
  $old_locale = CRM_Core_I18n::singleton()->getLocale();
  $preferred_language = CRM_Core_DAO::getFieldValue('CRM_Contact_DAO_Contact', $receipt['contact_id'], 'preferred_language');
  _cdntaxreceipts_setReceiptanguage($preferred_language);

  // @todo Deprecate this function - see issue 103. This used to be on the settings form but has been removed. For now if it had been previously set we still use the same (broken) implementation from before.
  $serial_receipt = Civi::settings()->get('receipt_serial');
  if ($serial_receipt && empty($receipt['is_duplicate'])) {
    // use my experimental alternative to generating serial receipt numbers, unless this is a re-issue
    $lock = new CRM_Core_Lock('cdntaxreceipts.processTaxReceipt');
    if (!$lock->acquire()) {
      $userAlert = ts('Failed to acquire lock. No receipts were processed.');
      CRM_Core_Session::setStatus($userAlert, ts('Warning'), 'alert');
      return array(FALSE,'',NULL);
    }
    $next_id = cdntaxreceipts_log_next_id();
    //CRM-1340 When previewing tax receipts, the tax receipt number should be a static placeholder
    if ($mode == CDNTAXRECEIPTS_MODE_PREVIEW) {
      $receipt['receipt_no'] = Civi::settings()->get('receipt_prefix') .'XXXXXXXX';
    }else{
      $receipt['receipt_no'] = Civi::settings()->get('receipt_prefix') . str_pad($next_id, 8, 0, STR_PAD_LEFT);
    }
  }
  // allow modules to alter any details
  // invoke hook_cdntaxreceipts_alter_receipt(&$receipt)
  //   modules should accept a reference to the $receipt array and alter it directly if they wish
  //   one useful use case: alter the $receipt['source'] line to include custom text
  //   to alter receipt amount, use hook_cdntaxreceipts_eligibleAmount() instead
  CRM_Utils_Hook::singleton()->invoke(['receipt'], $receipt, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, 'cdntaxreceipts_alter_receipt');

  if ($receipt['issue_method'] == 'data') {
    // generate log entry only, no PDF file needed
    $pdf_file = NULL;
    $sent = TRUE;
  }
  else {
    // generates custom PDF file
    // this completely replaces the following functions
    // - cdntaxreceipts_generateFormattedReceipt
    // - _cdntaxreceipts_writeReceipt
    // - _cdntaxreceipts_writePage
    // - _addItemizedDetails
    // @todo move other customizations to CRM_Canadahelps_TaxReceipts_Receipt
    $customReceipt = new CRM_Canadahelps_TaxReceipts_Receipt($receipt['receipt_no'], $receipt);
    $customReceipt->setMode($mode);
    $customReceipt->setCollectedPDF($collectedPdf);
    $customReceipt->setUploadDir(CRM_Core_Config::singleton()->uploadDir);
    $customReceipt->setDefaultImagesDir( dirname(__FILE__) . '/img/' );
    $customReceipt->setFontsDir( dirname(__FILE__) . '/fonts/' );
    $customReceipt->generatePDF();

    // @todo check for errors during PDF generation / empty -> do not proceed if so
    // @todo CRM-1897
    $pdf_file = $customReceipt->getFilePath();
    $user_friendly = $customReceipt->getFileName();

    /* NOT USED
    // generate the PDF file
    list($pdf_file, $user_friendly) = cdntaxreceipts_generateFormattedReceipt($receipt, $collectedPdf, $mode);
    */

    if ($mode == CDNTAXRECEIPTS_MODE_PREVIEW) {
      // no email, just PDF
      $sent = TRUE;
    }

    // @todo move this section to CRM_Canadahelps_TaxReceipts_Receipt
    // -> sendToDonor
    // -> archiveReceipt
    else {
      // generate an email to the tax archive and possibly to the donor
      $org_name = Civi::settings()->get('org_name');
      $email_from = Civi::settings()->get('email_from');
      $email_archive = Civi::settings()->get('email_archive');

      // get all the basic contact information
      $params = array(
        'id' => $receipt['contact_id'],
      );
      $contact = civicrm_api3('contact', 'getsingle', $params);

      // add in the greetings (not return as part of the api result)
      $params = array(
        'id' => $receipt['contact_id'],
        'return.email_greeting' => 1,
      );
      $greetings = civicrm_api3('contact', 'getsingle', $params);
      $contact['email_greeting_display'] = $greetings['email_greeting_display'];

      $attachment = array(
        'fullPath' => $pdf_file,
        'mime_type' => 'application/pdf',
        'cleanName' => $user_friendly,
      );

      $tplParams = array(
        'orgName' => $org_name,
        'contact' => $contact,
        'receipt' => $receipt,
      );

      $sendTemplateParams = array(
        'groupName' => 'msg_tpl_workflow_cdntaxreceipts',
        'contactId' => $receipt['contact_id'],
        'tplParams' => $tplParams,
        'from' => $org_name . ' <' . $email_from . '> ',
        'toName' => $contact['display_name'],
        'attachments' => array( $attachment ),
      );

      if ($receipt['issue_type'] == 'single') {
        $sendTemplateParams['valueName'] = 'cdntaxreceipts_receipt_single';
      }
      else {
        $sendTemplateParams['valueName'] = 'cdntaxreceipts_receipt_aggregate';
      }

      // send a copy (without open tracking) to the tax archive
      $sendTemplateParams['toEmail'] = $email_archive;
      $sendTemplateParams['thankyou_email'] = FALSE;
      if($receipt['thankyou_html']) {
        $sendTemplateParams['thankyou_email'] = TRUE;
        $sendTemplateParams['html'] = $receipt['thankyou_html'];
      }
      list($sent, $subject, $message, $html) = ch_sendTemplate($sendTemplateParams);

      // send a copy (with open tracking) to the donor
      if ($sent && $receipt['issue_method'] == 'email' && $mode != CDNTAXRECEIPTS_MODE_WORKFLOW) {
        $sendTemplateParams['toEmail'] = $contact['email'];
        $sendTemplateParams['tplParams']['openTracking'] = "<img src=\"" . CRM_Utils_System::baseURL() .
          "civicrm/cdntaxreceipts/open?r={$receipt['email_tracking_id']}\" width='1' height='1' alt='' border='0'>";
        list($sent, $subject, $message, $html) = ch_sendTemplate($sendTemplateParams);
      }
    }
  }
  if (!empty($lock)) {
    $lock->release();
  }

  // Switch the locale back to the original locale
  CRM_Core_I18n::singleton()->setLocale($old_locale);

  // log the receipt
  if (!$receipt['is_duplicate'] && $sent && $mode != CDNTAXRECEIPTS_MODE_PREVIEW) {
    cdntaxreceipts_log($receipt);
    // @todo move this section to CRM_Canadahelps_TaxReceipts_Receipt
    // -> updateContributionWithReceiptInfo
    //CRM-1270
    if($receipt['issued_on']) {
      foreach($receipt['contributions'] as $contribution) {
        $convert_date = date('Y-m-d H:i:s',$receipt['issued_on']);
        $results = civicrm_api4('Contribution', 'update', [
          'values' => [
            'receipt_date' => $convert_date,
          ],
          'where' => [
            ['id', '=', $contribution['contribution_id']],
          ],
        ]);
      }
    }
    //CRM-1057: Update Receipt number and flag is_receipted custom field
    if($receipt['receipt_no']) {
      foreach($receipt['contributions'] as $contribution) {
        civicrm_api3('CustomValue', 'create', [
          'entity_id' => $contribution['contribution_id'],
          EU::getCustomFieldID('Is_Receipted_') => 1,
          EU::getCustomFieldID('Receipt_Number') => $receipt['receipt_no'],
        ]);
      }
    }
    // TODO: log an activity
  }

  if (isset($collectedPdf) && $pdf_file) {
    unlink($pdf_file); // file is no longer needed
    $pdf_file = NULL;
  }

  // if not unset, cron will clean up the PDF file later on
  return array( $sent, $receipt['issue_method'], $pdf_file );
}

/**
 * generateFormattedReceipt()
 * Accepts an associative array of receipt variables and produces a PDF file.
 * This function should always be called through processTaxReceipt().  It is imperative
 * that each receipt generated by the system gets logged and archived properly.
 */
function cdntaxreceipts_generateFormattedReceipt($receipt, &$collectedPdf = NULL, $mode = NULL) {

  // !!! this entire code is no longer used
  // shoulb be reverted back to core

  $pdf_img_files_path = dirname(__FILE__) . '/img/';

  $address = cdntaxreceipts_getAddress($receipt['contact_id']);

  $address_line_1 = isset($address['street_address']) ? $address['street_address'] : '';
  $parts = array();
  foreach(array('city', 'state_province', 'postal_code')  as $addr_part) {
    if (isset($address[$addr_part])) {
      $parts[] = $address[$addr_part];
    }
  }
  $address_line_1b = isset($address['supplemental_address_1']) ? $address['supplemental_address_1'] : '';
  $address_line_2 = implode(' ', $parts);
  $address_line_3 = isset($address['country']) ? $address['country'] : '';

  list($displayname, $email) = CRM_Contact_BAO_Contact::getContactDetails($receipt['contact_id']);

  $result_get_name = civicrm_api3('Contact', 'get', array(
    'sequential' => 1,
    'return' => array("first_name", "last_name", "middle_name","contact_type"),
    'id' => $receipt['contact_id'],
  ));

  if ($result_get_name['values'][0]['contact_type'] == 'Individual') {
    if (strlen(trim($result_get_name['values'][0]['middle_name'])) > 0) {
      $displayname = $result_get_name['values'][0]['first_name'] . ' ' . $result_get_name['values'][0]['middle_name'][0] . ' ' . $result_get_name['values'][0]['last_name'];
    }
    else {
      $displayname = $result_get_name['values'][0]['first_name'] . ' ' . $result_get_name['values'][0]['last_name'];
    }
  }

  if ( $receipt['issue_type'] == 'single' ) {
    $pos = strpos($receipt['receive_date'], '-');
    if ($pos === FALSE) {
      $date = substr($receipt['receive_date'], 0, 8);
      $display_date = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
    }
    else {
      $display_date = substr($receipt['receive_date'], 0, 10);
    }
    $display_year = '';
  }
  else {
    $display_year = $receipt['receive_date'];
    $display_date = date('M j, Y', mktime(0,0,0,1,1,$receipt['receive_date'])) . ' - ' . date('M j, Y', mktime(0,0,0,12,31,$receipt['receive_date']));
  }

  $line_1 = ts("This is your Official Receipt for income tax purposes.", array('domain' => 'org.civicrm.cdntaxreceipts'));

  $config = CRM_Core_Config::singleton();
  $donor_or_org_name = '';
  if ($receipt['issue_method'] == 'print') {
    $donor_or_org_name = '-' . CRM_Utils_File::makeFilenameWithUnicode($displayname);
  }
  if ($receipt['issue_method'] == 'email') {
    $donor_or_org_name = '-' . CRM_Utils_File::makeFilenameWithUnicode(Civi::settings()->get('org_name')) . '-Donation';
  }
  $pdf_file = $config->uploadDir . 'Receipt-' . $receipt['receipt_no'] . $donor_or_org_name . '.pdf';
  $user_friendly = 'Receipt-' . $receipt['receipt_no'] . $donor_or_org_name . '.pdf';

  if (!defined('PDF_PAGE_FORMAT')) {
    define('PDF_PAGE_FORMAT', 'USLETTER'); // orig: 'A4'
  }
  $pdf = new PDF(PDF_PAGE_ORIENTATION, PDF_UNIT, 'LETTER', TRUE, 'UTF-8', FALSE);
  $pdf->Open();

  $pdf->SetAuthor(Civi::settings()->get('org_name'));

  $mymargin_left = 12;
  $mymargin_top = 6;
  $mymargin_right = 12;
  $pdf->SetMargins($mymargin_left, $mymargin_top, $mymargin_right);

  $pdf->setJPEGQuality('100');

  $pdf->SetAutoPageBreak('', $margin=0);

  $pdf_variables = array(
    "mode" => $mode,
    "mymargin_left" => $mymargin_left,
    "mymargin_top" => $mymargin_top,
    "is_duplicate" => $receipt['is_duplicate'],
    "pdf_img_files_path" => $pdf_img_files_path,
    "line_1" => $line_1,
    "source_funds" => isset($receipt['source']) ? $receipt['source'] : '',
    "amount" => $receipt['receipt_amount'],
    "display_date" => $display_date,
    "display_year" => $display_year,
    "issued_on" => date('Y-m-d', $receipt['issued_on']),
    "location_issued" => _cdntaxreceipts_get_location(),
    "issue_type" => $receipt['issue_type'],
    "receipt_number" => $receipt['receipt_no'],
    "displayname" => $displayname,
    "address_line_1" => $address_line_1,
    "address_line_1b" => $address_line_1b,
    "address_line_2" => $address_line_2,
    "address_line_3" => $address_line_3,
    "inkind_values" => isset($receipt['inkind_values']) ? $receipt['inkind_values'] : array(),
    "receipt_contributions" => $receipt['contributions'],
    "receipt_status" => $receipt['receipt_status'],
  );

  $output_files = array($pdf);
  if ( isset($collectedPdf) && ( $receipt['issue_method'] == 'print' || $mode == CDNTAXRECEIPTS_MODE_PREVIEW) ) {
    $output_files[] = $collectedPdf;
  }

  foreach ( $output_files as $f ) {
    $f->AddPage();
    _cdntaxreceipts_writePage($f, $pdf_variables, $receipt);
  }

  // close and output the single receipt, but not the collected receipts...
  $pdf->Close();
  $pdf->Output($pdf_file, 'F');

  return array($pdf_file, $user_friendly);
}

function _cdntaxreceipts_writePage(&$f, $pdf_variables, $receipt) {

  // !!! this entire code is no longer used
  // shoulb be reverted back to core

  //
  // hook_cdntaxreceipts_writeReceipt(&$pdf, $pdf_variables, $receipt)
  //
  // If there's a custom function to write the page, invoke it.
  // Otherwise, provide a default receipt format.
  //
  // return array(TRUE) from the hook if you are completely replacing the
  // default implementation, otherwise return anything that isn't an array
  // if you want the default implementation to still write after yours.
  //
  // Note: if hook_cdntaxreceipts_writeReceipt is implemented in more than one active
  // module/extension, the receipt will get written into the PDF file multiple
  // times. It's one peril of using the hook system here. Implementers will need to catch
  // this when testing their particular installs.
  //

  $results = CRM_Utils_Hook::singleton()->invoke(['f', 'pdf_variables', 'receipt'], $f, $pdf_variables, $receipt, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, 'cdntaxreceipts_writeReceipt');

  if (is_array($results)) {
    foreach ($results as $result) {
      if ($result == TRUE) {
        return;
      }
    }
  }

  // if no custom function, go with default formatting...
  if ($pdf_variables['is_duplicate']) {
    // print duplicate copy
    _cdntaxreceipts_writeReceipt($f, $pdf_variables);
  }
  else {
    // print original AND duplicate copy
    $mymargin_top = $pdf_variables['mymargin_top'];
    _cdntaxreceipts_writeReceipt($f, $pdf_variables);
    $pdf_variables["mymargin_top"] = $mymargin_top + 90;
    $pdf_variables["is_duplicate"] = TRUE;
    _cdntaxreceipts_writeReceipt($f, $pdf_variables);
    $pdf_variables["mymargin_top"] = $mymargin_top + 90*2;
    $pdf_variables["is_duplicate"] = TRUE;
    _cdntaxreceipts_writeReceipt($f, $pdf_variables);
  }
}

function _cdntaxreceipts_writeReceipt(&$pdf, $pdf_variables) {

  // Extract variables
  // @todo Why do we do this?
  $mode = $pdf_variables["mode"];
  $mymargin_left = $pdf_variables["mymargin_left"];
  $mymargin_top = $pdf_variables["mymargin_top"];
  $is_duplicate = $pdf_variables["is_duplicate"];
  $pdf_img_files_path = $pdf_variables["pdf_img_files_path"];
  $line_1 = $pdf_variables["line_1"];
  $source_funds = $pdf_variables["source_funds"];
  $amount = $pdf_variables["amount"];
  $display_date = $pdf_variables["display_date"];
  $issued_on = $pdf_variables["issued_on"];
  $location_issued = $pdf_variables["location_issued"];
  $receipt_number = $pdf_variables["receipt_number"];
  $displayname = $pdf_variables["displayname"];
  $address_line_1 = $pdf_variables["address_line_1"];
  $address_line_1b = $pdf_variables["address_line_1b"];
  $address_line_2 = $pdf_variables["address_line_2"];
  $address_line_3 = $pdf_variables["address_line_3"];
  $inkind_values = $pdf_variables["inkind_values"];
  $display_year = $pdf_variables["display_year"];
  $issue_type = $pdf_variables["issue_type"];
  $receipt_contributions = $pdf_variables['receipt_contributions'];
  $receipt_status = $pdf_variables['receipt_status'];

  // Middle center section
  if ($mode == CDNTAXRECEIPTS_MODE_PREVIEW) {
    $pdf->Image($pdf_img_files_path . 'preview_mode.png', $mymargin_left + 65, $mymargin_top, '', 45);
  }
  else if ($receipt_status == 'cancelled') {
    $pdf->Image($pdf_img_files_path . 'cancelled_trans.png', $mymargin_left + 65, $mymargin_top, '', 45);
  }
  else if ($is_duplicate) {
    $pdf->Image($pdf_img_files_path . 'duplicate_trans.png', $mymargin_left + 65, $mymargin_top, '', 45);
  }
  // Top left section
  $pdf_template_file = _cdntaxreceipts_get_image_filepath('receipt_pdftemplate');
  if (empty($pdf_template_file)) {
    $receipt_logo = _cdntaxreceipts_get_image_filepath('receipt_logo');
    if ( empty($receipt_logo) ) {
      $receipt_logo = $pdf_img_files_path . 'your-logo.png';
    }
    $pdf->Image($receipt_logo, $mymargin_left, $mymargin_top, '', 30);

    // Top right section
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetY($mymargin_top);
    $pdf->Write(10, Civi::settings()->get('org_name'), '', 0, 'R', TRUE, 0, FALSE, FALSE, 0);
    $pdf->SetY($mymargin_top + 4);
    $pdf->Write(10, Civi::settings()->get('org_address_line1'), '', 0, 'R', TRUE, 0, FALSE, FALSE, 0);
    $pdf->SetY($mymargin_top + 8);
    $pdf->Write(10, Civi::settings()->get('org_address_line2'), '', 0, 'R', TRUE, 0, FALSE, FALSE, 0);
    $pdf->SetY($mymargin_top + 12);

    $telTxt = ts('Tel: %1', array(1 => Civi::settings()->get('org_tel'), 'domain' => 'org.civicrm.cdntaxreceipts'));
    if ( Civi::settings()->get('org_fax' ) != '' ) {
      $telTxt .= '; ' . ts('Fax: %1', array(1 => Civi::settings()->get('org_fax'), 'domain' => 'org.civicrm.cdntaxreceipts'));
    }
    $pdf->Write(10, $telTxt, '', 0, 'R', TRUE, 0, FALSE, FALSE, 0);
    $pdf->SetFont('Helvetica', 'I', 8);
    $pdf->SetY($mymargin_top + 16);

    $emailTxt = ts('Email: %1; Website: %2', array(
      1 => Civi::settings()->get('org_email'),
      2 => Civi::settings()->get('org_web'),
      'domain' => 'org.civicrm.cdntaxreceipts')
    );
    $registrationTxt = ts('Charitable Registration: %1', array(1 => Civi::settings()->get('org_charitable_no'), 'domain' => 'org.civicrm.cdntaxreceipts'));

    $pdf->Write(10, $emailTxt, '', 0, 'R', TRUE, 0, FALSE, FALSE, 0);
    $pdf->SetY($mymargin_top + 20);
    $pdf->Write(10, $registrationTxt, '', 0, 'R', TRUE, 0, FALSE, FALSE, 0);
  }

  // Right section
  $x_detailscolumn = 116;
  $y_detailscolumnstart = 22;
  $pdf_template_file = _cdntaxreceipts_get_image_filepath('receipt_pdftemplate');
  if (empty($pdf_template_file)) {
    $background_image = _cdntaxreceipts_get_image_filepath('receipt_watermark');
    if ($background_image) {
      $pdf->Image($background_image, $mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 6, '', 40);
    }
  }
  $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 6);
  $pdf->SetFont('Helvetica', 'B', 8);
  $pdf->Write(10, ts("Issue Date: %1", array(1 => $issued_on, 'domain' => 'org.civicrm.cdntaxreceipts')));
  $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 12);
  $pdf->Write(10, ts("Received on: %1", array(1 => $display_date, 'domain' => 'org.civicrm.cdntaxreceipts')));
  $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 18);
  $pdf->Write(10, ts("Eligible Amount:  $%1", array(1 => number_format($amount, 2), 'domain' => 'org.civicrm.cdntaxreceipts')));
  $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 24);
  $pdf->Write(10, ts("Location Issued: %1", array(1 => $location_issued, 'domain' => 'org.civicrm.cdntaxreceipts')));
  $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 30);

  if ($issue_type == 'annual') {
    $pdf->Write(10, ts("Thank you for giving in %1!", array( 1 => $display_year)));
  } else if ( !empty($inkind_values) ) {
    // If this is an in-kind donation

    $inkind_str = ts('Donation in-kind: %1', array(1 => $inkind_values[0], 'domain' => 'org.civicrm.cdntaxreceipts'));

    if (!empty($inkind_values[3])) {
      if ($inkind_values[3] < $amount) {
          $inkind_str .= ' - ' . ts('Cost: %1', array(1 => $inkind_values[3], 'domain' => 'org.civicrm.cdntaxreceipts'));
      }
    }

    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->Write(10, $inkind_str, ' - ', 0, 'L', TRUE, 0, FALSE, FALSE, 0);

    $appraiser = $inkind_values[1] . ' - '. $inkind_values[2];
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 36);

    if (strlen($appraiser) < 42) {
      $pdf->Write(10, ts("Appraiser: %1", array(1 => $appraiser, 'domain' => 'org.civicrm.cdntaxreceipts')));
    }
    else {
      $appraiser_words = explode(" ", substr($appraiser, 0, 42));
      $appraiser_lastbit = array_pop($appraiser_words);
      $pdf->Write(10, ts("Appraiser: %1", array(1 => implode(" ", $appraiser_words), 'domain' => 'org.civicrm.cdntaxreceipts')));
      $appraiser_count = count($appraiser_words);
      $appraiser_nextline = array_splice(explode(" ", $appraiser), $appraiser_count);
      $pdf->SetXY($mymargin_left + $x_detailscolumn + 8, $mymargin_top + $y_detailscolumnstart + 42);
      $pdf->Write(10, implode(" ", $appraiser_nextline));
    }
  }
  else if (strlen($source_funds) < 42 && strlen($source_funds) > 0) {
    $pdf->Write(10, $source_funds);
  }
  else if (strlen($source_funds) > 0) {
    $source_funds_words = explode(" ", substr($source_funds, 0, 42));
    $source_funds_lastbit = array_pop($source_funds_words);
    $pdf->Write(10, implode(" ", $source_funds_words));
    $source_funds_count = count($source_funds_words);
    // $source_funds_nextline = array_splice(explode(" ", $source_funds), $source_funds_count);
    $exploded = explode(" ", $source_funds);
    $source_funds_nextline = array_splice($exploded, $source_funds_count);
    $pdf->SetXY($mymargin_left + $x_detailscolumn + 16, $mymargin_top + $y_detailscolumnstart + 34);
    $pdf->Write(10, implode(" ", $source_funds_nextline));
  }

  // Left section
  $pdf->SetFont('Helvetica', 'B', 8);
  $pdf->SetY($mymargin_top + $y_detailscolumnstart + 6);
  $pdf->Write(10, ts("Receipt No: %1", array(1 => $receipt_number, 'domain' => 'org.civicrm.cdntaxreceipts')));
  $pdf->SetFont('Helvetica', 'B', 8);
  $pdf->SetY($mymargin_top + $y_detailscolumnstart + 10);
  $pdf->Write(10, ts("Received from: ", array('domain' => 'org.civicrm.cdntaxreceipts')), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
  $pdf->SetFont('Helvetica', 'B', 10);
  $pdf->SetXY($mymargin_left+10, $mymargin_top + $y_detailscolumnstart + 20);
  $pdf->Write(10, strtoupper($displayname), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);

  if (!$address_line_1b){
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetXY($mymargin_left+10, $mymargin_top + $y_detailscolumnstart + 24);
    $pdf->Write(10, strtoupper($address_line_1), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
    $pdf->SetXY($mymargin_left+10, $mymargin_top + $y_detailscolumnstart + 28);
    $pdf->Write(10, strtoupper($address_line_2), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
    $pdf->SetXY($mymargin_left+10, $mymargin_top + $y_detailscolumnstart + 32);
    $pdf->Write(10, strtoupper($address_line_3), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
  }
  else {
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetXY($mymargin_left+10, $mymargin_top + $y_detailscolumnstart + 24);
    $pdf->Write(10, strtoupper($address_line_1), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
    $pdf->SetXY($mymargin_left+10, $mymargin_top + $y_detailscolumnstart + 28);
    $pdf->Write(10, strtoupper($address_line_1b), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
    $pdf->SetXY($mymargin_left+10, $mymargin_top + $y_detailscolumnstart + 32);
    $pdf->Write(10, strtoupper($address_line_2), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
    $pdf->SetXY($mymargin_left+10, $mymargin_top + $y_detailscolumnstart + 36);
    $pdf->Write(10, strtoupper($address_line_3), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
  }

  // Bottom left section
  if (!empty($pdf_template_file)) {
  }
  else {
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetY($mymargin_top + 72);
    $pdf->Write(10, $line_1, '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetY($mymargin_top + 76, 0, 'L', TRUE, 0, FALSE, FALSE, 0);
    $pdf->Write(10, ts('Canada Revenue Agency: canada.ca/charities-giving', array('domain' => 'org.civicrm.cdntaxreceipts')));

    // Bottom center section
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetXY($mymargin_left + 92, $mymargin_top + 74);
    $pdf->Write(10, ts('Thank you!', array('domain' => 'org.civicrm.cdntaxreceipts')));

    // Bottom right section
    $signature = Civi::settings()->get('receipt_authorized_signature_text');
    if ( $signature == '' ) {
      $signature = ts('Authorized Signature', array('domain' => 'org.civicrm.cdntaxreceipts'));
    }
    $sig_offset = strlen($signature) - 20;
    $sig_image = _cdntaxreceipts_get_image_filepath('receipt_signature');
    if (empty($sig_image)) {
      $sig_image = $pdf_img_files_path . 'authorized-signature.png';
    }
    $pdf->Image($sig_image, $mymargin_left + 137, $mymargin_top + 67, '', 15);
    $pdf->Line($mymargin_left + 136, $mymargin_top + 79, $mymargin_left + 186, $mymargin_top + 79);
    $pdf->SetXY($mymargin_left + 148 - $sig_offset, $mymargin_top + 76);
    $pdf->SetFont("Helvetica", "I", 7);
    $pdf->Write(10, $signature);
  }

  // Line at the bottom
  $pdf->Line($mymargin_left, $mymargin_top + 85, 198, $mymargin_top + 85, 'dash');
}

/**************************************
 * SECTION: Utility Functions
 */

/**
 * set up the custom field structure for In-Kind tax receipts
 */

function cdntaxreceipts_configure_inkind_fields() {
  // check if the In-kind contribution type exists.  if not, create it.
  require_once 'CRM/Financial/DAO/FinancialType.php';
  $dao = new CRM_Financial_DAO_FinancialType();
  $dao->name = 'In Kind';

  if ( ! $dao->find(true) ) {
    $dao->name = 'In Kind';
    $dao->is_deductible = TRUE;
    $dao->is_active = TRUE;
    $dao->save();
  }

  E::createDefaultFinancialAccounts($dao);

  $contrib_type_id = $dao->id;

  // check if the custom group exists.  if not, create it.
  $params = array(
    'title' => 'In Kind donation fields',
    'version' => 3,
  );

  require_once 'api/api.php';
  $result = civicrm_api( 'custom_group', 'get', $params );

  if ( $result['count'] == 0 ) {
    $group = array(
      'title' => 'In Kind donation fields',
      'extends' => array( 'Contribution' ),
      'extends_entity_column_value' => array( $contrib_type_id ),
      'collapse_display' => 0,
      'style' => 'Inline',
      'is_active' => 1,
      'version' => 3
    );
    $result = civicrm_api('custom_group', 'create', $group);
  }
  foreach ( $result['values'] as $id => $detail ) {
    $custom_group_id = $id;
  }

  // check if the custom fields exist.  if not, create them.
  foreach ( array( 'Description of property', 'Appraised by', 'Address of Appraiser', 'Original cost' ) as $field ) {
    if (!_cdntaxreceipts_custom_field_exists($custom_group_id, $field)) {
      $field_params = array(
        'custom_group_id' => $custom_group_id,
        'label' => $field,
        'name' => CRM_Utils_String::munge($field, '_', 64),
        'data_type' => 'String',
        'html_type' => 'Text',
        'is_required' => 1,
        'is_active' => 1,
        'version' => 3,
      );
      if ( $field == 'Original cost' ) {
        $field_params['is_required'] = 0;
        $field_params['help_post'] = 'Amount originally paid for the item. This item is needed for a special rule'.
          ' that applies if either something was purchased in the last ten years'.
          ' with the intent of making a donation, or if an item is donated within three years'.
          ' of being purchased. In both cases the amount that can be claimed is the lesser'.
          ' of the market value, and the amount paid for the item. This item is used to show'.
          ' the cost of the item donated (and the creditable amount) if either of those '.
          ' situations apply.';
      }
      civicrm_api('custom_field', 'create', $field_params);
    }
  }
}

/**************************************
 * SECTION: Tax Receipt API
 */

/**
 * issueTaxReceipt()
 * Issues a tax receipt for a single contribution.
 * Detects if the receipt is a duplicate and reacts appropriately.
 */
function cdntaxreceipts_issueTaxReceipt($contribution, &$collectedPdf = NULL, $mode = CDNTAXRECEIPTS_MODE_BACKOFFICE) {
  // This function basically needs to set up a $receipt[] array and pass it to
  // processTaxReceipt() which does the hard work
  $receipt = array();

  // check if a Tax Receipt has already been issued previously for this Contribution
  // user input could be tainted! we want this method to check everything for validity...
  list($issued_on, $receipt_id) = cdntaxreceipts_issued_on($contribution->id);

  // When returning issued_on field:
  //   returns false for non existing $contribution ID
  //   returns 0 for existing but old data
  //   returns timestamp for new data
  $is_duplicate = empty($issued_on) ? 0 : 1;


  if ( $is_duplicate ) {
    // if this was issued as part of an annual receipt, then jump over to the Annual method
    $receipt = cdntaxreceipts_load_receipt($receipt_id);
    if ( $receipt['issue_type'] == 'annual' ) {
      $contactId = $receipt['contact_id'];
      $year = substr($receipt['contributions'][0]['receive_date'], 0, 4);
      return cdntaxreceipts_issueAnnualTaxReceipt($contactId, $year, $collectedPdf, $mode);
    }
    else if ( $receipt['issue_type'] == 'aggregate' ) {
      $contactId = $receipt['contact_id'];
      $year = substr($receipt['contributions'][0]['receive_date'], 0, 4);
      list($method, $email) = cdntaxreceipts_sendMethodForContact($contactId);
      return cdntaxreceipts_issueAggregateTaxReceipt($contactId, $year, $receipt['contributions'],
        $method, $collectedPdf, $mode);
    }
    // be sure I'm not changing the receipt number
    $receipt_no = $receipt['receipt_no'];
  }
  else { // generate a receipt number
    $receipt_no = Civi::settings()->get('receipt_prefix') . str_pad($contribution->id, 8, 0, STR_PAD_LEFT);
  }

  // determine the send method
  list($method, $email) = cdntaxreceipts_sendMethodForContact($contribution->contact_id);

  // Cancelled receipts are not sent via email
  if ($is_duplicate && $receipt['receipt_status'] == 'cancelled' && $method != 'data') {
    $method = 'print';
  }

  // "Attach" receipts are email (we save the file & attach to an email)
  if ((int)$mode == CDNTAXRECEIPTS_MODE_WORKFLOW) {
    $method = 'email';
  }

  // process In-Kind variables if this is a receipt for an in-kind contribution

  $contributiontype =  _cdntaxreceipts_get_type_for_contribution($contribution);

  $inkind_values = array();
  // check if this is an 'In-kind" contribution.
  $fund_name_check = preg_replace("/[^a-zA-Z0-9]+/", "", $contributiontype->name);
  if ( stripos($fund_name_check,"inkind") !== false) {
    // in this case get the custom field values:
    require_once 'CRM/Core/BAO/CustomField.php';
    $groupTitle = 'In Kind donation fields';
    $fieldLabel_description = 'Description of property';
    $customFieldID_description = CRM_Core_BAO_CustomField::getCustomFieldID( $fieldLabel_description, $groupTitle );
    $fieldLabel_appraisedby = 'Appraised by';
    $customFieldID_appraisedby = CRM_Core_BAO_CustomField::getCustomFieldID( $fieldLabel_appraisedby, $groupTitle );
    $fieldLabel_appraiseraddress = 'Address of Appraiser';
    $customFieldID_appraiseraddress = CRM_Core_BAO_CustomField::getCustomFieldID( $fieldLabel_appraiseraddress, $groupTitle );
    $fieldLabel_cost = 'Original cost';
    $customFieldID_cost = CRM_Core_BAO_CustomField::getCustomFieldID( $fieldLabel_cost, $groupTitle );

    require_once 'CRM/Core/BAO/CustomValueTable.php';

    $custom_id = 'custom_' . $customFieldID_description;
    $params = array('entityID' => $contribution->id, $custom_id => 1);
    $values = CRM_Core_BAO_CustomValueTable::getValues($params);
    $inkind_values[] = $values[$custom_id];

    $custom_id = 'custom_' . $customFieldID_appraisedby;
    $params = array('entityID' => $contribution->id, $custom_id => 1);
    $values = CRM_Core_BAO_CustomValueTable::getValues($params);
    $inkind_values[] = $values[$custom_id];

    $custom_id = 'custom_' . $customFieldID_appraiseraddress;
    $params = array('entityID' => $contribution->id, $custom_id => 1);
    $values = CRM_Core_BAO_CustomValueTable::getValues($params);
    $inkind_values[] = $values[$custom_id];

    $custom_id = 'custom_' . $customFieldID_cost;
    $params = array('entityID' => $contribution->id, $custom_id => 1);
    $values = CRM_Core_BAO_CustomValueTable::getValues($params);
    $inkind_values[] = $values[$custom_id];
  }

  // Get description advantage field
  cdntaxreceipts_advantage($contribution->id, NULL, $advantage, TRUE);
  $receipt = array(
    'receipt_no' => $receipt_no,
    'issued_on' => CRM_Cdntaxreceipts_Utils_Time::time(),
    'location_issued' => _cdntaxreceipts_get_location(),
    'contact_id' => $contribution->contact_id,
    'receipt_amount' => cdntaxreceipts_eligibleAmount($contribution->id),
    'contribution_amount' => $contribution->total_amount,
    'is_duplicate' => $is_duplicate,
    'issue_type' => 'single',
    'issue_method' => $method,
    'receive_date' => $contribution->receive_date,
    'inkind_values' => $inkind_values,
    'receipt_status' => ($is_duplicate && isset($receipt)) ? $receipt['receipt_status'] : 'issued',
    'email_tracking_id' => md5(uniqid(rand(), TRUE)),
    'source' => _cdntaxreceipts_get_contribution_source($contribution),
    'advantage_description' => CRM_Utils_Array::value('advantage_description', $advantage, NULL),
    'advantage_amount' => $contribution->non_deductible_amount,
    'thankyou_html' => $contribution->thankyou_html,
  );

  $receipt['contributions'] = array(
    array(
      'contribution_id' => $contribution->id,
      'contribution_amount' => $contribution->total_amount,
      'receipt_amount' => $receipt['receipt_amount'],
      'receive_date' => $contribution->receive_date,
      'advantage_description' => CRM_Utils_Array::value('advantage_description', $advantage, NULL),
      'advantage_amount' => $contribution->non_deductible_amount,
    )
  );

  return cdntaxreceipts_processTaxReceipt($receipt, $collectedPdf, $mode);
}

function cdntaxreceipts_eligibleForReceipt( $contributionId ) {

  // This function may be called multiple times throughout a request lifecycle, for the
  // same contribution. This can be resource intensive, since it must load the Contribution
  // object each time and will invoke any custom hooks, which may themselves hit the DB. This
  // adds up quickly, particularly during bulk runs. The basic eligibility of a
  // contribution will not change through the request lifecycle, so we can cache those we
  // have already computed.
  static $cache = array();
  if ( array_key_exists($contributionId, $cache) ) {
    return $cache[$contributionId];
  }

  require_once('CRM/Contribute/DAO/Contribution.php');
  $contribution =  new CRM_Contribute_DAO_Contribution();
  $contribution->id = $contributionId;

  if ( ! $contribution->find( TRUE ) ) {
    CRM_Core_Error::fatal( "CDNTaxReceipts: Could not retrieve details for this contribution" );
  }

  // 1. check that contribution amount is deductible
  $deductibleAmount = cdntaxreceipts_eligibleAmount($contributionId);;

  // 2. we used to check here the financial type -- to see if it is deductible.
  // Since the introduction of split transactions (4.5 is the cleanest starting point, though they existed prior),
  // we no longer check the financial type of the contribution, and check instead the tax eligibility of each
  // line item. This is done in the eligibleAmount() function.

  // 3. check if the Contribution Status is Completed ( i.e. = 1 )
  $contributionStatus = $contribution->contribution_status_id;
  if ( !isset($contributionStatus) ) {
    CRM_Core_Error::fatal( "CDNTaxReceipts: Could not find status of this Contribution." );
  }

  // invoke hook_cdntaxreceipts_eligible:
  //   module implementations should return an array containing a single item,
  //   either TRUE or FALSE. assumes TRUE until we receive a FALSE. one FALSE
  //   disqualifies the contribution for a tax receipt.
  $results = CRM_Utils_Hook::singleton()->invoke(['contribution'], $contribution, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, 'cdntaxreceipts_eligible');
  $hookOk = TRUE;

  if (is_array($results)) {
    foreach ( $results as $result ) {
      if ( $result == FALSE ) {
        $hookOk = FALSE;
      }
    }
  }

  // CRM-738: Make receipt accessible for 'refunded' & 'cancelled' Contributions as well
  // Removing the condition $contributionStatus == '1' from below
  if ( $contribution->is_test == 0 && $deductibleAmount > 0 && $hookOk ) {
    $cache[$contributionId] = TRUE;
  }
  else {
    $cache[$contributionId] = FALSE;
  }

  return $cache[$contributionId];

}

function cdntaxreceipts_eligibleAmount( $contributionId ) {

  // This function may be called multiple times throughout a request lifecycle, for the
  // same contribution. This can be resource intensive, since it must load the Contribution
  // object each time and will invoke any custom hooks, which may themselves hit the DB. This
  // adds up quickly, particularly during bulk runs. The basic eligibility amount of a
  // contribution will not change through the request lifecycle, so we can cache those we
  // have already computed.
  if (!isset(\Civi::$statics[__FUNCTION__]['cache'])) {
    \Civi::$statics[__FUNCTION__]['cache'] = array();
  }
  if (array_key_exists($contributionId, \Civi::$statics[__FUNCTION__]['cache'])) {
    return \Civi::$statics[__FUNCTION__]['cache'][$contributionId];
  }

  require_once('CRM/Contribute/DAO/Contribution.php');
  $contribution =  new CRM_Contribute_DAO_Contribution();
  $contribution->id = $contributionId;

  if ( ! $contribution->find( TRUE ) ) {
    CRM_Core_Error::fatal( "CDNTaxReceipts: Could not retrieve details for this contribution" );
  }

  // 1. calculate deductible amount
  $deductibleAmount = 0;

  // compute by line item in 4.5+ if there are line items
  // (if the contribution was done pre-upgrade, it might not have line items)
  $line_item = new CRM_Price_DAO_LineItem();
  $line_item->contribution_id = $contributionId;
  $count = $line_item->find();

  if ($count > 0 && $contribution->total_amount > 0) {
    while ( $line_item->fetch() ) {
      $financialType =  _cdntaxreceipts_get_type_for_line_item($line_item);
      if ( $financialType->is_deductible ) {
        $deductibleAmount += $line_item->line_total;
      }
    }
  }
  else {
    // compute for overall contribution
    $financialType =  _cdntaxreceipts_get_type_for_contribution($contribution);
    if ( $financialType->is_deductible ) {
      $deductibleAmount = $contribution->total_amount;
    }
  }

  // account for manually-entered non_deductible_amount
  $deductibleAmount = $deductibleAmount - $contribution->non_deductible_amount;
  if ( $deductibleAmount < 0 ) {
    $deductibleAmount = 0;
  }

  // 2. allow modules to alter the amount. lowest amount wins.
  $results = CRM_Utils_Hook::singleton()->invoke(['contribution'], $contribution, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, 'cdntaxreceipts_eligibleAmount');

  if (is_array($results)) {
    foreach ( $results as $result ) {
      if ( $result < $deductibleAmount ) {
        $deductibleAmount = $result;
      }
    }
  }

  \Civi::$statics[__FUNCTION__]['cache'][$contributionId] = $deductibleAmount;
  return $deductibleAmount;
}

/**
 * issueAnnualTaxReceipt()
 * Issues an annual tax receipt for a given year.
 * Detects if the receipt is a duplicate and reacts appropriately.
 */
function cdntaxreceipts_issueAnnualTaxReceipt( $contactId, $year, &$collectedPdf = NULL, $mode = CDNTAXRECEIPTS_MODE_BACKOFFICE, $thankyou_html = NULL ) {

  // determine the send method
  list( $method, $email ) = cdntaxreceipts_sendMethodForContact($contactId);

  // query for duplicate receipt
  list( $issued_on, $receipt_id ) = cdntaxreceipts_annual_issued_on($contactId, $year);

  // When returning issued_on field:
  //   returns false for non existing $contribution ID
  //   returns 0 for existing but old data
  //   returns timestamp for new data
  $is_duplicate = empty($issued_on) ? 0 : 1;

  if ( $is_duplicate ) {
    // issue an identical receipt
    $receipt = cdntaxreceipts_load_receipt($receipt_id);
    // Cancelled receipts are not sent via email
    if ($receipt['receipt_status'] == 'cancelled' && $method != 'data') {
      $method = 'print';
    }
    $receipt['issued_on'] = CRM_Cdntaxreceipts_Utils_Time::time();
    $receipt['location_issued'] = _cdntaxreceipts_get_location();
    $receipt['is_duplicate'] = 1;
    $receipt['receive_date'] = $year;
    $receipt['issue_method'] = $method;
    $receipt['thankyou_html'] = $thankyou_html;
  }
  else {

    $contributions = cdntaxreceipts_contributions_not_receipted($contactId, $year);

    if ( count($contributions) == 0 ) {
      return array(FALSE, $method, NULL);
    }

    // calculate total amount
    $receiptContributions = array();
    $totalReceipt = 0;
    foreach ( $contributions as $c ) {
      $eligibleAmount = cdntaxreceipts_eligibleAmount($c['contribution_id']);
      $receiptContributions[] = array(
        'contribution_id' => $c['contribution_id'],
        'contribution_amount' => $c['total_amount'],
        'receipt_amount' => $eligibleAmount,
        'receive_date' => $c['receive_date'],
      );
      $totalReceipt += $eligibleAmount;
    }

    // generate a receipt number
    $receiptNo = Civi::settings()->get('receipt_prefix') . str_pad($receiptContributions[0]['contribution_id'], 8, 0, STR_PAD_LEFT);

    $receipt = array(
      'receipt_no' => $receiptNo,
      'issued_on' => CRM_Cdntaxreceipts_Utils_Time::time(),
      'location_issued' => _cdntaxreceipts_get_location(),
      'contact_id' => $contactId,
      'receipt_amount' => $totalReceipt,
      'is_duplicate' => 0,
      'issue_type' => 'annual',
      'issue_method' => $method,
      'receive_date' => $year,
      'receipt_status' => 'issued',
      'contributions' => $receiptContributions,
      'email_tracking_id' => md5(uniqid(rand(), TRUE)),
      'thankyou_html' => $thankyou_html,
    );

  }

  return cdntaxreceipts_processTaxReceipt($receipt, $collectedPdf, $mode);
}

/**
 * cdntaxreceipts_issueAggregateTaxReceipt()
 * Issues an grouped tax receipt for a given contact, year and list of selected contributions.
 * Currently does not issue duplicates. Pass only original contribution ids.
 *
 * @param $contactId
 * @param $year
 * @param $contributions
 * @param $method
 * @param null $collectedPdf
 * @param bool $previewMode
 * @return array
 */
function cdntaxreceipts_issueAggregateTaxReceipt($contactId, $year, $contributions, $method, &$collectedPdf = NULL,
                                                 $mode = CDNTAXRECEIPTS_MODE_BACKOFFICE, $thankyou_html = NULL) {
  if ( count($contributions) == 0 ) {
    return array(FALSE, $method, NULL);
  }

  /** Two different formats come into this function in the $contributions array
   * 1. When issuing for the first time:
   * array ($contribution_id => array(
   *   'contribution_id' => $id,
   *   'contact_id' => $dao->contact_id,
   *   'total_amount' => $dao->total_amount,
   *   'non_deductible_amount' => $dao->non_deductible_amount,
   *   'receive_date' => $dao->receive_date,
   *   'receive_year' => $dao->receive_year,
   *   'eligible' => $eligible,
   *   'receipt_id' => $dao->receipt_id,
   * ),);
   * 2. When re-issuing:
   *
   *  $index (not contrib id) => array(
   *    'contribution_id'
   *    'contribution_amount'
   *    'receipt_amount'
   *    'receive_date'
   * );
   **/

  $contrib_copy = $contributions;
  $check_contribution = array_shift($contrib_copy);
  list($issued_on, $receipt_id) = cdntaxreceipts_issued_on($check_contribution['contribution_id']);

  if (isset($check_contribution['receipt_id']) && $check_contribution['receipt_id'] != 0) {
    $is_duplicate = TRUE;
  }
  else {
    $is_duplicate = empty($issued_on) ? 0 : 1;
  }

  if ( $is_duplicate ) {
    $receipt = cdntaxreceipts_load_receipt($receipt_id);
    //CRM-1060 Added "In-Kind Donation" details for re-issued aggregated tax receipts
    // @todo refactor so that we get inkind/advantage from initial query in cdntaxreceipts_load_receipt
    $inkind_aggregate = FALSE;
    $total_advantage_amount = 0;
    foreach ( $receipt['contributions'] as $key=>$contribution ) {
      $contribution_id = array($contribution['contribution_id']);
      $inkind_details = cdntaxreceipts_contributions_get_status_inkind($contribution_id);
      if($inkind_details){
        $receipt['contributions'][$key]['inkind']=$inkind_details[$contribution['contribution_id']]['inkind'];
        $receipt['contributions'][$key]['inkind_values']=$inkind_details[$contribution['contribution_id']]['inkind_values'] ?? FALSE;
        $receipt['contributions'][$key]['advantage_amount']=$inkind_details[$contribution['contribution_id']]['non_deductible_amount'];
        $receipt['contributions'][$key]['advantage_description']=$inkind_details[$contribution['contribution_id']]['advantage_description'];
        if($inkind_details[$contribution['contribution_id']]['inkind']){
          $inkind_aggregate = TRUE;
        }
        if($inkind_details[$contribution['contribution_id']]['non_deductible_amount']) {
          $total_advantage_amount += $inkind_details[$contribution['contribution_id']]['non_deductible_amount'];
        }
      }
    }
    // Cancelled receipts are not sent via email
    if ($receipt['receipt_status'] == 'cancelled' && $method != 'data') {
      $method = 'print';
    }
    $receipt['issued_on'] = CRM_Cdntaxreceipts_Utils_Time::time();
    $receipt['location_issued'] = _cdntaxreceipts_get_location();
    $receipt['is_duplicate'] = 1;
    $receipt['issue_method'] = $method;
    $receipt['receive_date'] = $year;
    $receipt['thankyou_html'] = $thankyou_html;
    $receipt['inkind_aggregate'] = $inkind_aggregate;
    $receipt['total_advantage_amount'] = $total_advantage_amount;
  }
  else {
    // calculate total amount
    $receiptContributions = array();
    $totalReceipt = 0;
    $inkind_aggregate = FALSE;
    $total_advantage_amount = 0;
    foreach ( $contributions as $c ) {
      $eligibleAmount =  cdntaxreceipts_eligibleAmount($c['contribution_id']);
      $receiptContributions[] = array(
        'contribution_id' => $c['contribution_id'],
        'contribution_amount' => $c['total_amount'],
        'receipt_amount' => $eligibleAmount,
        'receive_date' => $c['receive_date'],
        'inkind' => $c['inkind'],
        'inkind_values' => $c['inkind_values'] ?? FALSE,
        'advantage_amount' => $c['non_deductible_amount'],
        'advantage_description' => $c['advantage_description'],
      );
      if($c['inkind']) {
        $inkind_aggregate = TRUE;
      }
      $totalReceipt += $eligibleAmount;
      if($c['non_deductible_amount']) {
        $total_advantage_amount += $c['non_deductible_amount'];
      }
    }

    // generate a receipt number
    $receiptNo = Civi::settings()->get('receipt_prefix') . str_pad($receiptContributions[0]['contribution_id'], 8, 0, STR_PAD_LEFT);

    $receipt = array(
      'receipt_no' => $receiptNo,
      'issued_on' => CRM_Cdntaxreceipts_Utils_Time::time(),
      'location_issued' => _cdntaxreceipts_get_location(),
      'contact_id' => $contactId,
      'receipt_amount' => $totalReceipt,
      'is_duplicate' => 0,
      'issue_type' => 'aggregate',
      'issue_method' => $method,
      'receive_date' => $year,
      'receipt_status' => 'issued',
      'contributions' => $receiptContributions,
      'email_tracking_id' => md5(uniqid(rand(), TRUE)),
      'thankyou_html' => $thankyou_html,
      'inkind_aggregate' => $inkind_aggregate,
      'total_advantage_amount' => $total_advantage_amount,
    );
  }

  return cdntaxreceipts_processTaxReceipt($receipt, $collectedPdf, $mode);
}


/* determines the send method for the specified contact
 * returns array, [0] = 'email' or 'print', [1] = email address or NULL
 */
function cdntaxreceipts_sendMethodForContact( $contactId ) {

  $delivery_method = Civi::settings()->get('delivery_method') ?? CDNTAX_DELIVERY_PRINT_ONLY;
  if ($delivery_method == CDNTAX_DELIVERY_DATA_ONLY) {
    return array('data', NULL);
  }
  else if ($delivery_method == CDNTAX_DELIVERY_PRINT_ONLY) {
    return array('print', NULL);
  }

  // if we have CDNTAX_DELIVERY_PRINT_OR_EMAIL, assume print unless we can find valid email information
  // or a hook overrides us.
  $method = array('print', NULL);

  require_once 'CRM/Contact/BAO/Contact.php';
  list($displayname, $email, $doNotEmail, $onHold) = CRM_Contact_BAO_Contact::getContactDetails($contactId);

  if ( isset($email) ) {
    if ( ! $doNotEmail && ! $onHold ) {
      $method = array('email', $email);
    }
  }

  // invoke hook_cdntaxreceipts_method:
  //   module implementations should return an array containing a single item,
  //   either 'email' or 'print'. if we receive one or more 'print', the contact
  //   is disqualified from receiving email receipts.
  $results = CRM_Utils_Hook::singleton()->invoke(['contactId'], $contactId, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, 'cdntaxreceipts_method');

  if (is_array($results)) {
    foreach ( $results as $result ) {
      if ( $result == 'print' ) {
        $method = array('print', NULL);
      }
    }
  }

  return $method;

}

function cdntaxreceipts_sendMethodForContribution( $contributionId ) {

  $contribution =  new CRM_Contribute_DAO_Contribution();
  $contribution->id = $contributionId;

  if ( ! $contribution->find( TRUE ) ) {
    CRM_Core_Error::fatal( "CDNTaxReceipts: Could not retrieve details for this contribution" );
  }

  return cdntaxreceipts_sendMethodForContact( $contribution->contact_id );
}

function cdntaxreceipts_openCollectedPDF() {

  static $pdf;

  if ( ! isset($pdf) ) {
    //define ('K_PATH_IMAGES', '');

    $pdf = new PDF(PDF_PAGE_ORIENTATION, PDF_UNIT, 'LETTER', TRUE, 'UTF-8', FALSE);
    //CRM-1640: Update PDF security and make it non-editable
    $pdf->SetProtection(array('modify', 'copy', 'annot-forms', 'fill-forms', 'assemble', 'print-high'), '', null, 1, null);
    $pdf->Open();

    $pdf->SetAuthor(Civi::settings()->get('org_name'));

    $mymargin_left = 12;
    $mymargin_top = 6;
    $mymargin_right = 12;
    $pdf->SetMargins($mymargin_left, $mymargin_top, $mymargin_right);

    $pdf->setJPEGQuality('100');

    $pdf->SetAutoPageBreak(true, $margin=0);
  }

  return $pdf;

}

function cdntaxreceipts_sendCollectedPDF(&$pdf, $filename) {
  if ( $pdf->getNumPages() > 0 ) {
    if (defined('CIVICRM_TEST')) {
      // Put it in uploadDir the same as the other places we put a file, then
      // the tests can pick it up and do whatever with it. It would be better
      // if there was a way to pass the full $filename to the tests or have
      // the tests provide it, but it means refactoring a bit.
      $pdf->Output(CRM_Core_Config::singleton()->uploadDir . 'test.pdf', 'F');
    }
    else {
      $pdf->Output($filename, 'D');
    }
    CRM_Utils_System::civiExit();
  }
  else {
    $pdf->Close();
  }
}

/**
 * return a full Financial Type object for corresponding contribution
 * @param $contribution
 * @return CRM_Financial_DAO_FinancialType
 */
function _cdntaxreceipts_get_type_for_contribution($contribution) {
  require_once 'CRM/Financial/DAO/FinancialType.php';
  $contributionType = new CRM_Financial_DAO_FinancialType();
  $contributionType->id = $contribution->financial_type_id;
  if ( ! $contributionType->find( TRUE ) ) {
    CRM_Core_Error::fatal( "CDNTaxReceipts: Could not find corresponding contribution type." );
  }
  return $contributionType;
}

function _cdntaxreceipts_get_type_for_line_item($line_item) {
  require_once 'CRM/Financial/DAO/FinancialType.php';
  $financialType = new CRM_Financial_DAO_FinancialType();
  $financialType->id = $line_item->financial_type_id;
  if ( ! $financialType->find( TRUE ) ) {
    CRM_Core_Error::fatal( "CDNTaxReceipts: Could not find corresponding financial type." );
  }
  return $financialType;
}

/**
 * Get the correct contact address. Get the billing address followed by the is_primary as a fallback.
 * @param $contact_id
 * @return array|mixed|null address : Contact address fixed with named country and state_province
 */
function cdntaxreceipts_getAddress($contact_id) {

  $address = NULL;
  // get Address information via contact
  $params = array(
    'version' => 3,
    'contact_id' => $contact_id,
    'is_billing' => 1,
  );

  $address_results = civicrm_api('Address', 'get', $params);

  if ( $address_results['is_error'] == 0) {
    $address = array_shift($address_results['values']);
  }

  if (!isset($address)) {
    $params = array(
      'version' => 3,
      'contact_id' => $contact_id,
      'is_primary' => 1,
    );
    $address_results = civicrm_api('Address', 'get', $params);

    if ( $address_results['is_error'] == 0) {
      $address = array_shift($address_results['values']);
    }

  }
  $address = isset($address) ? $address : array();

  // get actual names for province and country

  // add actual names for province and country instead of just having ids
  if (isset($address['state_province_id'])) {
    $address['state_province'] = CRM_Core_DAO::getFieldValue(
      'CRM_Core_DAO_StateProvince',
      $address['state_province_id'],
      'abbreviation'
    );
  }
  if (isset($address['country_id'])) {
    $address['country'] = CRM_Core_DAO::getFieldValue(
      'CRM_Core_DAO_Country',
      $address['country_id']
    );
  }

  return $address;
}

/**
 * Returns a setting of CiviCRM.
 *
 * @param string $key the setting to retrieve.
 * @return string the value of the $key setting.
 */
function cdntaxreceipts_getCiviSetting($key) {
  $result = civicrm_api3('Setting', 'get', array(
    'sequential' => 1,
    'return' => array($key),
  ));
  $settings = CRM_Utils_Array::first($result['values']);
  return isset($settings[$key]) ? $settings[$key] : NULL;
}

/**
 * Sets the value of a CiviCRM setting.
 *
 * @param string $key the setting to set.
 * @paramm string $value the value for the setting.
 */
function cdntaxreceipts_setCiviSetting($key, $value) {
  civicrm_api3('Setting', 'create', array(
    'sequential' => 1,
    $key => $value,
  ));
}

/**
 * @param $issue_type
 * @return string display formatted Issue type
 */
function _cdntaxreceipts_get_display_type($issue_type) {
  switch ($issue_type) {
    case 'aggregate' :
      return 'Aggregate';
    case 'annual' :
      return 'Annual';
    case 'single' :
      return 'Single';
    default:
      return '';
  }
}


/**
 * Change the language for generating the receipt in contact preferred language.
 *
 * @language string $language preferred_language (i.e. en_US or fr_CA)
 */
function _cdntaxreceipts_setReceiptanguage($language) {

  $cdnLanguages = array('en', 'fr');
  $short = CRM_Core_I18n_PseudoConstant::shortForLong($language);

  // the preferred language for the user is neither english nor french
  if (!in_array($short, $cdnLanguages)) {
    $default = civicrm_api3('setting', 'getvalue', array(
      'name' => 'lcMessages',
      'group' => CRM_Core_BAO_Setting::LOCALIZATION_PREFERENCES_NAME,
    ));
    $short = CRM_Core_I18n_PseudoConstant::shortForLong($default);

    // site default is neither english nor french, use default US english
    if (!in_array($short, $cdnLanguages)) {
      $language = 'en_US';
    }
    // use site default
    else {
      $language = $default;
    }
  }

  // now, we have the language, changed it for generating next receipt
  $i18n = CRM_Core_I18n::singleton();
  $i18n->setLocale($language);

}

/**
 * Override core SendTemplateMethod
 * Easier to attach custom email with the PDF
 * @language string $language preferred_language (i.e. en_US or fr_CA)
 */
function ch_sendTemplate($params) {
  $defaults = [
    // option value name of the template
    'valueName' => NULL,
    // ID of the template
    'messageTemplateID' => NULL,
    // contact id if contact tokens are to be replaced
    'contactId' => NULL,
    // additional template params (other than the ones already set in the template singleton)
    'tplParams' => [],
    // the From: header
    'from' => NULL,
    // the recipient’s name
    'toName' => NULL,
    // the recipient’s email - mail is sent only if set
    'toEmail' => NULL,
    // the Cc: header
    'cc' => NULL,
    // the Bcc: header
    'bcc' => NULL,
    // the Reply-To: header
    'replyTo' => NULL,
    // email attachments
    'attachments' => NULL,
    // whether this is a test email (and hence should include the test banner)
    'isTest' => FALSE,
    // filename of optional PDF version to add as attachment (do not include path)
    'PDFFilename' => NULL,
    // Disable Smarty?
    'disableSmarty' => FALSE,
  ];
  $params = array_merge($defaults, $params);

  // Core#644 - handle Email ID passed as "From".
  if (isset($params['from'])) {
    $params['from'] = CRM_Utils_Mail::formatFromAddress($params['from']);
  }

  CRM_Utils_Hook::alterMailParams($params, 'messageTemplate');
  if (!is_int($params['messageTemplateID']) && !is_null($params['messageTemplateID'])) {
    CRM_Core_Error::deprecatedWarning('message template id should be an integer');
    $params['messageTemplateID'] = (int) $params['messageTemplateID'];
  }
  $mailContent = ch_loadTemplate((string) $params['valueName'], $params['isTest'], $params['messageTemplateID'] ?? NULL, $params['groupName'] ?? '');

  // Overwrite subject from form field
  if (!empty($params['subject'])) {
    $mailContent['subject'] = $params['subject'];
  }

  $mailContent = CRM_Core_BAO_MessageTemplate::renderTemplate([
    'messageTemplate' => [
      'msg_text' => $mailContent['text'],
      'msg_html' => $mailContent['html'],
      'msg_subject' => $mailContent['subject'],
    ],
    'contactId' => $params['contactId'] ?? NULL,
    'disableSmarty' => $params['disableSmarty'],
    'tplParams' => $params['tplParams']
  ]);

  // send the template, honouring the target user’s preferences (if any)
  $sent = FALSE;

  // create the params array
  $params['subject'] = $mailContent['subject'];
  $params['text'] = $mailContent['text'];
  if(!$params['thankyou_email']) {
    $params['html'] = $mailContent['html'];
  }

  // Check Whether openTracking token present or not
  // If not, add it in the html template
  if($params['tplParams']['openTracking']) {
    $dom = new DOMDocument;
    $dom->loadHTML($params['html']);

    $openTracking = $dom->getElementById('openTracking');
    if(!$openTracking) {
      // Create a Fragment
      $openTrackingFragment = $dom->createDocumentFragment();

      // Append the HTML
      $imageSource = CRM_Utils_System::baseURL() . "civicrm/cdntaxreceipts/open?r=". $params['tplParams']['receipt']['email_tracking_id'];
      $openTrackingFragment->appendXML('<img src="'.$imageSource.'" alt="" width="1" height="1" id="openTracking" border="0"></img>');
      $dom->documentElement->appendChild($openTrackingFragment);
      $params['html'] = $dom->saveHTML();
    }
  }

  if ($params['toEmail']) {
    $contactParams = [['email', 'LIKE', $params['toEmail'], 0, 1]];
    [$contact] = CRM_Contact_BAO_Query::apiQuery($contactParams);

    $prefs = array_pop($contact);

    if (isset($prefs['preferred_mail_format']) and $prefs['preferred_mail_format'] === 'HTML') {
      $params['text'] = NULL;
    }

    if (isset($prefs['preferred_mail_format']) and $prefs['preferred_mail_format'] === 'Text') {
      $params['html'] = NULL;
    }

    $config = CRM_Core_Config::singleton();
    if (isset($params['isEmailPdf']) && $params['isEmailPdf'] == 1) {
      $pdfHtml = CRM_Contribute_BAO_ContributionPage::addInvoicePdfToEmail($params['contributionId'], $params['contactId']);
      if (empty($params['attachments'])) {
        $params['attachments'] = [];
      }
      $params['attachments'][] = CRM_Utils_Mail::appendPDF('Invoice.pdf', $pdfHtml, $mailContent['format']);
    }
    $pdf_filename = '';
    if ($config->doNotAttachPDFReceipt &&
      $params['PDFFilename'] &&
      $params['html']
    ) {
      if (empty($params['attachments'])) {
        $params['attachments'] = [];
      }
      $params['attachments'][] = CRM_Utils_Mail::appendPDF($params['PDFFilename'], $params['html'], $mailContent['format']);
      if (isset($params['tplParams']['email_comment'])) {
        $params['html'] = $params['tplParams']['email_comment'];
        $params['text'] = strip_tags($params['tplParams']['email_comment']);
      }
    }

    $sent = CRM_Utils_Mail::send($params);

    if ($pdf_filename) {
      unlink($pdf_filename);
    }
  }

  return [$sent, $mailContent['subject'], $mailContent['text'], $mailContent['html']];
}

function ch_loadTemplate(string $workflowName, bool $isTest, int $messageTemplateID = NULL, $groupName = NULL): array {

  $messageTemplate = civicrm_api3('MessageTemplate', 'get', [
    'sequential' => 1,
    'workflow_name' => $workflowName,
  ])['values'];
  if (empty($messageTemplate)) {
    if ($messageTemplateID) {
      throw new CRM_Core_Exception(ts('No such message template: id=%1.', [1 => $messageTemplateID]));
    }
    throw new CRM_Core_Exception(ts('No message template with workflow name %2.', [2 => $workflowName]));
  } else {
    $messageTemplate = $messageTemplate[0];
  }

  $mailContent = [
    'subject' => $messageTemplate['msg_subject'],
    'text' => $messageTemplate['msg_text'],
    'html' => $messageTemplate['msg_html'],
    'format' => $messageTemplate['pdf_format_id'],
    // Workflow name is the field in the message templates table that denotes the
    // workflow the template is used for. This is intended to eventually
    // replace the non-standard option value/group implementation - see
    // https://github.com/civicrm/civicrm-core/pull/17227 and the longer
    // discussion on https://github.com/civicrm/civicrm-core/pull/17180
    'workflow_name' => $workflowName,
    // Note messageTemplateID is the id but when present we also know it was specifically requested.
    'messageTemplateID' => $messageTemplateID,
    // Group name & valueName are deprecated parameters. At some point it will not be passed out.
    // https://github.com/civicrm/civicrm-core/pull/17180
    'groupName' => $groupName,
    'valueName' => $workflowName,
  ];

  CRM_Utils_Hook::alterMailContent($mailContent);

  // add the test banner (if requested)
  if ($isTest) {
    $testText = MessageTemplate::get(FALSE)
      ->setSelect(['msg_subject', 'msg_text', 'msg_html'])
      ->addWhere('workflow_name', '=', 'test_preview')
      ->addWhere('is_default', '=', TRUE)
      ->execute()->first();

    $mailContent['subject'] = $testText['msg_subject'] . $mailContent['subject'];
    $mailContent['text'] = $testText['msg_text'] . $mailContent['text'];
    $mailContent['html'] = preg_replace('/<body(.*)$/im', "<body\\1\n{$testText['msg_html']}", $mailContent['html']);
  }
  return $mailContent;
}


// BACKPORTED from 1.7.1
/**
 * Get file name with path of file/image field.
 *
 * @param string $imageSettingName
 *
 * @return string file path or NULL
 */
function _cdntaxreceipts_get_image_filepath($imageSettingName) {
  $fileName = Civi::settings()->get($imageSettingName);
  if ($fileName) {
    // Backward compatibilty for full path
    if ( strpos($fileName, "/") === 0 )
      return $fileName;

    // @todo https://lab.civicrm.org/extensions/cdntaxreceipts/-/commit/8ebaf88064323b1e20c005084caabd8846f8de25
    $fileNameWithPath = CRM_Core_Config::singleton()->customFileUploadDir . $fileName;
    if (file_exists($fileNameWithPath)) {
      return $fileNameWithPath;
    }
  }
  return NULL;
}

/**
 * Check if a custom field exists given only the `label`, but we want to
 * check `name` first, then fall back to `label`.
 * Being a little bit paranoid but it's not clear if it's possible that a
 * really old install might have had the `name` generated differently than
 * the way core currently does it, since we never used to set the `name`
 * ourselves, so that's why we fall back to `label`.
 *
 * @param int $custom_group_id
 * @param string $field_label
 * @return bool
 */
function _cdntaxreceipts_custom_field_exists($custom_group_id, $field_label) {
  $params = array(
    'custom_group_id' => $custom_group_id,
    'name' => CRM_Utils_String::munge($field_label, '_', 64),
    'label' => $field_label,
    'options' => array('or' => array(array('name', 'label'))),
    'version' => 3,
  );
  $result = civicrm_api('custom_field', 'get', $params);
  return ($result['count'] != 0);
}

/**
 * Get the issuing city, but bounce if not set.
 * @return string
 */
function _cdntaxreceipts_get_location(): string {
  /*$city = \Civi::settings()->get('receipt_location_issued');
  if (empty($city)) {
    CRM_Core_Error::statusBounce(
      ts('Location Issued is not set. Please set it under Administer - CiviContribute - CDN Tax Receipts.', array('domain' => 'org.civicrm.cdntaxreceipts')),
      CRM_Utils_System::url('civicrm/cdntaxreceipts/settings', 'reset=1')
    );
  }
  return $city;*/
  // @todo, use setting field as per extension
  // https://lab.civicrm.org/extensions/cdntaxreceipts/-/commit/6d2de1a3067cee936a92838af2d6a42aafa1df3d
  $address = CRM_Core_BAO_Location::getValues(['contact_id' => CRM_Core_Config::domainID()], TRUE)['address'][1];
  $address['province'] = CRM_Core_PseudoConstant::stateProvince($address['state_province_id']);
  if ($address && $address['state_province_id']) {
    return $address['city'].", ".$address['province'];
  }
  return '';
}

/**
 * On upgrade, it may not always be possible to set a new setting to a sane
 * value, or some similar issue. So on relevant pages we should call this to
 * run some checks and statusBounce if not good to go.
 */
function _cdntaxreceipts_check_requirements(): void {
  // At the moment this is all we do...
  _cdntaxreceipts_get_location();
}

/**
 * Based on configuration, either return the source field, a custom field,
 * or nothing. Fancier customization can be done in
 * hook_cdntaxreceipts_alter_receipt.
 * @param CRM_Core_DAO_Contribution $contribution
 * @return string
 */
function _cdntaxreceipts_get_contribution_source($contribution): string {
  return 'Source: ' . $contribution->source;
}
