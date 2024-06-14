<?php

/**
 * @group headless
 */
class CRM_Cdntaxreceipts_SourceTest extends CRM_Cdntaxreceipts_Base {

  public function setUp(): void {
    parent::setUp();
    $this->custom_group_id = $this->callAPISuccess('CustomGroup', 'create', [
      'name' => 'mycustom',
      'title' => 'My Custom Fields',
      'extends' => 'Contribution',
    ])['id'];
    $this->custom_field_id = $this->callAPISuccess('CustomField', 'create', [
      'custom_group_id' => $this->custom_group_id,
      'name' => 'category',
      'label' => 'Some Category',
      'data_type' => 'String',
      'html_type' => 'Text',
    ])['id'];
  }

  public function tearDown(): void {
    $this->callAPISuccess('CustomField', 'delete', ['id' => $this->custom_field_id]);
    $this->callAPISuccess('CustomGroup', 'delete', ['id' => $this->custom_group_id]);
    parent::tearDown();
  }

  /**
   * Test the variations of source
   *
   * @dataProvider sourceData
   * @param array $input
   * @param string $expected
   */
  public function testSource(array $input, string $expected) {
    if (isset($input['setting_field'])) {
      // We need to replace this at runtime since dataproviders are evaluated before setUp().
      $setting = str_replace('%%id%%', $this->custom_field_id, $input['setting_field']);
      \Civi::settings()->set('cdntaxreceipts_source_field', $setting);
    }
    if (isset($input['setting_label'])) {
      \Civi::settings()->set('cdntaxreceipts_source_label_' . CRM_Core_I18n::getLocale(), $input['setting_label']);
    }
    // create contribution
    $contact_id = $this->individualCreate(['first_name' => 'Bob'], 1);
    $datestr = date('Y-m-d');
    $contribution_id = $this->callAPISuccess('Contribution', 'create', [
      'contact_id' => $contact_id,
      'financial_type_id' => 'Donation',
      'total_amount' => '10',
      'receive_date' => $datestr,
      'source' => $input['source'],
      'custom_' . $this->custom_field_id => $input['custom'],
    ])['id'];

    // Need it in DAO format
    $contribution = new CRM_Contribute_DAO_Contribution();
    $contribution->id = $contribution_id;
    $contribution->find(TRUE);
    $this->assertEquals($expected, _cdntaxreceipts_get_contribution_source($contribution));
  }

  public function sourceData(): array {
    return [
      [
        [
          'source' => 'Bake Sale',
          'setting_field' => NULL,
          'setting_label' => NULL,
          'custom' => '',
        ],
        'Source: Bake Sale',
      ],
      [
        [
          'source' => 'Bake Sale',
          'setting_field' => '',
          'setting_label' => '',
          'custom' => '',
        ],
        '',
      ],
      [
        [
          'source' => 'Bake Sale',
          'setting_field' => '{contribution.source}',
          'setting_label' => 'Source: ',
          'custom' => '',
        ],
        'Source: Bake Sale',
      ],
      [
        [
          'source' => 'Bake Sale',
          'setting_field' => '{contribution.source}',
          'setting_label' => '',
          'custom' => '',
        ],
        'Bake Sale',
      ],
      // When the config says blank, it shouldn't print the word Source either.
      [
        [
          'source' => 'Bake Sale',
          'setting_field' => '',
          'setting_label' => 'Source: ',
          'custom' => '',
        ],
        '',
      ],
      // When source is blank, it shouldn't print the word Source either.
      [
        [
          'source' => '',
          'setting_field' => '{contribution.source}',
          'setting_label' => 'Source: ',
          'custom' => '',
        ],
        '',
      ],
      [
        [
          'source' => 'Bake Sale',
          'setting_field' => '{contribution.custom_%%id%%}',
          'setting_label' => 'Some Category: ',
          'custom' => 'Red',
        ],
        'Some Category: Red',
      ],
      [
        [
          'source' => 'Bake Sale',
          'setting_field' => 'X:{contact.first_name} Y:{contribution.custom_%%id%%}',
          'setting_label' => '',
          'custom' => 'Green',
        ],
        'X:Bob Y:Green',
      ],
      [
        [
          'source' => 'Bake Sale',
          'setting_field' => 'X:{contact.first_name} Y:{contribution.custom_%%id%%}',
          'setting_label' => 'W: ',
          'custom' => 'Green',
        ],
        'W: X:Bob Y:Green',
      ],
    ];
  }

}
