<?php

/**
 * @group headless
 */
class CRM_Cdntaxreceipts_AggregateTest extends CRM_Cdntaxreceipts_Base {

  public function tearDown(): void {
    $this->_tablesToTruncate = [
      'civicrm_contact',
    ];
    $this->quickCleanUpFinancialEntities();
    parent::tearDown();
  }

  /**
   * Test aggregate receipts.
   * @dataProvider aggregateProvider
   * @param array $input
   * @param array $expected
   */
  public function testAggregate(array $input, array $expected) {
    // set up mock time
    $mock_time = '2021-01-02 10:11:12';
    \CRM_Cdntaxreceipts_Utils_Time::setTime($mock_time);

    $this->setDeliveryMethod(CDNTAX_DELIVERY_PRINT_ONLY);

    // create some contributions based on input
    $contact[1] = $this->individualCreate([], 1);
    $contact[2] = $this->individualCreate([], 2);
    $createdContributions = [];
    foreach ($input['contributions'] as $contribution) {
      // note we just store the id of the result
      $createdContributions[] = $this->callAPISuccess('Contribution', 'create', [
        'contact_id' => $contact[$contribution['contact_index']],
        'financial_type_id' => $contribution['financial_type'],
        'total_amount' => $contribution['total_amount'],
        'receive_date' => $contribution['receive_date'],
      ])['id'];
    }

    // issue some receipts for the totals
    $receiptsForPrintingPDF = cdntaxreceipts_openCollectedPDF();
    $counter = 0;
    foreach ($input['grouped'] as $contact_index => $contributionList) {
      // update some things we don't know at dataprovider time
      foreach ($contributionList as $loop_index => $c) {
        $input['grouped'][$contact_index][$loop_index]['contribution_id'] = $createdContributions[$counter++];
        $input['grouped'][$contact_index][$loop_index]['contact_id'] = $contact[$contact_index];
      }

      // issue receipt for this contact
      $status = cdntaxreceipts_issueAggregateTaxReceipt(
        $contact[$contact_index],
        '2020',
        $input['grouped'][$contact_index],
        'print',
        $receiptsForPrintingPDF,
        FALSE
      );
      $this->assertEquals([
        0 => TRUE,
        1 => 'print',
        2 => NULL,
      ], $status);
    }

    // check logs
    $records = \CRM_Core_DAO::executeQuery("SELECT * FROM cdntaxreceipts_log")->fetchAll();
    // There's other fields we either can't reliably know or don't care about,
    // but the expected should match a subset of them.
    foreach ($expected as $eid => $expectedContributionLog) {
      $realExpected = array(
        'issued_on' => (string) strtotime($mock_time),
        'contact_id' => (string) $contact[$expectedContributionLog['contact_index']],

        'id' => $expectedContributionLog['id'],
        'receipt_no' => $expectedContributionLog['receipt_no'],
        'receipt_amount' => $expectedContributionLog['receipt_amount'],
        'is_duplicate' => $expectedContributionLog['is_duplicate'],
        'issue_type' => $expectedContributionLog['issue_type'],
        'issue_method' => $expectedContributionLog['issue_method'],
        'receipt_status' => $expectedContributionLog['receipt_status'],
      );
      $intersect = array_intersect_key($records[$eid], $realExpected);
      $this->assertNotEmpty($intersect);
      $this->assertEquals($realExpected, $intersect);
    }

    \CRM_Cdntaxreceipts_Utils_Time::reset();
  }

