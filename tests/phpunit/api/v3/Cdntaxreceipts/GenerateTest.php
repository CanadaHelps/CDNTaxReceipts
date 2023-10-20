<?php

/**
 * Cdntaxreceipts.Generate API Test Case
 * This is a generic test class implemented with PHPUnit.
 * @group headless
 */
class api_v3_Cdntaxreceipts_GenerateTest extends CRM_Cdntaxreceipts_Base {
  use \Civi\Test\Api3TestTrait;

  public function testGenerate() {
    \Civi::settings()->set('delivery_method', CDNTAX_DELIVERY_PRINT_EMAIL);
    $mut = new CiviMailUtils($this);
    $contact_id = $this->individualCreate(['first_name' => 'Tim', 'last_name' => 'Horton', 'email' => 'timmys@civicrm.org']);
    $contribution_id = $this->contributionCreate(['contact_id' => $contact_id]);
    $result = civicrm_api3('Cdntaxreceipts', 'generate', ['contribution_id' => $contribution_id, 'sequential' => 1]);
    $this->assertAPISuccess($result);
    $this->assertEquals($contribution_id, $result['values'][0]['contribution_id']);
    $this->assertEquals('email', $result['values'][0]['issue_method']);
    $mut->checkMailLog(['From: CDN Tax Org <cdntaxorg@example.org>', 'Subject: Your tax receipt C-00000001', 'Tim Horton', 'timmys@civicrm.org']);
  }

  public function testIneligibleContribution() {
    \Civi::settings()->set('delivery_method', CDNTAX_DELIVERY_PRINT_EMAIL);
    $mut = new CiviMailUtils($this);
    $contact_id = $this->individualCreate(['first_name' => 'James', 'last_name' => 'Brown', 'email' => 'jbrown@civicrm.org']);
    $contribution_id = $this->contributionCreate(['contact_id' => $contact_id, 'total_amount' => 5, 'non_deductible_amount' => 5]);
    $exceptionThrown = FALSE;
    try {
      $result = civicrm_api3('Cdntaxreceipts', 'generate', ['contribution_id' => $contribution_id]);
    }
    catch (CRM_Core_Exception $e) {
      $exceptionThrown = TRUE;
      $mut->assertMailLogEmpty();
      $this->assertEquals('Contribution not eligible.', $e->getMessage());
      $this->assertEquals(CDNTAXRECEIPTS_API_INELIGIBLE, $e->getErrorCode());
    }
    $this->assertTrue($exceptionThrown, 'Should have thrown an exception');
  }

  public function testNoContribution() {
    $exceptionThrown = FALSE;
    try {
      civicrm_api3('Cdntaxreceipts', 'generate', ['contribution_id' => 55555]);
    }
    catch (CRM_Core_Exception $e) {
      $exceptionThrown = TRUE;
      $this->assertEquals('Contribution ID not found.', $e->getMessage());
      $this->assertEquals(CDNTAXRECEIPTS_API_NOTFOUND, $e->getErrorCode());
    }
    $this->assertTrue($exceptionThrown, 'Should have thrown an exception');
  }

  public function testNoValidEmail() {
    $contact_id = $this->individualCreate();
    \Civi\Api4\Email::update(FALSE)
      ->addWhere('contact_id', '=', $contact_id)
      ->addValue('on_hold', 1)
      ->execute();
    $contribution_id = $this->contributionCreate(['contact_id' => $contact_id]);
    $exceptionThrown = FALSE;
    try {
      civicrm_api3('Cdntaxreceipts', 'generate', ['contribution_id' => $contribution_id]);
    }
    catch (CRM_Core_Exception $e) {
      $exceptionThrown = TRUE;
      $this->assertEquals('Contact does not have a valid email.', $e->getMessage());
      $this->assertEquals(CDNTAXRECEIPTS_API_NOEMAIL, $e->getErrorCode());
    }
    $this->assertTrue($exceptionThrown, 'Should have thrown an exception');

    \Civi\Api4\Email::delete(FALSE)
      ->addWhere('contact_id', '=', $contact_id)
      ->execute();
    $exceptionThrown = FALSE;
    try {
      civicrm_api3('Cdntaxreceipts', 'generate', ['contribution_id' => $contribution_id]);
    }
    catch (CRM_Core_Exception $e) {
      $exceptionThrown = TRUE;
      $this->assertEquals('Contact does not have a valid email.', $e->getMessage());
      $this->assertEquals(CDNTAXRECEIPTS_API_NOEMAIL, $e->getErrorCode());
    }
    $this->assertTrue($exceptionThrown, 'Should have thrown an exception');
  }

}
