<?php
namespace Civi\Cdntaxreceipts\Tests\Mink;

/**
 * @group mink
 */
class IssueTest extends CdntaxreceiptsBase {

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

  public function testIssueTaxReceipt(bool $printOverride = FALSE) {
    $contribution = civicrm_api3('Contribution', 'create', [
      'contact_id' => $this->contact['id'],
      'financial_type_id' => 'Donation',
      'total_amount' => '10',
      'receive_date' => date('Y-m-d H:i:s', \CRM_Cdntaxreceipts_Utils_Time::time()),
    ]);

    // view the contribution
    $this->drupalGet(\CRM_Utils_System::url("civicrm/contact/view/contribution", "reset=1&id={$contribution['id']}&cid={$this->contact['id']}&action=view", TRUE, NULL, FALSE));
    $this->assertPageHasNoErrorMessages();

    // There's been ongoing intermittent fails, more often since drupal 10. See
    // if this helps.
    $this->assertSession()->waitForElementVisible('css', '.crm-button_qf_ContributionView_submit_issue_tax_receipt');

    // click the tax receipt button
    $this->getSession()->getPage()->pressButton('Tax Receipt');
    $this->assertSession()->pageTextContains('A tax receipt has not been issued for this contribution.');
    $this->assertPageHasNoErrorMessages();

    // I don't know why but we need to wait for it. It's strange because if we
    // don't wait for it then it's not like it can't find it to press, it's that
    // pressing it does nothing. Sometimes we need to press twice.
    $this->assertSession()->waitForElementVisible('css', '.crm-button_qf_ViewTaxReceipt_next');

    if ($printOverride) {
      $this->assertSession()->waitForElementVisible('css', '#printOverride');
      $this->getSession()->getPage()->checkField('printOverride');
    }

    $this->getSession()->getPage()->pressButton('_qf_ViewTaxReceipt_next-bottom');
    $this->assertSession()->pageTextContains("C-0000000{$contribution['id']}");
    $this->assertSession()->pageTextContains('Re-Issue Tax Receipt');
    $this->assertPageHasNoErrorMessages();
    $this->htmlOutput();
  }

  /**
   * This is identical to testIssueTaxReceipt() but we use print method.
   * We don't verify the PDF here.
   */
  public function testIssueTaxReceiptPrint() {
    $this->setDeliveryMethod(CDNTAX_DELIVERY_PRINT_ONLY);
    $this->testIssueTaxReceipt();
    $this->assertSession()->pageTextContains('Please download and print the receipt that is generated. You will need to send a printed copy to the contributor.');
  }

  /**
   * This is identical to testIssueTaxReceipt() but we use email method.
   * We don't verify the PDF here.
   */
  public function testIssueTaxReceiptEmail() {
    $this->setDeliveryMethod(CDNTAX_DELIVERY_PRINT_EMAIL);
    $this->testIssueTaxReceipt();
    $this->assertSession()->pageTextContains('The receipt will be sent by email to the contributor (anthony.anderson@example.org).');
    $this->assertSession()->pageTextContains('Tax Receipt has been emailed to the contributor.');
  }

  /**
   * This is identical to testIssueTaxReceipt() but we use select print override.
   * We don't verify the PDF here.
   */
  public function testIssueTaxReceiptPrintOverride() {
    $this->setDeliveryMethod(CDNTAX_DELIVERY_PRINT_EMAIL);
    $this->testIssueTaxReceipt(TRUE);
    $this->assertNotNull($this->getSession()->getPage()->find('css', '.crm-info-panel tbody tr td:contains("Print")'));
  }

  /**
   * Similar but with accented characters.
   * Maybe should expand this to test switching language to french and checking
   * some other things?
   */
  public function testIssueTaxReceiptAccentedCharacters() {
    $mock_time = '2023-01-02 10:11:12';
    \CRM_Cdntaxreceipts_Utils_Time::setTime($mock_time);

    $oldContact = $this->contact;
    $this->contact = $this->createContact(0, ['first_name' => 'Pièrre', 'last_name' => 'Garçon']);
    \Civi\Api4\Address::create(FALSE)
      ->setValues([
        'contact_id' => $this->contact['id'],
        'street_address' => '123 Rue Noël',
        'city' => 'Montréal',
        'state_province_id:name' => 'Quebec',
        'country_id:name' => 'CA',
      ])
      ->execute();

    $this->setDeliveryMethod(CDNTAX_DELIVERY_PRINT_ONLY);
    $this->testIssueTaxReceipt();

    $this->assertExpectedPDF(__CLASS__, __FUNCTION__, 'Receipt-C-00000001-Pièrre_Garçon.pdf');

    \CRM_Cdntaxreceipts_Utils_Time::reset();
    $this->contact = $oldContact;
  }

}
