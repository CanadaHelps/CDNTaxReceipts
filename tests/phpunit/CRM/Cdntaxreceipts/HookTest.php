<?php

/**
 * @group headless
 */
class CRM_Cdntaxreceipts_HookTest extends CRM_Cdntaxreceipts_Base {

  /**
   * Test eligibleAmount
   */
  public function testEligibleAmount() {
    $contact_id = $this->individualCreate([], 0, TRUE);
    $contribution = $this->callAPISuccess('contribution', 'create', [
      'contact_id' => $contact_id,
      'financial_type_id' => 'Donation',
      'total_amount' => '10',
    ]);
    $amount = cdntaxreceipts_eligibleAmount($contribution['id']);
    $this->assertEquals(10, $amount);
  }

  /**
   * Test eligibleAmount with hook
   * @see hookForEligibleAmount
   */
  public function testEligibleAmountWithHook() {
    \Civi::dispatcher()->addListener('hook_cdntaxreceipts_eligibleAmount', [$this, 'hookForEligibleAmount']);
    $contact_id = $this->individualCreate([], 0, TRUE);
    $contribution = $this->callAPISuccess('contribution', 'create', [
      'contact_id' => $contact_id,
      'financial_type_id' => 'Donation',
      'total_amount' => '10',
    ]);
    // We're expecting our hook to subtract 5 from the amount.
    $amount = cdntaxreceipts_eligibleAmount($contribution['id']);
    $this->assertEquals(5, $amount);
  }

  /**
   * This is the listener for hook_cdntaxreceipts_eligibleAmount
   *
   * @param \Civi\Core\Event\GenericHookEvent $e
   *   has member CRM_Contribute_DAO_Contribution $contribution
   */
  public function hookForEligibleAmount(\Civi\Core\Event\GenericHookEvent $e) {
    // alter the existing amount by subtracting 5
    $e->addReturnValues([$e->contribution->total_amount - 5]);
  }

}
