<?php

use CRM_Cdntaxreceipts_ExtensionUtil as E;

/**
 * Collection of upgrade steps
 */
class CRM_Cdntaxreceipts_Upgrader extends CRM_Cdntaxreceipts_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Run the fresh install script when the module is installed
   */
  public function install() {
    $this->executeSqlFile('sql/install.sql');

    $email_message = '{$contact.email_greeting_display},

Attached please find your official tax receipt for income tax purposes.

{$orgName}';
    $email_subject = 'Your tax receipt {$receipt.receipt_no}';

    $this->_create_message_template($email_message, $email_subject);
  }

  /**
   * Run the uninstall script when the module is uninstalled
   */
  public function uninstall() {
    $this->executeSqlFile('sql/uninstall.sql');
  }

  public function upgrade_1320() {
    $this->ctx->log->info('Applying update 1.3.2');
    $dao =& CRM_Core_DAO::executeQuery("SELECT 1");
    $db_name = $dao->_database;
    $dao =& CRM_Core_DAO::executeQuery("
SELECT COUNT(*) as col_count
FROM information_schema.COLUMNS
WHERE
    TABLE_SCHEMA = '{$db_name}'
AND TABLE_NAME = 'cdntaxreceipts_log'
AND COLUMN_NAME = 'receipt_status'");
   if ($dao->fetch()) {
     if ($dao->col_count == 0) {
       CRM_Core_DAO::executeQuery("ALTER TABLE cdntaxreceipts_log ADD COLUMN receipt_status varchar(10) DEFAULT 'issued'");
       $ndao =& CRM_Core_DAO::executeQuery("
SELECT COUNT(*) as col_count
FROM information_schema.COLUMNS
WHERE
    TABLE_SCHEMA = '{$db_name}'
AND TABLE_NAME = 'cdntaxreceipts_log'
AND COLUMN_NAME = 'receipt_status'");
       if($ndao->fetch()) {
         if ($ndao->col_count == 1) {
           return TRUE;
         }
       }
     }
   }
    return FALSE;
  }

  public function upgrade_1321() {
    $this->ctx->log->info('Applying update 1321: Email Tracking');
    CRM_Core_DAO::executeQuery('ALTER TABLE cdntaxreceipts_log ADD email_tracking_id varchar(64) NULL');
    CRM_Core_DAO::executeQuery('ALTER TABLE cdntaxreceipts_log ADD email_opened datetime NULL');
    CRM_Core_DAO::executeQuery('CREATE INDEX contribution_id ON cdntaxreceipts_log_contributions (contribution_id)');
    return TRUE;
  }

  public function upgrade_1322() {
    $this->ctx->log->info('Applying update 1322: Message Templates');
    $current_message = Civi::settings()->get('email_message');
    $current_subject = Civi::settings()->get('email_subject') . ' {$receipt.receipt_no}';
    return $this->_create_message_template($current_message, $current_subject);
  }

  public function upgrade_1410() {
    $this->ctx->log->info('Applying update 1410: Data mode');
    $email_enabled = Civi::settings()->get('enable_email');
    if ($email_enabled) {
      Civi::settings()->set('delivery_method', 1);
    }
    else {
      Civi::settings()->set('delivery_method', 0);
    }
    return TRUE;
  }

  public function upgrade_1510() {
    $this->ctx->log->info('Applying update 1510: Adding gift advantage description table');
    $sql = "CREATE TABLE IF NOT EXISTS cdntaxreceipts_advantage (
      id int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
      contribution_id int(10) UNSIGNED NOT NULL,
      advantage_description varchar(255) DEFAULT NULL,
      PRIMARY KEY (id),
      INDEX contribution_id (contribution_id)
    )";
    CRM_Core_DAO::executeQuery($sql);
    return TRUE;
  }

  public function upgrade_1511() {
    $this->ctx->log->info('Applying update 1511: adding missing financial accounts to "In-Kind" fund');

    // add missing GL account to In-kind fund
    require_once 'CRM/Financial/DAO/FinancialType.php';
    $financialType = new CRM_Financial_DAO_FinancialType();
    $financialType->name = 'In-kind';

    if ($financialType->find(TRUE)) {
      try {
        E::createDefaultFinancialAccounts($financialType);
      }
      catch (Exception $e) {
      }
      // Set the GL Account code to match master
      $revenueAccountTypeID = array_search('Revenue', CRM_Core_OptionGroup::values('financial_account_type', FALSE, FALSE, FALSE, NULL, 'name'));
      if ($revenueAccountTypeID) {
        CRM_Core_DAO::executeQuery("UPDATE civicrm_financial_account fa
          INNER JOIN civicrm_entity_financial_account efa ON efa.financial_account_id = fa.id
          SET fa.accounting_code = '4300'
          WHERE efa.entity_table = 'civicrm_financial_type' AND fa.financial_account_type_id = %1 AND efa.entity_id = %2", [
          1 => [$revenueAccountTypeID, 'Positive'],
          2 => [$financialType->id, 'Positive'],
        ]);
      }
    }
    else {
      // Create Inkind financial type and fields
      cdntaxreceipts_configure_inkind_fields();
    }

    return TRUE;
  }

  public function upgrade_1512() {
    $this->ctx->log->info('Applying update 1512: renaming in-kind to In Kind');
    // add missing GL account to In-kind fund
    require_once 'CRM/Financial/DAO/FinancialType.php';
    $financialType = new CRM_Financial_DAO_FinancialType();
    $financialType->name = 'In-kind';
    if ($financialType->find(TRUE)) {
      $financialType->name = 'In Kind';
      $financialType->save();
    }
    $customGroup = new CRM_Core_DAO_CustomGroup();
    $customGroup->title = 'In-kind donation fields';
    if ($customGroup->find(TRUE)) {
      $customGroup->title = 'In Kind donation fields';
      $customGroup->save();
    }
    $financialAccount = new CRM_Financial_DAO_FinancialAccount();
    $financialAccount->name = 'In-kind Donation';
    if ($financialAccount->find(TRUE)) {
      $financialAccount->name = 'In Kind Donation';
      $financialAccount->save();
    }
    $financialAccount->name = 'In-kind';
    if ($financialAccount->find(TRUE)) {
      $financialAccount->name = 'In Kind';
      $financialAccount->save();
    }
    $financialType = new CRM_Financial_DAO_FinancialType();
    $financialType->name = 'In Kind';
    $financialType->find(TRUE);
    $query = CRM_Core_DAO::executeQuery("SELECT id
      FROM civicrm_financial_account
      WHERE id NOT IN (SELECT financial_account_id FROM civicrm_entity_financial_account WHERE entity_table = 'civicrm_financial_type' AND entity_id = %1)
      AND name like '%In Kind%'", [1 => [$financialType->id, 'Positive']]);
    while ($query->fetch()) {
      if (!empty($query->id)) {
        civicrm_api3('FinancialAccount', 'delete', ['id' => $query->id]);
      }
    }
    return TRUE;
  }

  function _create_message_template($email_message, $email_subject) {

    $html_message = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
 <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
 <title></title>
</head>
<body>
{capture assign=headerStyle}colspan="2" style="text-align: left; padding: 4px; border-bottom: 1px solid #999; background-color: #eee;"{/capture}
{capture assign=labelStyle }style="padding: 4px; border-bottom: 1px solid #999; background-color: #f7f7f7;"{/capture}
{capture assign=valueStyle }style="padding: 4px; border-bottom: 1px solid #999;"{/capture}

<center>
 <table width="620" border="0" cellpadding="0" cellspacing="0" style="font-family: Arial, Verdana, sans-serif; text-align: left;">

  <!-- BEGIN HEADER -->
  <!-- You can add table row(s) here with logo or other header elements -->
  <!-- END HEADER -->

  <!-- BEGIN CONTENT -->

  <tr>
   <td>
    <p>' . nl2br(htmlspecialchars($email_message)) . '</p>
   </td>
  </tr>
  <tr>
 </table>
</center>
{$openTracking}
</body>
</html>';

    // create message template for email that accompanies tax receipts
    $params = array(
      'sequential' => 1,
      'name' => 'msg_tpl_workflow_cdntaxreceipts',
      'title' => 'Message Template Workflow for CDN Tax Receipts',
      'description' => 'Message Template Workflow for CDN Tax Receipts',
      'is_reserved' => 1,
      'is_active' => 1,
      'api.OptionValue.create' => array(
        '0' => array(
          'label' => 'CDN Tax Receipts - Email Single Receipt',
          'value' => 1,
          'name' => 'cdntaxreceipts_receipt_single',
          'is_reserved' => 1,
          'is_active' => 1,
          'format.only_id' => 1,
        ),
        '1' => array(
          'label' => 'CDN Tax Receipts - Email Annual/Aggregate Receipt',
          'value' => 2,
          'name' => 'cdntaxreceipts_receipt_aggregate',
          'is_reserved' => 1,
          'is_active' => 1,
          'format.only_id' => 1,
        ),
      ),
    );
    $result = civicrm_api3('OptionGroup', 'create', $params);

    $params = array(
      'msg_title' => 'CDN Tax Receipts - Email Single Receipt',
      'msg_subject' => $email_subject,
      'msg_text' => $email_message,
      'msg_html' => $html_message,
      'workflow_id' => $result['values'][0]['api.OptionValue.create'][0],
      'is_default' => 1,
      'is_reserved' => 0,
    );
    civicrm_api3('MessageTemplate', 'create', $params);

    $params = array(
      'msg_title' => 'CDN Tax Receipts - Email Annual/Aggregate Receipt',
      'msg_subject' => $email_subject,
      'msg_text' => $email_message,
      'msg_html' => $html_message,
      'workflow_id' => $result['values'][0]['api.OptionValue.create'][1],
      'is_default' => 1,
      'is_reserved' => 0,
    );
    civicrm_api3('MessageTemplate', 'create', $params);

    return TRUE;
  }

  /**
   * Example: Run an external SQL script
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4201() {
    $this->ctx->log->info('Applying update 4201');
    // this path is relative to the extension base dir
    $this->executeSqlFile('sql/upgrade_4201.sql');
    return TRUE;
  } // */

}
