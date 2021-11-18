<?php

use CRM_Cdntaxreceipts_ExtensionUtil as E;
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
      $pdf_template_file = Civi::settings()->get('receipt_pdftemplate');
      if (!empty($pdf_template_file)) {

        if (is_null($this->_tplIdx)) {
          $pdf_template_file = Civi::settings()->get('receipt_pdftemplate');
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
function cdntaxreceipts_processTaxReceipt($receipt, &$collectedPdf = NULL, $mode, $method = NULL) {

  // Get contact details
  list($displayname, $email) = CRM_Contact_BAO_Contact::getContactDetails($receipt['contact_id']);

  // add support for preferred language
  $old_locale = CRM_Core_I18n::singleton()->getLocale();
  $preferred_language = CRM_Core_DAO::getFieldValue('CRM_Contact_DAO_Contact', $receipt['contact_id'], 'preferred_language');
  _cdntaxreceipts_setReceiptanguage($preferred_language);

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
    $receipt['receipt_no'] = Civi::settings()->get('receipt_prefix') . str_pad($next_id, 8, 0, STR_PAD_LEFT);
  }
  // allow modules to alter any details
  // invoke hook_cdntaxreceipts_alter_receipt(&$receipt)
  //   modules should accept a reference to the $receipt array and alter it directly if they wish
  //   one useful use case: alter the $receipt['source'] line to include custom text
  //   to alter receipt amount, use hook_cdntaxreceipts_eligibleAmount() instead
  CRM_Utils_Hook::singleton()->invoke(1, $receipt, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, 'cdntaxreceipts_alter_receipt');

  if ($receipt['issue_method'] == 'data') {
    // generate log entry only, no PDF file needed
    $pdf_file = NULL;
    $sent = TRUE;
  }
  else {
    // generate the PDF file
    list($pdf_file, $user_friendly) = cdntaxreceipts_generateFormattedReceipt($receipt, $collectedPdf, $mode);
    if ($mode == CDNTAXRECEIPTS_MODE_PREVIEW) {
      // no email, just PDF
      $sent = TRUE;
    }
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
      list($sent, $subject, $message, $html) = sendTemplate($sendTemplateParams);

      // send a copy (with open tracking) to the donor
      if ($sent && $receipt['issue_method'] == 'email' && $mode != CDNTAXRECEIPTS_MODE_WORKFLOW) {
        $sendTemplateParams['toEmail'] = $contact['email'];
        $sendTemplateParams['tplParams']['openTracking'] = "<img src=\"" . CRM_Utils_System::baseURL() .
          "civicrm/cdntaxreceipts/open?r={$receipt['email_tracking_id']}\" width='1' height='1' alt='' border='0'>";
        list($sent, $subject, $message, $html) = sendTemplate($sendTemplateParams);
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
function cdntaxreceipts_generateFormattedReceipt($receipt, &$collectedPdf = NULL, $mode) {

  require_once 'tcpdf/tcpdf.php';

  $pdf_img_files_path = dirname(__FILE__) . '/img/';

  $address = cdntaxreceipts_getAddress($receipt['contact_id']);

  $address_line_1 = isset($address['street_address']) ? $address['street_address'] : '';
  $parts = array();
  foreach(array('city', 'state_province')  as $addr_part) {
    if (isset($address[$addr_part])) {
      $parts[] = $address[$addr_part];
    }
  }
  $address_line_1b = isset($address['supplemental_address_1']) ? $address['supplemental_address_1'] : '';
  $address_line_2 = implode(', ', $parts);
  if(isset($address['postal_code'])) {
    $address_country = isset($address['country']) ? $address['country'] : '';
    if($address_country) {
      $address_line_3 = $address['postal_code']. ', '.$address_country;
    } else {
      $address_line_3 = $address['postal_code'];
    }
  } else {
    $address_line_3 = isset($address['country']) ? $address['country'] : '';
  }

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
  $pdf_file = $config->uploadDir . 'Receipt-' . $receipt['receipt_no'] . '.pdf';
  $user_friendly = 'Receipt-' . $receipt['receipt_no'] . '.pdf';

  if (!defined('pdf_page_format')) {
    define('PDF_PAGE_FORMAT', 'USLETTER', true); // orig: 'A4'
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
    "issue_type" => $receipt['issue_type'],
    "receipt_number" => $receipt['receipt_no'],
    "displayname" => $displayname,
    "address_line_1" => $address_line_1,
    "address_line_1b" => $address_line_1b,
    "address_line_2" => $address_line_2,
    "address_line_3" => $address_line_3,
    "inkind_values" => isset($receipt['inkind_values']) ? $receipt['inkind_values'] : array(),
    "inkind_aggregate" => isset($receipt['inkind_aggregate']) ? $receipt['inkind_aggregate'] : array(),
    "receipt_contributions" => $receipt['contributions'],
    "receipt_status" => $receipt['receipt_status'],
    "advantage_description" => $receipt['advantage_description'],
    "advantage_amount" => $receipt['advantage_amount'],
    "contribution_amount" => $receipt['contribution_amount'],
    "thankyou_html" => $receipt['thankyou_html']
  );

  $output_files = array($pdf);
  if ( isset($collectedPdf) && ( $receipt['issue_method'] == 'print' || $mode == CDNTAXRECEIPTS_MODE_PREVIEW) ) {
    $output_files[] = $collectedPdf;
  }

  foreach ( $output_files as $f ) {
    if($pdf_variables['issue_type'] == 'aggregate') {
      $f->startPageGroup();
    }
    $f->AddPage();
    _cdntaxreceipts_writePage($f, $pdf_variables, $receipt);
  }

  // close and output the single receipt, but not the collected receipts...
  $pdf->Close();
  $pdf->Output($pdf_file, 'F');

  return array($pdf_file, $user_friendly);
}

function _cdntaxreceipts_writePage(&$f, $pdf_variables, $receipt) {

  //
  // hook_cdntaxreceipts_writeReceipt(&$pdf, $pdf_variables, $receipt)
  //
  // If there's a custom function to write the page, invoke it.
  // Otherwise, provide a default receipt format.
  //
  // Note: if hook_cdntaxreceipts_writeReceipt is implemented in more than one active
  // module/extension, the receipt will get written into the PDF file multiple
  // times. It's one peril of using the hook system here. Implementers will need to catch
  // this when testing their particular installs.
  //

  $pdf_variables['thankyou_note'] = true; 
  $results = CRM_Utils_Hook::singleton()->invoke(3, $f, $pdf_variables, $receipt, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, 'cdntaxreceipts_writeReceipt');
  _cdntaxreceipts_writeReceipt($f, $pdf_variables, $receipt);
  $pdf_variables['thankyou_note'] = false; 
  // if custom rewrite is implemented
  // don't execute _cdntaxreceipts_writeReceipt 
  // as it will overwrite the pdf

  if(isset($pdf_variables) && $pdf_variables['is_custom_rewrite'] == TRUE) {
    if(!$pdf_variables['is_duplicate'] && $pdf_variables["mode"] !== CDNTAXRECEIPTS_MODE_PREVIEW) {
      $pdf_variables['duplicate_offset'] = TRUE;
      CRM_Utils_Hook::singleton()->invoke(3, $f, $pdf_variables, $receipt, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, 'cdntaxreceipts_writeReceipt');
      _cdntaxreceipts_writeReceipt($f, $pdf_variables, $receipt);
    }
    if($pdf_variables['is_duplicate']) {
      $pdf_variables['is_duplicate'] = TRUE;
      $pdf_variables['duplicate_offset'] = TRUE;
      CRM_Utils_Hook::singleton()->invoke(3, $f, $pdf_variables, $receipt, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, 'cdntaxreceipts_writeReceipt');
      _cdntaxreceipts_writeReceipt($f, $pdf_variables, $receipt);
    }   
    return;
  }


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
    _cdntaxreceipts_writeReceipt($f, $pdf_variables, $receipt);
    $pdf_variables["mymargin_top"] = $mymargin_top + 100;
    $pdf_variables["is_duplicate"] = TRUE;
    _cdntaxreceipts_writeReceipt($f, $pdf_variables, $receipt);
    $pdf_variables["mymargin_top"] = $mymargin_top + 100*2;
    $pdf_variables["is_duplicate"] = TRUE;
    _cdntaxreceipts_writeReceipt($f, $pdf_variables, $receipt);
  }
}

function _cdntaxreceipts_writeReceipt(&$pdf, &$pdf_variables, $receipt) {
  //Set PDF Variable to custom for complete rewrite
  $pdf_variables['is_custom_rewrite'] = true;
  if($pdf_variables['thankyou_note']) {
    _receiptThankYouHeader($pdf, $pdf_variables, $receipt);
    return;
  }

  // Extract variables
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
  $advantage_description = $pdf_variables['advantage_description'];
  $advantage_amount = $pdf_variables['advantage_amount'];
  $contribution_amount = $pdf_variables['contribution_amount'];

  // Add Font
  // convert TTF font to TCPDF format
  $imageLocalPath = dirname(__FILE__).'/img/CDNTaxReceipts/';
  $basePath = dirname(__FILE__).'/fonts/Lato/';
  $fontLatoRegular = TCPDF_FONTS::addTTFfont($basePath.'Lato-Semibold.ttf', 'TrueTypeUnicode', '', 32);
  $fontLatoLight = TCPDF_FONTS::addTTFfont($basePath.'Lato-Light.ttf', 'TrueTypeUnicode', '', 32);

  // CH Set Receipt Margins
  $top_header_margin_offset = 10;
  $side_indentation_margin = 55;
  $mymargin_top += $top_header_margin_offset;
  // Line Height
  $line_height = 5;

  // Duplicate Offset
  $duplicate_offset = 0;
  if(isset($pdf_variables['duplicate_offset'])) {
    if($pdf_variables['duplicate_offset'] == TRUE) {
      $duplicate_offset = 130;
    }
  }
  // Non-Single Receipts
  $grid2_mid_offset = 0;
  if($issue_type !== 'single') {
    $contribution_amount = array_sum(array_column($receipt_contributions, 'contribution_amount')); 
    $grid2_mid_offset = 10;
  }
  // Grid Co-Ordinates
  $grid1_y_top = 15 + $duplicate_offset + $top_header_margin_offset;
  $grid2_y_top = 38 + $duplicate_offset + $top_header_margin_offset;
  $grid2_x_mid = 85 + $grid2_mid_offset;
  $grid2_x_right = 120;
  $grid2b_y_top = 55 + $duplicate_offset + $top_header_margin_offset;
  $grid3_y_top = 85 + $duplicate_offset + $top_header_margin_offset;
  $grid4_y_top = 90 + $duplicate_offset + $top_header_margin_offset; 
  $footer_y_top = 110 + $duplicate_offset + $top_header_margin_offset;

  // My Margin Top
  $mymargin_top += $duplicate_offset;

  // Text Color
  $pdf->SetTextColor(35,35,35);

  //Font Sizes 
  $bigFont = 14;
  $smallFont = 11;
  $footerFont = 9;

  // WaterMark center section
  if ($mode == CDNTAXRECEIPTS_MODE_PREVIEW) {
    $pdf->Image($imageLocalPath . 'PREVIEW.png', 0, $mymargin_top + 35, '', 45, 'PNG', '', 'T', false, 300, 'C');
  }
  else if ($receipt_status == 'cancelled') {
    $pdf->Image($imageLocalPath . 'CANCELLED.png', 0, $mymargin_top + 35, '', 0, 'PNG', '', 'T', false, 300, 'C');
  }
  else if ($is_duplicate) {
    // CRM-713: Remove duplicate watermark from the receipts
    // TODO: Clean up this code if the request is never reverted by Product Team
    // $pdf->Image($imageLocalPath . 'DUPLICATE.png', 0, $mymargin_top + 35, '', 45, 'PNG', '', 'T', false, 300, 'C');
  }

  // Grid Top: Heading
  $pdf->SetY($mymargin_top);
  $pdf->SetFont($fontLatoRegular, '', $bigFont);
  $pdf->Write(10, ts('Official Tax Receipt For Income Tax Purposes', array('domain' => 'org.civicrm.cdntaxreceipts')));

  $pdf_template_file = Civi::settings()->get('receipt_pdftemplate');
  if (!empty($pdf_template_file)) {
  }
  else {
    // Grid 1: Right Section
    $receipt_logo = Civi::settings()->get('receipt_logo');
    if ( empty($receipt_logo) ) {
      $receipt_logo = $pdf_img_files_path . 'your-logo.png';
    }
    // Max Height : 30, Max Width : 45
    list($w, $h) = EU::setImageDimension($receipt_logo, 30, 45);
    $pdf->Image($receipt_logo, 10, 10 + $duplicate_offset + $top_header_margin_offset, $w, $h, 'PNG', '', 'T', false, 300, 'R');


    // Grid 1: Left Section
    $grid_1_counter = 1;
    $org_text_limit = 50;
    $org_name = Civi::settings()->get('org_name');
    $registrationTxt = ts('Charitable Registration #: %1', array(1 => Civi::settings()->get('org_charitable_no'), 'domain' => 'org.civicrm.cdntaxreceipts'));

    $pdf->SetFont($fontLatoRegular, '', $smallFont);
    $pdf->SetY($grid1_y_top);
    $org_name_array = EU::getStringLimitArray($org_name, $org_text_limit);
    for($i=0; $i<2; $i++) {
      if (isset($org_name_array[$i])) {
        if(mb_strlen($org_name_array[$i]) > $org_text_limit) {
          $pdf->Write(10, strtoupper(EU::stringLimiter($org_name_array[$i], $org_text_limit, '')), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
        } else {
          $pdf->Write(10, strtoupper($org_name_array[$i]), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
        }
        $pdf->SetY(EU::getReceiptLineHeight($grid1_y_top, $line_height, $grid_1_counter++));
      }
    }
    $pdf->SetFont($fontLatoLight, '', $smallFont);
    $pdf->Write(10, $registrationTxt, '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
    $pdf->SetY(EU::getReceiptLineHeight($grid1_y_top, $line_height, $grid_1_counter++));
    $org_address_array = [Civi::settings()->get('org_address_line1'), Civi::settings()->get('org_address_line2')];
    foreach($org_address_array as $address_lines) {
      if(mb_strlen($address_lines) > $org_text_limit) {
        $address_lines_array = EU::getStringLimitArray($address_lines, $org_text_limit);
        for($i=0; $i<2; $i++) {
          if (isset($address_lines_array[$i])) {
            $pdf->Write(10, $address_lines_array[$i], '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
            $pdf->SetY(EU::getReceiptLineHeight($grid1_y_top, $line_height, $grid_1_counter++));
          }
        }
      } else {
        $pdf->Write(10, $address_lines, '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
        $pdf->SetY(EU::getReceiptLineHeight($grid1_y_top, $line_height, $grid_1_counter++));
      }
    }
  }
  

  // Grid 2A: Left Section
  $pdf->setY(EU::getReceiptLineHeight($grid2_y_top, $line_height, 1.5));
  $pdf->SetFont($fontLatoRegular, '', $smallFont);
  $pdf->Write(10, ts("Receipt #: "));
  $pdf->SetFont($fontLatoLight, '', $smallFont);
  $pdf->Write(10, ts($receipt_number), array('domain' => 'org.civicrm.cdntaxreceipts'));
  $pdf->setY(EU::getReceiptLineHeight($grid2_y_top, $line_height, 2.5));
  $pdf->SetFont($fontLatoRegular, '', $smallFont);
  $pdf->Write(10, ts("Receipt Issued: "));
  $pdf->SetFont($fontLatoLight, '', $smallFont);
  $pdf->Write(10, ts($issued_on), array('domain' => 'org.civicrm.cdntaxreceipts'));
  
  // Get Location of the Charity
  $address = CRM_Core_BAO_Location::getValues(['contact_id' => CRM_Core_Config::domainID()], TRUE)['address'][1];
  $address['province'] = CRM_Core_PseudoConstant::stateProvince($address['state_province_id']);
  if($address && $address['state_province_id']) {
    $pdf->setY(EU::getReceiptLineHeight($grid2_y_top, $line_height, 3.5));
    $pdf->SetFont($fontLatoRegular, '', $smallFont);
    $pdf->Write(10, ts("Location Issued: "));
    $pdf->SetFont($fontLatoLight, '', $smallFont);
    $address_full = $address['city'].", ".$address['province'];
    // Check Address Length
    if(mb_strlen($address_full) < 45) {
      $pdf->Write(10, ts($address_full), array('domain' => 'org.civicrm.cdntaxreceipts'));
    } else {
      $address_array = EU::getStringLimitArray($address_full, 45);
      $index_address = 4.5;
      foreach($address_array as $address_lines) {
        if(mb_strlen($address_lines) > 45) {
          $pdf->Write(10, ts(mb_substr($address_lines, 0, 23)), array('domain' => 'org.civicrm.cdntaxreceipts'));
          $pdf->setY(EU::getReceiptLineHeight($grid2_y_top, $line_height, $index_address++));
          $pdf->Write(10, ts(mb_substr($address_lines, 23, 45)), array('domain' => 'org.civicrm.cdntaxreceipts'));
          $pdf->setY(EU::getReceiptLineHeight($grid2_y_top, $line_height, $index_address++));
        } else {
          $pdf->Write(10, ts($address_lines), array('domain' => 'org.civicrm.cdntaxreceipts'));
          $pdf->setXY($grid2_x_mid + 28.75, EU::getReceiptLineHeight($grid2_y_top, $line_height, $index_address++));
        }

      }
    }
  }

  // Grid 2B: Left Section
  $pdf->setY(EU::getReceiptLineHeight($grid2b_y_top, $line_height, 1.5));
  $pdf->SetFont($fontLatoRegular, '', $smallFont);
  $pdf->Write(10, ts("Donation Received: "));
  $pdf->SetFont($fontLatoLight, '', $smallFont);
  $pdf->Write(10, ts($display_date), array('domain' => 'org.civicrm.cdntaxreceipts'));
  $pdf->setY(EU::getReceiptLineHeight($grid2b_y_top, $line_height, 2.5));
  $pdf->SetFont($fontLatoRegular, '', $smallFont);
  if($issue_type !== 'single') {
    $pdf->Write(10, ts("Total Donation Amount: "));
  } else {
    $pdf->Write(10, ts("Donation Amount: "));
  }
  $pdf->SetFont($fontLatoLight, '', $smallFont);
  $pdf->Write(10, ts('$'.number_format($contribution_amount, 2)), array('domain' => 'org.civicrm.cdntaxreceipts'));
  // Advantage and In-Kind Fields
  $index_advantage = 3.5;
  if ($advantage_amount > 0) {
    $pdf->setY(EU::getReceiptLineHeight($grid2b_y_top, $line_height, $index_advantage));
    $pdf->SetFont($fontLatoRegular, '', $smallFont);
    $pdf->Write(10, ts("Value of Advantage to: "));
    $pdf->SetFont($fontLatoLight, '', $smallFont);
    $pdf->Write(10, ts('$'.number_format($advantage_amount, 2)), array('domain' => 'org.civicrm.cdntaxreceipts'));
    $index_advantage += 1;
  }
  $pdf->setY(EU::getReceiptLineHeight($grid2b_y_top, $line_height, $index_advantage));
  $pdf->SetFont($fontLatoRegular, '', $smallFont);
  $pdf->Write(10, ts("Eligible Amount of Gift: "));
  $pdf->SetFont($fontLatoLight, '', $smallFont);
  $pdf->Write(10, ts('$'.number_format($amount, 2)));

  // Grid 2: Right Section
  $text_limit = 45;
  $pdf->SetXY(0, EU::getReceiptLineHeight($grid2_y_top, $line_height, 2));
  $pdf->SetFont($fontLatoRegular, '', $smallFont);
  $pdf->Cell(0, 0, ts('Donated by:', array('domain' => 'org.civicrm.cdntaxreceipts')), 0, 0, 'R');
  $pdf->Ln($line_height);
  $pdf->SetFont($fontLatoLight, '', $smallFont);
  $donated_by = [$displayname, $address_line_1, $address_line_1b, $address_line_2, $address_line_3];
  foreach($donated_by as $donor_detail) {
    if(trim($donor_detail)) {
      if(mb_strlen($donor_detail) <= $text_limit) {
        $pdf->Cell(0, 0, ts($donor_detail, array('domain' => 'org.civicrm.cdntaxreceipts')), 0, $line_height, 'R');
      } else {
        $donor_detail_array = EU::getStringLimitArray($donor_detail, $text_limit);
        for($i=0; $i < 2; $i++) {
          if(mb_strlen($donor_detail_array[$i]) > $text_limit) {
            if(strpos($donor_detail_array[$i], '-') !== false) {
              $hyphenedString = explode("-", $donor_detail_array[$i]);
              $donor_detail_sub_array = EU::getStringLimitArray(implode(" ", $hyphenedString), $text_limit);
              $donor_detail_sub_array_count = count($donor_detail_sub_array);
              $i_ddsa = 1;
              foreach($donor_detail_sub_array as $ddsa) {
                $i_ddsa_hyphen = ($i_ddsa == $donor_detail_sub_array_count) ? '' : '-';
                $pdf->Cell(0, 0, ts(str_replace(' ','-',$ddsa).$i_ddsa_hyphen, array('domain' => 'org.civicrm.cdntaxreceipts')), 0, $line_height, 'R');  
                $i_ddsa++;
              }
            } else {
              $pdf->Cell(0, 0, ts(mb_substr($donor_detail_array[$i], 0, $text_limit), array('domain' => 'org.civicrm.cdntaxreceipts')), 0, $line_height, 'R');
              $pdf->Cell(0, 0, ts(mb_substr($donor_detail_array[$i], $text_limit), array('domain' => 'org.civicrm.cdntaxreceipts')), 0, $line_height, 'R');
            }
          } else {
            $pdf->Cell(0, 0, ts(mb_substr($donor_detail_array[$i], 0, $text_limit), array('domain' => 'org.civicrm.cdntaxreceipts')), 0, $line_height, 'R');
          }
        }
      }
    }
  }
  

  // Check Background Image or WaterMark
  $x_detailscolumn = 120;
  $y_detailscolumnstart = 22;
  $pdf_template_file = Civi::settings()->get('receipt_pdftemplate');
  if (!empty($pdf_template_file)) {
  }
  else {
    $background_image = Civi::settings()->get('receipt_watermark');
    if ( $background_image ) $pdf->Image(Civi::settings()->get('receipt_watermark'), $mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 6, '', 40);
  }

  // Grid 3: Left In-Kind Section
  $pdf->setY($grid3_y_top);
  $index_in_kind = 0;
  $string_length = 45;
  if (!empty($advantage_description)) {
    $index_in_kind += 1;
    $pdf->SetFont($fontLatoRegular, '', $smallFont);
    $pdf->Write(10, ts("Description of Advantage: "));
    $pdf->SetFont($fontLatoLight, '', $smallFont);
    if(mb_strlen($advantage_description) > $string_length) {
      $advantage_description_array = EU::getStringLimitArray($advantage_description, $string_length);
      $pdf->Write(10, ts(EU::stringLimiter($advantage_description_array[0], $string_length)));
      $pdf->setY(EU::getReceiptLineHeight($grid3_y_top, $line_height, $index_in_kind++));
      $pdf->Write(10, ts(EU::stringLimiter($advantage_description_array[1], $string_length)));
    } else {
      $pdf->Write(10, ts(EU::stringLimiter($advantage_description, $string_length)));
    }
  }
  
  $inkindvalues_label = [
    'Description of Property Received: ',
    'Fair Market Value Appraised by: ',
    'Address of Appraiser: ',
  ];
  
  if (!empty($inkind_values)) {
    $inkind_values[0] = empty($inkind_values[0]) ? 'N/A': $inkind_values[0];
    for($i=0; $i <3; $i++) {
      if(!empty($inkind_values[$i])) {
        $pdf->setY(EU::getReceiptLineHeight($grid3_y_top, $line_height, $index_in_kind++));
        $pdf->SetFont($fontLatoRegular, '', $smallFont);
        $pdf->Write(10, $inkindvalues_label[$i]);
        $pdf->SetFont($fontLatoLight, '', $smallFont);
        if(mb_strlen($inkind_values[$i]) > $string_length && $i == 0) {
          $inkind_values_array = EU::getStringLimitArray($inkind_values[$i], $string_length);
          $pdf->Write(10, ts(EU::stringLimiter($inkind_values_array[0], $string_length)));
          $pdf->setY(EU::getReceiptLineHeight($grid3_y_top, $line_height, $index_in_kind++));
          $pdf->Write(10, ts(EU::stringLimiter($inkind_values_array[1], $string_length)));
        } else {
          $pdf->Write(10, ts(EU::stringLimiter($inkind_values[$i], $string_length)));
        }
      }
    }
  }


  // Grid 4 Signature
  if (!empty($pdf_template_file)) {
  }
  else {
    $pdf->setY($grid4_y_top);
    $pdf->SetFont($fontLatoRegular, '', $smallFont);
    $pdf->Cell(0, 0, ts('Authorized Signature', array('domain' => 'org.civicrm.cdntaxreceipts')), 0, 0, 'R');
    $sig_image = Civi::settings()->get('receipt_signature');
    if (empty($sig_image)) {
      $sig_image = $pdf_img_files_path . 'authorized-signature.png';
    }

    // Max Height : 25, Max Width : 35
    list($w, $h) = EU::setImageDimension($sig_image, 25, 35);
    $pdf->Image($sig_image, 0, EU::getReceiptLineHeight($grid4_y_top, $line_height, 1.25), $w, $h, 'PNG', '', 'T', false, 300, 'R', false, false, 0, false, false, false);
  }

  // Footer Section
  $pdf->SetY($footer_y_top);
  $pdf->SetFont($fontLatoLight, '', $footerFont);
  $pdf->Ln(2*$line_height);
  $pdf->Cell(0, 10, ts('Canada Revenue Agency - canada.ca/charities-giving', array('domain' => 'org.civicrm.cdntaxreceipts')), 0, false, 'C', 0, '', 0, false, 'T', 'M');
  if($pdf_variables['issue_type'] == 'aggregate') {
    $pdf->Cell(0, 10, 'Page '.$pdf->getPageNumGroupAlias().' of '.$pdf->getPageGroupAlias(), 0, false, 'R', 0, '', 0, false, 'T', 'M');
  }
  $lineStyle = array('width' => 0.3, 'cap' => 'butt', 'join' => 'miter', 'dash' => 4, 'phase' => 40);
  if (empty($duplicate_offset)) {
    $pdf->Line($mymargin_left, $footer_y_top + 18, 198, $footer_y_top + 18, $lineStyle);
  }
  if($pdf_variables['issue_type'] == 'aggregate') {
    $pdf->addPage();
    _addItemizedDetails($pdf, $pdf_variables, $receipt);
    return;
  }
}

function _addItemizedDetails(&$pdf, &$pdf_variables, $receipt) {
  //PDF Variables
  $mymargin_left = $pdf_variables["mymargin_left"];
  $mymargin_top = $pdf_variables["mymargin_top"];
  $mode = $pdf_variables["mode"];
  $is_duplicate = $pdf_variables["is_duplicate"];
  $pdf_img_files_path = $pdf_variables["pdf_img_files_path"];
  $receipt_status = $pdf_variables['receipt_status'];

  // Add Font
  // convert TTF font to TCPDF format
  $imageLocalPath = dirname(__FILE__).'/img/CDNTaxReceipts/';
  $basePath = dirname(__FILE__).'/fonts/Lato/';
  $fontLatoRegular = TCPDF_FONTS::addTTFfont($basePath.'Lato-Semibold.ttf', 'TrueTypeUnicode', '', 32);
  $fontLatoLight = TCPDF_FONTS::addTTFfont($basePath.'Lato-Light.ttf', 'TrueTypeUnicode', '', 32);
  $fontLatoBoldItalic = TCPDF_FONTS::addTTFfont($basePath.'Lato-BoldItalic.ttf', 'TrueTypeUnicode', '', 32);

  // CH Set Receipt Margins
  $top_header_margin_offset = 10;
  $side_indentation_margin = 55;
  $mymargin_top += $top_header_margin_offset;
  // Line Height
  $line_height = 5;


  // Grid Co-Ordinates
  $grid1_y_top = $mymargin_top + 30;
  $grid1_x_mid = $mymargin_left + 4;
  $grid2_y_top = $mymargin_top + 40;
  $grid2_x_mid = $mymargin_left + 75;
  $grid2_x_right = 20;
  $grid3_y_top = 75 + $top_header_margin_offset;
  $grid4_y_top = 90 + $top_header_margin_offset;
  $footer_y_top = 240 + $top_header_margin_offset;

  // Text Color
  $pdf->SetTextColor(35,35,35);


  //Font Sizes
  $bigFont = 14;
  $smallFont = 11;
  $smallerFont = 10;
  $footerFont = 9;
  $inkindHeaderFont = 8;

  // WaterMark center section
  if ($mode == CDNTAXRECEIPTS_MODE_PREVIEW) {
    $pdf->Image($imageLocalPath . 'PREVIEW.png', 0, $mymargin_top + 85, '', 45, 'PNG', '', 'T', false, 300, 'C');
  }
  if ($receipt_status == 'cancelled') {
    $pdf->Image($imageLocalPath . 'CANCELLED.png', 0, $mymargin_top + 85, '', 0, 'PNG', '', 'T', false, 300, 'C');
  }

  // Grid 1: Right Section
  $receipt_logo = Civi::settings()->get('receipt_logo');
  if ( empty($receipt_logo) ) {
    $receipt_logo = $pdf_img_files_path . 'your-logo.png';
  }
  // Max Height : 30, Max Width : 45
  list($w, $h) = EU::setImageDimension($receipt_logo, 30, 45);
  $pdf->Image($receipt_logo, 10, 10 + $top_header_margin_offset, $w, $h, 'PNG', '', 'T', false, 300, 'R');


  // In-Kind Section
  $pdf->SetFont($fontLatoRegular, '', $bigFont);
  $pdf->SetXY($mymargin_left, $grid1_y_top);
  $pdf->Write(10, 'Supplemental Information', '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
  $string_length = 30;
  $string_length_offset = 75;
  $contribution_aggregate_counter_left = $contribution_aggregate_counter_right = 1;
  $contribution_values_count = 1;
  $contribution_values_limit = 18;
  foreach($pdf_variables['receipt_contributions'] as $contribution) {
    if($contribution_values_count > $contribution_values_limit) {
      // Add Footer on the Previous page
      $pdf->SetY($footer_y_top);
      $pdf->SetFont($fontLatoLight, '', $footerFont);
      $pdf->Ln(2*$line_height);
      $pdf->Cell(0, 10, 'Page '.$pdf->getPageNumGroupAlias(). ' of '.$pdf->getPageGroupAlias(), 0, false, 'R', 0, '', 0, false, 'T', 'M');
      //Add New Page
      $pdf->addPage();
      $contribution_aggregate_counter_left = $contribution_aggregate_counter_right = 1;
      $contribution_values_count = 1;

      //Add Header in the new page
      // WaterMark center section
      if ($mode == CDNTAXRECEIPTS_MODE_PREVIEW) {
        $pdf->Image($imageLocalPath . 'PREVIEW.png', 0, $mymargin_top + 85, '', 45, 'PNG', '', 'T', false, 300, 'C');
      }
      if ($receipt_status == 'cancelled') {
        $pdf->Image($imageLocalPath . 'CANCELLED.png', 0, $mymargin_top + 85, '', 0, 'PNG', '', 'T', false, 300, 'C');
      }

      // Grid 1: Right Section
      $receipt_logo = Civi::settings()->get('receipt_logo');
      if ( empty($receipt_logo) ) {
        $receipt_logo = $pdf_img_files_path . 'your-logo.png';
      }
      // Max Height : 30, Max Width : 45
      list($w, $h) = EU::setImageDimension($receipt_logo, 30, 45);
      $pdf->Image($receipt_logo, 10, 10 + $top_header_margin_offset, $w, $h, 'PNG', '', 'T', false, 300, 'R');

      // In-Kind Section
      $pdf->SetFont($fontLatoRegular, '', $bigFont);
      $pdf->SetXY($mymargin_left, $grid1_y_top);
      $pdf->Write(10, 'Supplemental Information', '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
    }
    // Left Contribution Details Section
    if($contribution_aggregate_counter_left == 1) {
      $contribution_aggregate_counter_left = $contribution_aggregate_counter_right = 0.25;
    }
    $pdf->SetFont($fontLatoLight, '', $smallFont);
    $pdf->Ln();
    $pdf->SetLineStyle(array('width' => 0.75, 'cap' => 'butt', 'join' => 'miter', 'dash' => 0, 'color' => array(220, 220, 220)));
    $pdf->SetFillColor(220,220,220);
    $pdf->Cell(0, 0, '', 1, 1, 'L', 1, 0);
    $pdf->setY(EU::getReceiptLineHeight($grid2_y_top+6.5, $line_height, $contribution_aggregate_counter_left++));
    $pdf->SetFont($fontLatoRegular, '', $smallFont);
    $pdf->Write(10, ts("Donation Received: "));
    $pdf->SetFont($fontLatoLight, '', $smallFont);
    $pdf->Write(10, ts(date("Y-m-d",strtotime($contribution['receive_date']))), array('domain' => 'org.civicrm.cdntaxreceipts'));
    $contribution_aggregate_counter_left += 1.75;
    $pdf->setY(EU::getReceiptLineHeight($grid2_y_top, $line_height, $contribution_aggregate_counter_left++));
    $pdf->SetFont($fontLatoRegular, '', $smallFont);
    $pdf->Write(10, ts("Donation Amount: "));
    $pdf->SetFont($fontLatoLight, '', $smallFont);
    $pdf->Write(10, ts('$'.number_format($contribution['contribution_amount'], 2)), array('domain' => 'org.civicrm.cdntaxreceipts'));
    $pdf->setY(EU::getReceiptLineHeight($grid2_y_top, $line_height, $contribution_aggregate_counter_left++));
    $pdf->SetFont($fontLatoRegular, '', $smallFont);
    $pdf->Write(10, ts("Eligible Amount of Gift: "));
    $pdf->SetFont($fontLatoLight, '', $smallFont);
    $pdf->Write(10, ts('$'.number_format($contribution['receipt_amount'], 2)), array('domain' => 'org.civicrm.cdntaxreceipts'));

    // Right In-kind section
    $pdf->SetFont($fontLatoLight, '', $smallFont);
    $pdf->setXY($grid2_x_mid, EU::getReceiptLineHeight($grid2_y_top+6.5, $line_height, $contribution_aggregate_counter_right++));
    if($contribution['inkind']) {
      $pdf->SetFont($fontLatoRegular, '', $inkindHeaderFont);
      $pdf->Write(10, ts("In-kind Donation"));
    }
    $contribution_aggregate_counter_right += 1.75;
    if($contribution['advantage_amount'] && $contribution['advantage_amount'] > 0) {
      $pdf->setXY($grid2_x_mid, EU::getReceiptLineHeight($grid2_y_top, $line_height, $contribution_aggregate_counter_right++));
      $pdf->SetFont($fontLatoRegular, '', $smallFont);
      $pdf->Write(10, ts("Value of Advantage to: "));
      $pdf->SetFont($fontLatoLight, '', $smallFont);
      $pdf->Write(10, ts('$'.number_format($contribution['advantage_amount'], 2)), array('domain' => 'org.civicrm.cdntaxreceipts'));
      $contribution_values_count++;
    }
    if(!empty($contribution['advantage_description'])) {
      $pdf->setXY($grid2_x_mid, EU::getReceiptLineHeight($grid2_y_top, $line_height, $contribution_aggregate_counter_right++));
      $pdf->SetFont($fontLatoRegular, '', $smallFont);
      $pdf->Write(10, ts("Description of Advantage: "));
      $pdf->SetFont($fontLatoLight, '', $smallFont);
      if(mb_strlen($contribution['advantage_description']) > $string_length) {
        $advantage_description_array = EU::getStringLimitArrayOffset($contribution['advantage_description'], $string_length, $string_length_offset);
        for($i=0; $i<2; $i++) {
          if($advantage_description_array[$i]){
            $string_length_limit = ($i == 0) ? $string_length : $string_length_offset;
            $pdf->Write(10, ts(EU::stringLimiter($advantage_description_array[$i], $string_length_limit, '')), array('domain' => 'org.civicrm.cdntaxreceipts'));
            if($i == 0) {
              $pdf->setXY($grid2_x_mid, EU::getReceiptLineHeight($grid2_y_top, $line_height, $contribution_aggregate_counter_right++));
            }
          }
        }
        $contribution_values_count++;
      } else {
        $pdf->Write(10, ts($contribution['advantage_description']), array('domain' => 'org.civicrm.cdntaxreceipts'));
      }
      $contribution_values_count++;
    }
    if($contribution['inkind_values'] ) {
      $inkind_ctr = 0;
      foreach($contribution['inkind_values'] as $inkind_values_label => $inkind_values_aggregate) {
        if($inkind_values_label == 'Description of Property Received' 
          && (!empty($contribution['advantage_description']) || !empty($contribution['advantage_amount']))
        ) {
          $contribution_aggregate_counter_right += 0.5;
        }
        if($inkind_values_aggregate) {
          $pdf->setXY($grid2_x_mid, EU::getReceiptLineHeight($grid2_y_top, $line_height, $contribution_aggregate_counter_right++));
          $pdf->SetFont($fontLatoRegular, '', $smallFont);
          $pdf->Write(10, ts($inkind_values_label.": "));
          $pdf->SetFont($fontLatoLight, '', $smallFont);
          if(mb_strlen($inkind_values_aggregate) > $string_length) {
            $inkind_values_aggregate_array = EU::getStringLimitArrayOffset($inkind_values_aggregate, $string_length, $string_length_offset);
            for($i=0; $i<2; $i++) {
              if($inkind_values_aggregate_array[$i]){
                $string_length_limit = ($i == 0) ? $string_length : $string_length_offset;
                $pdf->Write(10, ts(EU::stringLimiter($inkind_values_aggregate_array[$i], $string_length_limit, '')), array('domain' => 'org.civicrm.cdntaxreceipts'));
                if($i == 0) {
                  $pdf->setXY($grid2_x_mid, EU::getReceiptLineHeight($grid2_y_top, $line_height, $contribution_aggregate_counter_right++));
                }
              }
            }
            $contribution_values_count++;
          } else {
            $pdf->Write(10, ts($inkind_values_aggregate), array('domain' => 'org.civicrm.cdntaxreceipts'));
          }
          $contribution_values_count++;
          $inkind_ctr++;
        }
      }
      if($inkind_ctr == 1) {
        $contribution_values_count++;
        $contribution_aggregate_counter_right += 1;
      }
    } else {
      if((empty($contribution['advantage_amount']) || $contribution['advantage_amount'] == 0) && empty($contribution['advantage_description'])) {
        $contribution_values_count += 2;
        $contribution_aggregate_counter_right += 1;
      } else {
        $contribution_aggregate_counter_right -= 1;
      }
      $pdf->setXY($grid2_x_mid, EU::getReceiptLineHeight($grid2_y_top, $line_height, $contribution_aggregate_counter_right++));
    }

    $contribution_aggregate_counter_left = $contribution_aggregate_counter_right;
    $pdf->setY(EU::getReceiptLineHeight($grid2_y_top, $line_height, $contribution_aggregate_counter_left-0.25));
  }

  // Footer Section
  $pdf->SetY($footer_y_top);
  $pdf->SetFont($fontLatoLight, '', $footerFont);
  $pdf->Ln(2*$line_height);
  $pdf->Cell(0, 10, 'Page '.$pdf->getPageNumGroupAlias(). ' of '.$pdf->getPageGroupAlias(), 0, false, 'R', 0, '', 0, false, 'T', 'M');
  return;
}

function _receiptThankYouHeader($pdf, $pdf_variables, $receipt) {
  // Make tokens work on new Receipts
  $result = civicrm_api3('MessageTemplate', 'get', [
    'sequential' => 1,
    'msg_title' => "CDN Tax Receipts - Thank you Note",
  ]); 
  if($result['values'][0]) {
    $message_tpl = $result['values'][0];
    $newString = $message_tpl['msg_html'] ?? NULL;
    $messageToken = CRM_Utils_Token::getTokens($newString);
    $contact =  civicrm_api3('Contact', 'get', [
      'sequential' => 1,
      'id' => $receipt['contact_id'],
    ]);
    $tokenHtml = CRM_Utils_Token::replaceContactTokens($newString, $contact, TRUE, $messageToken);
    $domain = CRM_Core_BAO_Domain::getDomain();
    $tokenHtml = CRM_Utils_Token::replaceDomainTokens($tokenHtml, $domain, TRUE, $messageToken);
  }

  //PDF Variables 
  $mymargin_left = $pdf_variables["mymargin_left"];
  $mymargin_top = $pdf_variables["mymargin_top"];

  // Add Font
  // convert TTF font to TCPDF format
  $imageLocalPath = dirname(__FILE__).'/img/CDNTaxReceipts/';
  $basePath = dirname(__FILE__).'/fonts/Lato/';
  $fontLatoRegular = TCPDF_FONTS::addTTFfont($basePath.'Lato-Semibold.ttf', 'TrueTypeUnicode', '', 32);
  $fontLatoLight = TCPDF_FONTS::addTTFfont($basePath.'Lato-Light.ttf', 'TrueTypeUnicode', '', 32);

  // CH Set Receipt Margins
  $top_header_margin_offset = 10;
  $side_indentation_margin = 55;
  $mymargin_top += $top_header_margin_offset;
  // Line Height
  $line_height = 4;


  // Grid Co-Ordinates
  $grid1_x_mid = $mymargin_left + 4;
  $grid2_y_top = 42 + $top_header_margin_offset;
  $grid2_x_mid = 25;
  $grid2_x_right = 20;
  $grid3_y_top = 75 + $top_header_margin_offset;
  $grid4_y_top = 90 + $top_header_margin_offset; 
  $footer_y_top = 110 + $top_header_margin_offset;


  // Text Color
  $pdf->SetTextColor(35,35,35);

  //Font Sizes 
  $bigFont = 14;
  $smallFont = 11;
  $smallerFont = 10;
  $footerFont = 9;

  // Grid 1: Right Section
  $receipt_logo = Civi::settings()->get('receipt_logo');
  if ( empty($receipt_logo) ) {
    $receipt_logo = $pdf_img_files_path . 'your-logo.png';
  }
  // Max Height : 30, Max Width : 45
  list($w, $h) = EU::setImageDimension($receipt_logo, 30, 45);
  $pdf->Image($receipt_logo, 10, 10 + $top_header_margin_offset, $w, $h, 'PNG', '', 'T', false, 300, 'R');


  // Grid 1: Left Section
  $grid_1_counter = 1;
  $org_text_limit = 32;
  $org_name = Civi::settings()->get('org_name');
  $pdf->SetFont($fontLatoRegular, '', $smallerFont);
  $pdf->SetXY($grid1_x_mid, $mymargin_top);
  $org_name_array = EU::getStringLimitArray($org_name, $org_text_limit);
  for($i=0; $i<2; $i++) {
    if($org_name_array[$i]){
      if(mb_strlen($org_name_array[$i]) > $org_text_limit) {
        $pdf->Write(10, strtoupper(EU::stringLimiter($org_name_array[$i], $org_text_limit, '')), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
      } else {
        $pdf->Write(10, strtoupper($org_name_array[$i]), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
      }
      $pdf->SetXY($grid1_x_mid, EU::getReceiptLineHeight($mymargin_top, $line_height, $grid_1_counter++));
    }
  }
  $pdf->SetFont($fontLatoLight, '', $smallerFont);
  $pdf->Write(10, Civi::settings()->get('org_address_line1'), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
  $pdf->SetXY($grid1_x_mid, EU::getReceiptLineHeight($mymargin_top, $line_height, $grid_1_counter++));
  $pdf->Write(10, Civi::settings()->get('org_address_line2'), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);


  // Grid 2
  $line_index = 1;
  $pdf->setXY($grid2_x_mid, $grid2_y_top);
  // Grid 2: Heading
  $pdf->SetFont($fontLatoLight, '', $smallerFont);
  if($pdf_variables['displayname']) {
    $pdf->Write(10, ts($pdf_variables['displayname'], array('domain' => 'org.civicrm.cdntaxreceipts')));
  }
  if($pdf_variables['address_line_1']) {
    $pdf->setXY($grid2_x_mid, EU::getReceiptLineHeight($grid2_y_top, $line_height, $line_index++));
    $pdf->Write(10, ts($pdf_variables['address_line_1'], array('domain' => 'org.civicrm.cdntaxreceipts')));
  }
  if($pdf_variables['address_line_1b']) {
    $pdf->setXY($grid2_x_mid, EU::getReceiptLineHeight($grid2_y_top, $line_height, $line_index++));
    $pdf->Write(10, ts($pdf_variables['address_line_1b'], array('domain' => 'org.civicrm.cdntaxreceipts')));
  }
  if($pdf_variables['address_line_2']) {
    $pdf->setXY($grid2_x_mid, EU::getReceiptLineHeight($grid2_y_top, $line_height, $line_index++));
    $pdf->Write(10, ts($pdf_variables['address_line_2'], array('domain' => 'org.civicrm.cdntaxreceipts')));
  }
  if($pdf_variables['address_line_3']) {
    $pdf->setXY($grid2_x_mid, EU::getReceiptLineHeight($grid2_y_top, $line_height, $line_index++));
    $pdf->Write(10, ts($pdf_variables['address_line_3'], array('domain' => 'org.civicrm.cdntaxreceipts')));
  }
  

  // Grid 3
  // THank You HTML Message

  $pdf->SetXY(0, $grid3_y_top);
  $pdf->SetFont($fontLatoLight, '', $smallFont);
  if($result['values'][0]) {
    $pdf->writeHTML($tokenHtml, true, false, true, false, '');
  }
  //Footer
  $lineStyle = array('width' => 0.3, 'cap' => 'butt', 'join' => 'miter', 'dash' => 4, 'phase' => 40);
  $pdf->Line($mymargin_left, $footer_y_top + 18, 198, $footer_y_top + 18, $lineStyle);
  
  return;
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
      'extends_entity_column_value' => [$contrib_type_id],
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
    $params = array(
      'custom_group_id' => $custom_group_id,
      'label' => $field,
      'version' => 3,
    );
    $result = civicrm_api( 'custom_field', 'get', $params);

    if ( $result['count'] == 0 ) {
      $field_params = array(
        'custom_group_id' => $custom_group_id,
        'label' => $field,
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
      $result = civicrm_api('custom_field', 'create', $field_params);
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
  if ($mode == CDNTAXRECEIPTS_MODE_WORKFLOW) {
    $method = 'email';
  }

  // process In-Kind variables if this is a receipt for an in-kind contribution

  $contributiontype =  _cdntaxreceipts_get_type_for_contribution($contribution);

  $inkind_values = array();
  // check if this is an 'In-kind" contribution.
  if ( $contributiontype->name == 'In-kind' || $contributiontype->name == "In Kind") {
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
    'issued_on' =>  (int) $_SERVER['REQUEST_TIME'],
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
    'advantage_description' => CRM_Utils_Array::value('advantage_description', $advantage, NULL),
    'advantage_amount' => $contribution->non_deductible_amount,
    'thankyou_html' => $contribution->thankyou_html,
  );

  if ( $contribution->source != '' ) {
    $receipt['source'] = 'Source: ' . $contribution->source;
  }

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
  $results = CRM_Utils_Hook::singleton()->invoke(1, $contribution, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, 'cdntaxreceipts_eligible');
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
  $results = CRM_Utils_Hook::singleton()->invoke(1, $contribution, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, 'cdntaxreceipts_eligibleAmount');

  if (is_array($results)) {
    foreach ( $results as $result ) {
      if ( $result < $deductibleAmount ) {
        $deductibleAmount = $result;
      }
    }
  }

  $cache[$contributionId] = $deductibleAmount;
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
    $receipt['issued_on'] = (int) $_SERVER['REQUEST_TIME'];
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
      'issued_on' =>  (int) $_SERVER['REQUEST_TIME'],
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
    // Cancelled receipts are not sent via email
    if ($receipt['receipt_status'] == 'cancelled' && $method != 'data') {
      $method = 'print';
    }
    $receipt['issued_on'] = (int) $_SERVER['REQUEST_TIME'];
    $receipt['is_duplicate'] = 1;
    $receipt['issue_method'] = $method;
    $receipt['receive_date'] = $year;
    $receipt['thankyou_html'] = $thankyou_html;
  }
  else {
    // calculate total amount
    $receiptContributions = array();
    $totalReceipt = 0;
    $inkind_aggregate = FALSE;
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
    }

    // generate a receipt number
    $receiptNo = Civi::settings()->get('receipt_prefix') . str_pad($receiptContributions[0]['contribution_id'], 8, 0, STR_PAD_LEFT);

    $receipt = array(
      'receipt_no' => $receiptNo,
      'issued_on' =>  (int) $_SERVER['REQUEST_TIME'],
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
    );
  }
  return cdntaxreceipts_processTaxReceipt($receipt, $collectedPdf, $mode);
}


/* determines the send method for the specified contact
 * returns array, [0] = 'email' or 'print', [1] = email address or NULL
 */
function cdntaxreceipts_sendMethodForContact( $contactId ) {

  $delivery_method = Civi::settings()->get('delivery_method', NULL, CDNTAX_DELIVERY_PRINT_ONLY);
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
  $results = CRM_Utils_Hook::singleton()->invoke(1, $contactId, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, 'cdntaxreceipts_method');

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
    require_once 'tcpdf/tcpdf.php';

    $pdf = new PDF(PDF_PAGE_ORIENTATION, PDF_UNIT, 'LETTER', TRUE, 'UTF-8', FALSE);
    $pdf->Open();

    $pdf->SetAuthor(Civi::settings()->get('org_name'));

    $mymargin_left = 12;
    $mymargin_top = 6;
    $mymargin_right = 12;
    $pdf->SetMargins($mymargin_left, $mymargin_top, $mymargin_right);

    $pdf->setJPEGQuality('100');

    $pdf->SetAutoPageBreak('', $margin=0);
  }

  return $pdf;

}

function cdntaxreceipts_sendCollectedPDF(&$pdf, $filename) {
  if ( $pdf->getNumPages() > 0 ) {
    $pdf->Output($filename, 'D');
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
function sendTemplate($params) {
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
  $mailContent = loadTemplate((string) $params['valueName'], $params['isTest'], $params['messageTemplateID'] ?? NULL, $params['groupName'] ?? '');

  // Overwrite subject from form field
  if (!empty($params['subject'])) {
    $mailContent['subject'] = $params['subject'];
  }

  $mailContent = CRM_Core_BAO_MessageTemplate::renderMessageTemplate($mailContent, (bool) $params['disableSmarty'], $params['contactId'] ?? NULL, $params['tplParams']);

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

function loadTemplate(string $workflowName, bool $isTest, int $messageTemplateID = NULL, $groupName = NULL): array {
    
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
