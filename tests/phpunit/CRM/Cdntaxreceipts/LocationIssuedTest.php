<?php

/**
 * @group headless
 */
class CRM_Cdntaxreceipts_LocationIssuedTest extends CRM_Cdntaxreceipts_Base {

  /**
   * Test the location issued is recorded for the receipt.
   */
  public function testLocationIssued() {
    \Civi::settings()->set('receipt_location_issued', 'Vancouver');

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
    $this->assertEquals('data', $method);

    $records = \CRM_Core_DAO::executeQuery("SELECT * FROM cdntaxreceipts_log")->fetchAll();
    $this->assertCount(1, $records);
    $this->assertEquals('Vancouver', $records[0]['location_issued']);
  }

}
