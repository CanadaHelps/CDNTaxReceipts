<?php

/**
 * @group headless
 */
class CRM_Cdntaxreceipts_InkindTest extends CRM_Cdntaxreceipts_Base {

  private $finCount;
  private $groupCount;
  private $groupId;
  private $hookCount;

  public function setUp(): void {
    parent::setUp();
    $this->finCount = $this->callAPISuccess('FinancialType', 'getcount', []);
    $this->groupCount = $this->callAPISuccess('CustomGroup', 'getcount', []);
    cdntaxreceipts_configure_inkind_fields();
    $this->groupId = $this->callAPISuccess('CustomGroup', 'getsingle', ['name' => 'In_kind_donation_fields'])['id'];
    $this->hookCount = 0;
  }

  public function tearDown(): void {
    \Civi\Api4\CustomField::delete(FALSE)->addWhere('custom_group_id', '=', $this->groupId)->execute();
    \Civi\Api4\CustomGroup::delete(FALSE)->addWhere('id', '=', $this->groupId);
    \Civi\Api4\FinancialType::delete(FALSE)->addWhere('id', '=', \Civi::settings()->get('cdntaxreceipts_inkind'));
    parent::tearDown();
  }

  /**
   * Test Inkind creation and a couple edge-cases.
   */
  public function testInkindCreation() {
    // we create them in setup, so the count should be one more than before
    $this->assertEquals($this->finCount + 1, $this->callAPISuccess('FinancialType', 'getcount', []));
    $this->assertEquals($this->groupCount + 1, $this->callAPISuccess('CustomGroup', 'getcount', []));
    $this->assertEquals(4, $this->callAPISuccess('CustomField', 'getcount', ['custom_group_id' => $this->groupId]));
    $financial_type_id = \Civi::settings()->get('cdntaxreceipts_inkind');
    $this->assertNotEmpty($financial_type_id);

    // Now disable it and call setup routine again. It shouldn't recreate
    // anything and the count should still be the same as above.
    $this->callAPISuccess('FinancialType', 'create', ['id' => $financial_type_id, 'is_active' => 0]);
    cdntaxreceipts_configure_inkind_fields();
    $this->assertEquals($this->finCount + 1, $this->callAPISuccess('FinancialType', 'getcount', []));
    $this->assertEquals($this->groupCount + 1, $this->callAPISuccess('CustomGroup', 'getcount', []));
    $this->assertEquals(4, $this->callAPISuccess('CustomField', 'getcount', ['custom_group_id' => $this->groupId]));

    // Now re-enable but change the name and repeat.
    $this->callAPISuccess('FinancialType', 'create', ['id' => $financial_type_id, 'is_active' => 1, 'name' => 'Donations In Kind']);
    cdntaxreceipts_configure_inkind_fields();
    $this->assertEquals($this->finCount + 1, $this->callAPISuccess('FinancialType', 'getcount', []));
    $this->assertEquals($this->groupCount + 1, $this->callAPISuccess('CustomGroup', 'getcount', []));
    $this->assertEquals(4, $this->callAPISuccess('CustomField', 'getcount', ['custom_group_id' => $this->groupId]));
  }

  public function testIssue() {
    $custom_field_ids = \Civi\Api4\CustomField::get(FALSE)
      ->addSelect('id')
      ->addWhere('custom_group_id', '=', $this->groupId)
      ->execute();
    $custom_fields = [];
    foreach ($custom_field_ids as $id) {
      $custom_fields['custom_' . $id['id']] = 'Dummy text ' . $id['id'];
    }
    $contact_id = $this->individualCreate(['first_name' => 'Optimus']);
    $contribution_id = $this->callAPISuccess('Contribution', 'create', array_merge([
      'contact_id' => $contact_id,
      'financial_type_id' => \Civi::settings()->get('cdntaxreceipts_inkind'),
      'total_amount' => '10',
      'receive_date' => date('Y-m-d'),
    ], $custom_fields))['id'];
    // Need it in DAO format
    $contribution = new CRM_Contribute_DAO_Contribution();
    $contribution->id = $contribution_id;
    $contribution->find(TRUE);
    // implement hook so we can inspect the in-kind fields
    \Civi::dispatcher()->addListener('hook_cdntaxreceipts_alter_receipt', function($e) use ($custom_fields) {
      $this->assertEquals(array_values($custom_fields), $e->receipt['inkind_values']);
      $this->hookCount++;
    });
    // issue receipt
    list($result, $method) = cdntaxreceipts_issueTaxReceipt($contribution);
    $this->assertTrue($result);
    $this->assertEquals('data', $method);
    $this->assertEquals(1, $this->hookCount, 'Hook did not fire or was not listened to.');
  }

}
