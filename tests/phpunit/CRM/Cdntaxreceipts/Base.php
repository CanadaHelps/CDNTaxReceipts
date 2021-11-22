<?php
/**
 * Base class for tests
 */
class CRM_Cdntaxreceipts_Base extends \CiviUnitTestCase {

  public function setUp(): void {
    parent::setUp();
    // We can't put this in setUpHeadless because then it runs too early and
    // our message templates and such get overwritten.
    // Also it only seems to work once and then does nothing the next time, so
    // use api directly.
    // \Civi\Test::headless()->installMe(__DIR__)->apply();
    $this->callAPISuccess('Extension', 'install', ['keys' => 'org.civicrm.cdntaxreceipts']);
    \Civi::settings()->add([
      'org_name' => 'CDN Tax Org',
      'org_address_line1' => '123 Main St.',
      'org_address_line2' => 'Ababa, AA  A1A 1A1',
      'org_tel' => '123-456-7890',
      'org_fax' => '',
      'org_email' => 'cdntaxorg@example.org',
      'org_web' => 'https://cdntaxorg.example.org',
      'org_charitable_no' => '12345-678-RR0001',
      'receipt_prefix' => 'C-',
      'receipt_serial' => 0,
      'receipt_authorized_signature_text' => 'Receet Sighnor',
      'issue_inkind' => 0,
      'delivery_method' => CDNTAX_DELIVERY_DATA_ONLY,
      'attach_to_workflows' => 0,
      'enable_advanced_eligibility_report' => 0,
      'email_from' => 'cdntaxorg@example.org',
      'email_archive' => 'cdntaxorg@example.org',
    ]);
  }

  public function tearDown(): void {
    // The uninstallMe function doesn't seem to do anything?
    //\Civi\Test::headless()->uninstallMe(__DIR__)->apply();
    $this->callAPISuccess('Extension', 'disable', ['keys' => 'org.civicrm.cdntaxreceipts']);
    $this->callAPISuccess('Extension', 'uninstall', ['keys' => 'org.civicrm.cdntaxreceipts']);
    parent::tearDown();
  }

  /**
   * Set a delivery method.
   * The default is data only, which is useful for just testing the logs.
   * If we want a different method, we need to also remember to adjust the
   * mail settings since otherwise drupal gives errors about failed mail.
   * @param int $method
   */
  protected function setDeliveryMethod(int $method) {
    $mb = \Civi::settings()->get('mailing_backend');
    \Civi::settings()->set('mailing_backend', array_merge($mb, ['outBound_option' => \CRM_Mailing_Config::OUTBOUND_OPTION_MOCK]));

    \Civi::settings()->set('delivery_method', $method);
  }

}
