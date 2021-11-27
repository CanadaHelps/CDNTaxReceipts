<?php

/**
 * @group headless
 */
class CRM_Cdntaxreceipts_ReceiptsIssuedReportTest extends CRM_Cdntaxreceipts_Base {

  public function setUp(): void {
    parent::setUp();
    if (!\CRM_Core_BAO_Domain::isDBVersionAtLeast('5.43.alpha1')) {
      $this->markTestIncomplete('Test requires E_NOTICE fix that is only in 5.43+');
    }
    $this->setDeliveryMethod(CDNTAX_DELIVERY_PRINT_ONLY);
  }

  public function tearDown(): void {
    $this->_tablesToTruncate = [
      'civicrm_contact',
    ];
    $this->quickCleanUpFinancialEntities();
    parent::tearDown();
  }

  /**
   * Basic test with defaults
   */
  public function testReport() {
    // create contribution
    $contact_id = $this->individualCreate([], 1);
    $datestr = date('Y-m-d');
    $contribution_id = civicrm_api3('Contribution', 'create', [
      'contact_id' => $contact_id,
      'financial_type_id' => 'Donation',
      'total_amount' => '10',
      'receive_date' => $datestr,
    ])['id'];

    // Need it in DAO format
    $contribution = new CRM_Contribute_DAO_Contribution();
    $contribution->id = $contribution_id;
    $contribution->find(TRUE);
    // issue receipt
    list($result, $method) = cdntaxreceipts_issueTaxReceipt($contribution);
    $this->assertTrue($result);
    $this->assertEquals('print', $method);

    // run report
    $data = civicrm_api3('report_template', 'getrows', ['report_id' => 'cdntaxreceipts/receiptsissued'])['values'];

    // We don't care about this and it was introduced in 5.45 so causes fails
    // on earlier matrices.
    unset($data[0]['class']);

    // I'm still a little confused about how this passes even when using assertSame.
    // The data types for the 'id' fields are strings in $data, so they
    // shouldn't match when using strict comparison. But is it worth caring
    // about.
    $this->assertEquals([
      [
        'civicrm_contact_sort_name' => 'Miller, Joe',
        'civicrm_contact_id' => $contact_id,
        'civicrm_cdntaxreceipts_log_issued_on' => $datestr,
        'civicrm_cdntaxreceipts_log_receipt_amount' => '10.00',
        'civicrm_cdntaxreceipts_log_receipt_no' => 'C-00000001',
        'civicrm_cdntaxreceipts_log_issue_type' => 'Single',
        'civicrm_cdntaxreceipts_log_issue_method' => 'Print',
        'civicrm_cdntaxreceipts_log_uid' => 1,
        'civicrm_cdntaxreceipts_log_receipt_status' => 'Issued',
        'civicrm_cdntaxreceipts_log_email_opened' => NULL,
        'civicrm_cdntaxreceipts_log_contributions_contribution_id' => 1,
        'civicrm_contact_sort_name_link' => '/index.php?q=civicrm/contact/view&amp;reset=1&amp;cid=' . $contact_id,
        'civicrm_contact_sort_name_hover' => 'View Contact Summary for this Contact',
      ]
    ], $data);
  }

  /**
   * Test optional columns with one line item.
   */
  public function testFinancialTypeSingle() {
    // create contribution
    $contact_id = $this->individualCreate([], 1);
    $datestr = date('Y-m-d');
    $contribution_id = civicrm_api3('Contribution', 'create', [
      'contact_id' => $contact_id,
      'financial_type_id' => 'Donation',
      'total_amount' => '10',
      'receive_date' => $datestr,
    ])['id'];

    // Need it in DAO format
    $contribution = new CRM_Contribute_DAO_Contribution();
    $contribution->id = $contribution_id;
    $contribution->find(TRUE);
    // issue receipt
    list($result, $method) = cdntaxreceipts_issueTaxReceipt($contribution);

    // run report
    $data = civicrm_api3('report_template', 'getrows', [
      'report_id' => 'cdntaxreceipts/receiptsissued',
      'fields' => [
        'sort_name' => 1,
        'issued_on' => 1,
        'receipt_amount' => 1,
        'receipt_no' => 1,
        'issue_type' => 1,
        'issue_method' => 1,
        'uid' => 1,
        'receipt_status' => 1,
        'email_opened' => 1,
        'contribution_id' => 1,
        'financial_type_id' => 1,
        'payment_instrument_id' => 1,
      ],
    ])['values'];

    // We don't care about this and it was introduced in 5.45 so causes fails
    // on earlier matrices.
    unset($data[0]['class']);

    $this->assertEquals([
      [
        'civicrm_contact_sort_name' => 'Miller, Joe',
        'civicrm_contact_id' => $contact_id,
        'civicrm_cdntaxreceipts_log_issued_on' => $datestr,
        'civicrm_cdntaxreceipts_log_receipt_amount' => '10.00',
        'civicrm_cdntaxreceipts_log_receipt_no' => 'C-00000001',
        'civicrm_cdntaxreceipts_log_issue_type' => 'Single',
        'civicrm_cdntaxreceipts_log_issue_method' => 'Print',
        'civicrm_cdntaxreceipts_log_uid' => 1,
        'civicrm_cdntaxreceipts_log_receipt_status' => 'Issued',
        'civicrm_cdntaxreceipts_log_email_opened' => NULL,
        'civicrm_cdntaxreceipts_log_contributions_contribution_id' => 1,
        'civicrm_line_item_financial_type_id' => 'Donation',
        'civicrm_contribution_payment_instrument_id' => 'Check',
        'civicrm_contact_sort_name_link' => '/index.php?q=civicrm/contact/view&amp;reset=1&amp;cid=' . $contact_id,
        'civicrm_contact_sort_name_hover' => 'View Contact Summary for this Contact',
      ]
    ], $data);
  }

}
