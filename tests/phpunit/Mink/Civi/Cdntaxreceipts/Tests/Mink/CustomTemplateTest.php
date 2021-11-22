<?php
namespace Civi\Cdntaxreceipts\Tests\Mink;

/**
 * @group mink
 */
class CustomTemplateTest extends CdntaxreceiptsBase {

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

  public function testCustomTemplate() {
    // set up mock time
    $mock_time = '2021-02-03 10:11:12';
    \CRM_Cdntaxreceipts_Utils_Time::setTime($mock_time);

    $this->setDeliveryMethod(CDNTAX_DELIVERY_PRINT_ONLY);

    // copy our template to the 'custom' dir
    $this->assertTrue(copy(
      str_replace('\\', '/', __DIR__) . '/../../../../fixtures/receipt_pdftemplate.pdf',
      str_replace('\\', '/', \CRM_Core_Config::singleton()->customFileUploadDir) . 'receipt_pdftemplate.pdf')
    );
    \Civi::settings()->set('receipt_pdftemplate', 'receipt_pdftemplate.pdf');

    $contribution = civicrm_api3('Contribution', 'create', [
      'contact_id' => $this->contact['id'],
      'financial_type_id' => 'Donation',
      'total_amount' => '10',
      'receive_date' => '2021-02-03',
    ]);

    // view the contribution
    $this->drupalGet(\CRM_Utils_System::url("civicrm/contact/view/contribution", "reset=1&id={$contribution['id']}&cid={$this->contact['id']}&action=view", TRUE, NULL, FALSE));
    $this->assertPageHasNoErrorMessages();

    // click the tax receipt button
    $this->getSession()->getPage()->pressButton('Tax Receipt');
    $this->assertSession()->pageTextContains('A tax receipt has not been issued for this contribution.');
    $this->assertPageHasNoErrorMessages();

    $this->assertSession()->waitForElementVisible('css', '.crm-button_qf_ViewTaxReceipt_next');
    $this->getSession()->getPage()->pressButton('_qf_ViewTaxReceipt_next-bottom');
    $this->assertSession()->pageTextContains("C-00000001");
    $this->assertSession()->pageTextContains('Re-Issue Tax Receipt');
    $this->assertPageHasNoErrorMessages();

    $this->assertExpectedPDF(__CLASS__, __FUNCTION__, 'Receipt-C-00000001.pdf');

    \CRM_Cdntaxreceipts_Utils_Time::reset();

    $this->htmlOutput();
  }

}
