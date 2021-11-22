<?php
namespace Civi\Cdntaxreceipts\Tests\Mink;

/**
 * @group mink
 */
class AggregateTest extends CdntaxreceiptsBase {

  /**
   * @var array
   *   We always create one contact to start with.
   */
  protected $contact;

  public function setUp(): void {
    parent::setUp();
    $this->createUserAndLogIn();
    $this->contact = $this->createContact();
  }

  public function testAggregateTaxReceipt() {
    // set up mock time
    $mock_time = '2021-01-02 10:11:12';
    \CRM_Cdntaxreceipts_Utils_Time::setTime($mock_time);

    $this->setDeliveryMethod(CDNTAX_DELIVERY_PRINT_ONLY);

    $contribution1_1 = civicrm_api3('Contribution', 'create', [
      'contact_id' => $this->contact['id'],
      'financial_type_id' => 'Donation',
      'total_amount' => '10',
      'receive_date' => '2020-01-01',
    ]);
    $contribution1_2 = civicrm_api3('Contribution', 'create', [
      'contact_id' => $this->contact['id'],
      'financial_type_id' => 'Donation',
      'total_amount' => '20',
      'receive_date' => '2020-02-01',
    ]);

    $contact2 = $this->createContact(1);
    $contribution2_1 = civicrm_api3('Contribution', 'create', [
      'contact_id' => $contact2['id'],
      'financial_type_id' => 'Donation',
      'total_amount' => '40',
      'receive_date' => '2020-03-01',
    ]);

    // search
    $this->drupalGet(\CRM_Utils_System::url("civicrm/contribute/search", "reset=1&force=1", TRUE, NULL, FALSE));
    $this->assertPageHasNoErrorMessages();

    // choose all
    $this->getSession()->getPage()->selectFieldOption('radio_ts', 'ts_all');

    // select aggregate task
    $this->getSession()->executeScript("CRM.$('#task').val('406').trigger('change');");
    $this->assertPageHasNoErrorMessages();

    // Pick the receipt year and issue receipt
    $this->getSession()->getPage()->selectFieldOption('receipt_year', "issue_2020");
    $this->getSession()->getPage()->pressButton('_qf_IssueAggregateTaxReceipts_next');
    $this->assertPageHasNoErrorMessages();

    // check logs
    $records = \CRM_Core_DAO::executeQuery("SELECT * FROM cdntaxreceipts_log")->fetchAll();
    $expecteds = array(
      0 => array(
        'id' => '1',
        'issued_on' => (string) strtotime($mock_time),
        'receipt_no' => 'C-00000003',
        'contact_id' => (string) $contact2['id'],
        'receipt_amount' => '40.00',
        'is_duplicate' => '0',
        'issue_type' => 'aggregate',
        'issue_method' => 'print',
        'receipt_status' => 'issued',
      ),
      1 => array(
        'id' => '2',
        'issued_on' => (string) strtotime($mock_time),
        'receipt_no' => 'C-00000002',
        'contact_id' => (string) $this->contact['id'],
        'receipt_amount' => '30.00',
        'is_duplicate' => '0',
        'issue_type' => 'aggregate',
        'issue_method' => 'print',
        'receipt_status' => 'issued',
      ),
    );
    // There's other fields we either can't reliably know or don't care about,
    // but the expected should match a subset of them.
    foreach ($expecteds as $eid => $expected) {
      $intersect = array_intersect_key($records[$eid], $expected);
      $this->assertNotEmpty($intersect);
      $this->assertEquals($expected, $intersect);
    }

    $this->assertExpectedPDF(__CLASS__, __FUNCTION__);

    \CRM_Cdntaxreceipts_Utils_Time::reset();

    $this->htmlOutput();
  }

}
