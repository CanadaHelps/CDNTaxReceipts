<?php
// This file declares a managed database record of type "ReportTemplate".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate. For more details, see "hook_civicrm_managed" at:
// http://wiki.civicrm.org/confluence/display/CRMDOC42/Hook+Reference
return array (
  0 => 
  array (
    'name' => 'CRM_Cdntaxreceipts_Form_Report_ReceiptsNotIssued',
    'entity' => 'ReportTemplate',
    'params' => 
    array (
      'version' => 3,
      'label' => 'Tax Receipts (Not Yet Issued)',
      'description' => 'All Tax Receipts not yet Issued',
      'class_name' => 'CRM_Cdntaxreceipts_Form_Report_ReceiptsNotIssued',
      'report_url' => 'cdntaxreceipts/receiptsnotissued',
      'component' => 'CiviContribute',
    ),
  ),
);
