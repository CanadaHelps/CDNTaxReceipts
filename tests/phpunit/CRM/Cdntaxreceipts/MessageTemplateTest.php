<?php

/**
 * @group headless
 */
class CRM_Cdntaxreceipts_MessageTemplateTest extends CRM_Cdntaxreceipts_Base {

  public function setUp(): void {
    parent::setUp();
    $this->setDeliveryMethod(CDNTAX_DELIVERY_PRINT_EMAIL);
    // We want to start spooling at different times in each test, so FALSE.
    $this->mut = new CiviMailUtils($this, FALSE);
  }

  public function tearDown(): void {
    $this->mut->stop();
    $this->mut->clearMessages();
    $this->_tablesToTruncate = [
      'civicrm_contact',
    ];
    $this->quickCleanUpFinancialEntities();
    parent::tearDown();
  }

  /**
   * Basic test
   */
  public function testBasic() {
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
    $this->mut->start();
    list($result, $method) = cdntaxreceipts_issueTaxReceipt($contribution);
    $this->assertTrue($result);
    $this->assertEquals('email', $method);

    $msgs = $this->mut->getAllMessages();
    $this->assertCount(2, $msgs);

    // First the one sent to the archive
    $this->assertStringContainsString('From: CDN Tax Org <cdntaxorg@example.org>', $msgs[0]);
    $this->assertStringContainsString('To: "Mr. Joe Miller II" <cdntaxorg@example.org>', $msgs[0]);
    $this->assertStringContainsString('Subject: Your tax receipt C-00000001', $msgs[0]);
    $this->assertStringContainsString("Dear Joe,\n\nAttached please find your official tax receipt for income tax purposes.\n\nCDN Tax Org", $msgs[0]);
    $this->assertStringContainsString("<p>Dear Joe,<br />\n<br />\nAttached please find your official tax receipt for income tax purposes.<br />\n<br />\nCDN Tax Org</p>", $msgs[0]);
    $this->assertStringContainsString("Content-Type: application/pdf;\n name=Receipt-C-00000001.pdf", $msgs[0]);
    $this->assertStringContainsString("Content-Disposition: attachment;\n filename=Receipt-C-00000001.pdf;", $msgs[0]);
    $this->assertStringNotContainsString('civicrm/cdntaxreceipts/open', $msgs[0]);
    // We *could* check the base64 attachment to see if it matches the
    // expected, but we'd have to do the same timestamp fudge we do for mink
    // tests of pdfs. Leaving out for now.

    // Now check the one to the recipient
    $this->assertStringContainsString('From: CDN Tax Org <cdntaxorg@example.org>', $msgs[1]);
    $this->assertStringContainsString('To: "Mr. Joe Miller II" <joe_miller@civicrm.org>', $msgs[1]);
    $this->assertStringContainsString('Subject: Your tax receipt C-00000001', $msgs[1]);
    $this->assertStringContainsString("Dear Joe,\n\nAttached please find your official tax receipt for income tax purposes.\n\nCDN Tax Org", $msgs[1]);
    $this->assertStringContainsString("<p>Dear Joe,<br />\n<br />\nAttached please find your official tax receipt for income tax purposes.<br />\n<br />\nCDN Tax Org</p>", $msgs[1]);
    $this->assertStringContainsString("Content-Type: application/pdf;\n name=Receipt-C-00000001.pdf", $msgs[1]);
    $this->assertStringContainsString("Content-Disposition: attachment;\n filename=Receipt-C-00000001.pdf;", $msgs[1]);
    $this->assertStringContainsString('civicrm/cdntaxreceipts/open', $msgs[1]);
  }

  /**
   * Test with attach to workflow.
   */
  public function testAttachedWorkflow() {
    \Civi::settings()->set('attach_to_workflows', TRUE);
    $this->mut->start();
    // create contribution
    $contact_id = $this->individualCreate([], 1);
    $datestr = date('Y-m-d');
    $contribution_id = civicrm_api3('Contribution', 'create', [
      'contact_id' => $contact_id,
      'financial_type_id' => 'Donation',
      'total_amount' => '10',
      'receive_date' => $datestr,
      'contribution_status_id' => 'Pending',
    ])['id'];
    civicrm_api3('Contribution', 'completetransaction', [
      'id' => $contribution_id,
      'trxn_date' => $datestr,
      'trxn_id' => 'dontcare',
      'is_email_receipt' => 1,
    ]);

    $msgs = $this->mut->getAllMessages();
    $this->assertCount(2, $msgs);

    // First the one sent to the archive.
    $this->assertStringContainsString('From: CDN Tax Org <cdntaxorg@example.org>', $msgs[0]);
    $this->assertStringContainsString('To: "Mr. Joe Miller II" <cdntaxorg@example.org>', $msgs[0]);
    $this->assertStringContainsString('Subject: Your tax receipt C-00000001', $msgs[0]);
    $this->assertStringContainsString("Content-Type: application/pdf;\n name=Receipt-C-00000001.pdf", $msgs[0]);
    $this->assertStringContainsString("Content-Disposition: attachment;\n filename=Receipt-C-00000001.pdf;", $msgs[0]);
    $this->assertStringNotContainsString('civicrm/cdntaxreceipts/open', $msgs[0]);

    // Now the second one for the contribution receipt
    $this->assertStringContainsString('From: FIXME <info@EXAMPLE.ORG>', $msgs[1]);
    $this->assertStringContainsString('To: "Mr. Joe Miller II" <joe_miller@civicrm.org>', $msgs[1]);
    $this->assertStringContainsString('Subject: Receipt - Contribution - Mr. Joe Miller II', $msgs[1]);
    // @todo - is this a bug in cdntax? Why does it start with forward '/'?
    $this->assertStringContainsString("Content-Type: application/pdf;\n name=\"/Receipt-C-00000001.pdf\"", $msgs[1]);
    $this->assertStringContainsString("Content-Disposition: attachment;\n filename=\"/Receipt-C-00000001.pdf\";", $msgs[1]);

    \Civi::settings()->set('attach_to_workflows', FALSE);
  }

}
