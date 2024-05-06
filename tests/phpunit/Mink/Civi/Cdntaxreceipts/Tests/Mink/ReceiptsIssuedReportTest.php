<?php
namespace Civi\Cdntaxreceipts\Tests\Mink;

/**
 * @group mink
 */
class ReceiptsIssuedReportTest extends CdntaxreceiptsBase {

  /**
   * @var array
   *   We always create one contact to start with.
   */
  protected $contact;

  public function setUp(): void {
    parent::setUp();
    if (!\CRM_Core_BAO_Domain::isDBVersionAtLeast('5.43.alpha1')) {
      $this->markTestIncomplete('Test requires E_NOTICE fix that is only in 5.43+');
    }
    $this->createUserAndLogIn();
    $this->contact = $this->createContact();
    $this->setDeliveryMethod(CDNTAX_DELIVERY_PRINT_ONLY);
  }

  /**
   * Basic test with defaults.
   */
  public function testReport() {
    $contribution = civicrm_api3('Contribution', 'create', [
      'contact_id' => $this->contact['id'],
      'financial_type_id' => 'Donation',
      'total_amount' => '10',
      'receive_date' => date('Y-m-d'),
    ]);

    $this->issueReceipt($contribution['id'], $this->contact['id']);

    // go to the report
    $this->drupalGet(\CRM_Utils_System::url('civicrm/report/cdntaxreceipts%3Areceiptsissued', 'reset=1', TRUE, NULL, FALSE));
    $this->assertPageHasNoErrorMessages();

    // click the button
    $this->getSession()->getPage()->pressButton('_qf_ReceiptsIssued_submit');
    $this->htmlOutput();
    $this->assertPageHasNoErrorMessages();

    $this->assertSession()->pageTextContains('Tax Receipts - Receipts Issued');
    $this->assertSession()->pageTextContains('C-00000001');
    $this->assertSession()->pageTextContains('Total Amount Issued');
    $this->assertSession()->pageTextContains('$ 10.00');
  }

  /**
   * Test optional columns with one line item.
   */
  public function testFinancialTypeSingle() {
    $contribution = civicrm_api3('Contribution', 'create', [
      'contact_id' => $this->contact['id'],
      'financial_type_id' => 'Donation',
      'total_amount' => '10',
      'receive_date' => date('Y-m-d'),
    ]);

    $this->issueReceipt($contribution['id'], $this->contact['id']);

    // go to the report
    $this->drupalGet(\CRM_Utils_System::url('civicrm/report/cdntaxreceipts%3Areceiptsissued', 'reset=1', TRUE, NULL, FALSE));
    $this->assertPageHasNoErrorMessages();

    // select the financial type and payment method columns
    $this->getSession()->getPage()->checkField('fields_financial_type_id');
    $this->getSession()->getPage()->checkField('fields_payment_instrument_id');
    $this->htmlOutput();

    // click the button
    $this->getSession()->getPage()->pressButton('_qf_ReceiptsIssued_submit');
    $this->htmlOutput();
    $this->assertPageHasNoErrorMessages();

    $this->assertSession()->pageTextContains('Financial Type (current value)');
    $this->assertSession()->pageTextContains('Donation');
    $this->assertSession()->pageTextContains('Payment Method (current value)');
    $this->assertSession()->pageTextContains('Check');
  }

  /**
   * Test optional columns with two line items.
   */
  public function testFinancialTypeMultiple() {
    $priceFields = $this->createPriceSet();
    $contribution_id = $this->createContributionFromPriceFields($priceFields);

    $this->issueReceipt($contribution_id, $this->contact['id']);

    // go to the report
    $this->drupalGet(\CRM_Utils_System::url('civicrm/report/cdntaxreceipts%3Areceiptsissued', 'reset=1', TRUE, NULL, FALSE));
    $this->assertPageHasNoErrorMessages();

    // select the financial type and payment method columns
    $this->getSession()->getPage()->checkField('fields_financial_type_id');
    $this->getSession()->getPage()->checkField('fields_payment_instrument_id');

    // click the button
    $this->getSession()->getPage()->pressButton('_qf_ReceiptsIssued_submit');
    $this->htmlOutput();
    $this->assertPageHasNoErrorMessages();

    $this->assertSession()->pageTextContains('Financial Type (current value)');
    $this->assertSession()->pageTextContains('Donation, Event Fee');
    $this->assertSession()->pageTextContains('Payment Method (current value)');
    $this->assertSession()->pageTextContains('Check');
    // Amount should only be the donation part
    // @todo how do you check that this is in the right spot? It's in a cell
    // in a table, but might appear somewhere else too giving a false positive.
    $this->assertSession()->pageTextContains('$ 10.00');
  }

