<?php
namespace Civi\Cdntaxreceipts\Tests\Mink;

use Drupal\Tests\civicrm\FunctionalJavascript\CiviCrmTestBase;

/**
 * @group mink
 */
class CdntaxreceiptsBase extends CiviCrmTestBase {

  use \Drupal\Tests\mink_civicrm_helpers\Traits\Utils;

  /**
   * @var array
   */
  protected static $modules = [
    'mink_civicrm_helpers',
  ];

  /**
   * @var int
   *   The uf_id of the logged in user.
   */
  protected $_loggedInUser = NULL;

  public function setUp(): void {
    parent::setUp();

    $this->setUpExtension('org.civicrm.cdntaxreceipts');

    $this->configureTaxReceiptSettings();

    // Temporarily turn off version_check for 5.45 since it pops up a
    // box about a security release which the tests interpret as an error box.
    if (!\CRM_Core_BAO_Domain::isDBVersionAtLeast('5.46.alpha1')) {
      $versioncheck = civicrm_api3('Job', 'get', ['api_action' => 'version_check', 'return' => ['id']]);
      foreach ($versioncheck['values'] as $vcheck) {
        civicrm_api3('Job', 'create', ['id' => $vcheck['id'], 'is_active' => 0]);
      }
    }
  }

  /**
   * Pretty much every test is going to start with this.
   */
  public function createUserAndLogIn(): void {
    $account = $this->createUser([
      'administer CiviCRM',
      'access CiviCRM',
      'administer CiviCRM system',
      'administer CiviCRM data',
      'administer Reports',
      'access CiviReport',
      'access all custom data',
      'edit all contacts',
      'delete contacts',
      'access deleted contacts',
      'access CiviContribute',
      'edit contributions',
      'delete in CiviContribute',
      'issue cdn tax receipts',
    ]);
    $this->drupalLogin($account);
    $this->_loggedInUser = $account->uid;
  }

  /**
   * Configure the extension, i.e. fill out the settings page.
   */
  private function configureTaxReceiptSettings(): void {
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

  /**
   * Compare two files efficiently.
   * @param string $a First file
   * @param string $b Second file
   * @return bool
   */
  private function compareFiles($a, $b): bool {
    // Check if filesize is different
    if (filesize($a) !== filesize($b)) {
      return FALSE;
    }

    // Check if content is different
    $ah = fopen($a, 'rb');
    $bh = fopen($b, 'rb');

    $result = TRUE;
    while (!feof($ah)) {
      if (fread($ah, 8192) != fread($bh, 8192)) {
        $result = FALSE;
        break;
      }
    }

    fclose($ah);
    fclose($bh);

    return $result;
  }

  /**
   * Return the full path to the associated fixture file for a given function.
   * @param string $class The fully qualified class name.
   * @param string $func The function name. Who called us.
   * @param string $type The file type.
   * @return string
   */
  protected function getFixtureFileFor(string $class, string $func, $type = '.pdf') {
    return str_replace('\\', '/', __DIR__) . '/../../../../fixtures/' . preg_replace('/[^a-zA-Z0-9_]/', '_', "{$class}{$func}") . $type;
  }

  /**
   * There should be a file named test.pdf created when running under tests
   * that generate pdfs. We have to fudge the creation dates inside it a bit
   * but otherwise it should be identical to one we're expecting.
   * @param string $class The fully qualified class name.
   * @param string $func The function name. Who called us.
   * @param string $expectedFileName Something like 'Receipt-C-00000001.pdf' or
   *   leave out for some tests where it has a timestamp because then it's not
   *   possible to know, so cdntax has to do some fudging for us.
   *
   */
  protected function assertExpectedPDF(string $class, string $func, string $expectedFileName = 'test.pdf') {
    // Replace windows backslashes.
    $pdf_file = str_replace('\\', '/', \CRM_Core_Config::singleton()->uploadDir . $expectedFileName);
    $this->assertTrue(file_exists($pdf_file), "$pdf_file does not exist");
    $new_name = str_replace('\\', '/', $this->getBrowserOutputDirectory()) . 'Receipts-To-Print-' . \CRM_Cdntaxreceipts_Utils_Time::time() . '.pdf';
    $this->assertTrue(rename($pdf_file, $new_name), "Can't rename $pdf_file to $new_name");
    $this->fudgePDFFile($new_name);
    $expectedFile = $this->getFixtureFileFor($class, $func);
    $this->assertTrue($this->compareFiles($new_name, $expectedFile), "$new_name differs from $expectedFile");
  }

  /**
   * Make the dates in the file match our mock timestamp.
   * Also there's a uuid, which we change to match our fixtures.
   * @param string $filename
   */
  protected function fudgePDFFile(string $filename) {
    $s = file_get_contents($filename);
    // The +10 is because for some reason that's the timezone that ends up
    // in our fixture file.
    $s = preg_replace('/\d{14}\+\d\d/', date('YmdHis', \CRM_Cdntaxreceipts_Utils_Time::time()) . '+10', $s);
    $s = preg_replace('/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+\d\d/', date('Y-m-d\TH:i:s', \CRM_Cdntaxreceipts_Utils_Time::time()) . '+10', $s);
    $s = preg_replace('/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}/', '9e7bde6b-2ad3-c6ba-6656-86ba3cf7b7a2', $s);
    $s = preg_replace('/<[a-f0-9]{32}>/', '<9e7bde6b2ad3c6ba665686ba3cf7b7a2>', $s);
    $this->assertGreaterThan(0, file_put_contents($filename, $s));
  }

}