  /**
   * Dataprovider for testAggregate.
   * @return array
   */
  public function aggregateProvider(): array {
    return [
      0 => [
        'input' => [
          'contributions' => [
            [
              'contact_index' => 1,
              'financial_type' => 'Donation',
              'total_amount' => '10',
              'receive_date' => '2020-01-01',
            ],
            [
              'contact_index' => 1,
              'financial_type' => 'Donation',
              'total_amount' => '20',
              'receive_date' => '2020-02-01',
            ],
            [
              'contact_index' => 2,
              'financial_type' => 'Donation',
              'total_amount' => '40',
              'receive_date' => '2020-03-01',
            ],
          ],
          // Index here is the contact index
          'grouped' => [
            1 => [
              [
                'total_amount' => '10',
                'non_deductible_amount' => 0.0,
                'receive_date' => '2020-01-01',
                'receive_year' => '2020',
                // Note at the time of writing this gets recalculated anyway
                // by processTaxReceipt so it doesn't matter what we put here.
                'eligible' => TRUE,
                'receipt_id' => '0',
              ],
              [
                'total_amount' => '20',
                'non_deductible_amount' => 0.0,
                'receive_date' => '2020-02-01',
                'receive_year' => '2020',
                'eligible' => TRUE,
                'receipt_id' => '0',
              ],
            ],
            2 => [
              [
                'total_amount' => '40',
                'non_deductible_amount' => 0.0,
                'receive_date' => '2020-03-01',
                'receive_year' => '2020',
                'eligible' => TRUE,
                'receipt_id' => '0',
              ],
            ],
          ],
        ],
        'expected' => [
          [
            'id' => '1',
            'contact_index' => 1,
            'receipt_no' => 'C-00000001',
            'receipt_amount' => '30.00',
            'is_duplicate' => '0',
            'issue_type' => 'aggregate',
            'issue_method' => 'print',
            'receipt_status' => 'issued',
          ],
          [
            'id' => '2',
            'contact_index' => 2,
            'receipt_no' => 'C-00000003',
            'receipt_amount' => '40.00',
            'is_duplicate' => '0',
            'issue_type' => 'aggregate',
            'issue_method' => 'print',
            'receipt_status' => 'issued',
          ],
        ],
      ],

      // Same as 0 but one is an Event Fee which should get excluded.
      1 => [
        'input' => [
          'contributions' => [
            [
              'contact_index' => 1,
              'financial_type' => 'Donation',
              'total_amount' => '10',
              'receive_date' => '2020-01-01',
            ],
            [
              'contact_index' => 1,
              'financial_type' => 'Event Fee',
              'total_amount' => '20',
              'receive_date' => '2020-02-01',
            ],
            [
              'contact_index' => 2,
              'financial_type' => 'Donation',
              'total_amount' => '40',
              'receive_date' => '2020-03-01',
            ],
          ],
          // Index here is the contact index
          'grouped' => [
            1 => [
              [
                'total_amount' => '10',
                'non_deductible_amount' => 0.0,
                'receive_date' => '2020-01-01',
                'receive_year' => '2020',
                'eligible' => TRUE,
                'receipt_id' => '0',
              ],
              [
                'total_amount' => '20',
                'non_deductible_amount' => 0.0,
                'receive_date' => '2020-02-01',
                'receive_year' => '2020',
                // see note above - this gets ignored and recalculated
                'eligible' => FALSE,
                'receipt_id' => '0',
              ],
            ],
            2 => [
              [
                'total_amount' => '40',
                'non_deductible_amount' => 0.0,
                'receive_date' => '2020-03-01',
                'receive_year' => '2020',
                'eligible' => TRUE,
                'receipt_id' => '0',
              ],
            ],
          ],
        ],
        'expected' => [
          [
            'id' => '1',
            'contact_index' => 1,
            'receipt_no' => 'C-00000001',
            'receipt_amount' => '10.00',
            'is_duplicate' => '0',
            'issue_type' => 'aggregate',
            'issue_method' => 'print',
            'receipt_status' => 'issued',
          ],
          [
            'id' => '2',
            'contact_index' => 2,
            'receipt_no' => 'C-00000003',
            'receipt_amount' => '40.00',
            'is_duplicate' => '0',
            'issue_type' => 'aggregate',
            'issue_method' => 'print',
            'receipt_status' => 'issued',
          ],
        ],
      ],
    ];
  }

}