  /**
   * Helper to click the buttons.
   *
   * @param int $contribution_id
   * @param int $contact_id
   */
  private function issueReceipt(int $contribution_id, int $contact_id) {
    // go to the contribution
    $this->drupalGet(\CRM_Utils_System::url("civicrm/contact/view/contribution", "reset=1&id={$contribution_id}&cid={$contact_id}&action=view", TRUE, NULL, FALSE));
    $this->htmlOutput();
    $this->assertPageHasNoErrorMessages();

    // click the tax receipt button
    $this->getSession()->getPage()->pressButton('Tax Receipt');
    $this->assertPageHasNoErrorMessages();
    $this->assertSession()->waitForElementVisible('css', '.crm-button_qf_ViewTaxReceipt_next');
    $this->getSession()->getPage()->pressButton('_qf_ViewTaxReceipt_next-bottom');
    $this->assertPageHasNoErrorMessages();
  }

  /**
   * Make a price set with an event component and a donation component.
   * Partly copied from CiviUnitTestCase.
   * @return array
   */
  private function createPriceSet(): array {
    $paramsSet['title'] = 'Price Set' . substr(sha1(rand()), 0, 7);
    $paramsSet['name'] = \CRM_Utils_String::titleToVar($paramsSet['title']);
    $paramsSet['is_active'] = TRUE;
    $paramsSet['financial_type_id'] = 'Event Fee';
    $paramsSet['extends'] = 1;
    $priceSet = civicrm_api3('price_set', 'create', $paramsSet);

    $paramsField = [
      'label' => 'Price Field',
      'name' => \CRM_Utils_String::titleToVar('Price Field'),
      'html_type' => 'Radio',
      'option_label' => ['1' => 'Price Field 1', '2' => 'Price Field 2'],
      'option_value' => ['1' => 75, '2' => 100],
      'option_name' => ['1' => 'Price Field 1', '2' => 'Price Field 2'],
      'option_weight' => ['1' => 1, '2' => 2],
      'option_amount' => ['1' => 75, '2' => 100],
      'is_display_amounts' => 1,
      'weight' => 1,
      'options_per_line' => 1,
      'is_active' => ['1' => 1, '2' => 1],
      'price_set_id' => $priceSet['id'],
      'is_enter_qty' => 0,
      'financial_type_id' => \CRM_Core_DAO::getFieldValue('CRM_Financial_DAO_FinancialType', 'Event Fee', 'id', 'name'),
    ];
    $priceField = \CRM_Price_BAO_PriceField::create($paramsField);
    $returnValue = [civicrm_api3('PriceFieldValue', 'get', ['price_field_id' => $priceField->id])];

    $paramsField = [
      'label' => 'Donation Field',
      'name' => \CRM_Utils_String::titleToVar('Donation Field'),
      'html_type' => 'Radio',
      'option_label' => ['1' => 'Donation Field 1', '2' => 'Donation Field 2'],
      'option_value' => ['1' => 10, '2' => 20],
      'option_name' => ['1' => 'Donation Field 1', '2' => 'Donation Field 2'],
      'option_weight' => ['1' => 1, '2' => 2],
      'option_amount' => ['1' => 10, '2' => 20],
      'is_display_amounts' => 1,
      'weight' => 1,
      'options_per_line' => 1,
      'is_active' => ['1' => 1, '2' => 1],
      'price_set_id' => $priceSet['id'],
      'is_enter_qty' => 0,
      'financial_type_id' => \CRM_Core_DAO::getFieldValue('CRM_Financial_DAO_FinancialType', 'Donation', 'id', 'name'),
    ];
    $priceField = \CRM_Price_BAO_PriceField::create($paramsField);
    $returnValue[] = civicrm_api3('PriceFieldValue', 'get', ['price_field_id' => $priceField->id]);
    return $returnValue;
  }

  /**
   * Kind of copied from CRMTraits_Financial_PriceSetTrait
   * @param array $priceFields
   * @return int
   */
  private function createContributionFromPriceFields(array $priceFields): int {
    $params = [
      'contact_id' => $this->contact['id'],
      'total_amount' => 85,
      'financial_type_id' => 'Event Fee',
      'contribution_status_id' => 'Pending',
    ];
    foreach ($priceFields as $pf) {
      foreach ($pf['values'] as $key => $priceField) {
        $params['line_items'][]['line_item'][$key] = [
          'price_field_id' => $priceField['price_field_id'],
          'price_field_value_id' => $priceField['id'],
          'label' => $priceField['label'],
          'field_title' => $priceField['label'],
          'qty' => 1,
          'unit_price' => $priceField['amount'],
          'line_total' => $priceField['amount'],
          'financial_type_id' => $priceField['financial_type_id'],
          'entity_table' => 'civicrm_contribution',
        ];
        // just use the first option for each field
        break;
      }
    }
    $order = civicrm_api3('Order', 'create', $params);
    civicrm_api3('Payment', 'create', [
      'contribution_id' => $order['id'],
      'total_amount' => $params['total_amount'],
    ]);
    return $order['id'];
  }

}
