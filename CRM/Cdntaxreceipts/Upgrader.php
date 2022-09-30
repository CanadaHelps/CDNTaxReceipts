<?php

use CRM_Cdntaxreceipts_ExtensionUtil as E;

/**
 * Collection of upgrade steps
 */
class CRM_Cdntaxreceipts_Upgrader extends CRM_Cdntaxreceipts_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Example: Run an external SQL script when the module is installed.
   */
  public function install() {
    $this->createTables();

    $email_message = '{$contact.email_greeting_display},

Attached please find your official tax receipt for income tax purposes.

{$orgName}';
    $email_subject = 'Your tax receipt {$receipt.receipt_no}';

    $this->_create_message_template($email_message, $email_subject);
    $this->_setSourceDefaults();
  }

  /**
   * Example: Run an external SQL script when the module is uninstalled.
   */
  public function uninstall() {
    $this->executeSqlFile('sql/uninstall.sql');
  }

  /**
   * Get the character set and collation that the core CiviCRM tables are
   * currently using.
   * @return array
   */
  private function getDatabaseCharacterSettings():array {
    $values = [
      'charset' => 'utf8',
      'collation' => 'utf8_unicode_ci',
    ];
    // This doesn't exist before 5.29. Not worth implementing ourselves, just
    // use defaults above.
    if (method_exists('CRM_Core_BAO_SchemaHandler', 'getInUseCollation')) {
      $values['collation'] = CRM_Core_BAO_SchemaHandler::getInUseCollation();
      if (stripos($values['collation'], 'utf8mb4') !== FALSE) {
        $values['charset'] = 'utf8mb4';
      }
    }
    return $values;
  }

  /**
   * Create the tables.
   *
   * changes made in:
   *   0.9.beta1
   *   1.5.4 - use same character set that core tables are currently using
   *
   * NOTE: We avoid direct foreign keys to CiviCRM schema because this log should
   * remain intact even if a particular contact or contribution is deleted (for
   * auditing purposes).
   */
  protected function createTables() {
    $character_settings = $this->getDatabaseCharacterSettings();

    CRM_Core_DAO::executeQuery("CREATE TABLE cdntaxreceipts_log (
id int(11) NOT NULL AUTO_INCREMENT COMMENT 'The internal id of the issuance.',
receipt_no varchar(128) NOT NULL  COMMENT 'Receipt Number.',
issued_on int(11) NOT NULL COMMENT 'Unix timestamp of when the receipt was issued, or re-issued.',
contact_id int(10) unsigned NOT NULL COMMENT 'CiviCRM contact id to whom the receipt is issued.',
receipt_amount decimal(10,2) NOT NULL COMMENT 'Receiptable amount, total minus non-receiptable portion.',
is_duplicate tinyint(4) NOT NULL COMMENT 'Boolean indicating whether this is a re-issue.',
uid int(10) unsigned NOT NULL COMMENT 'Drupal user id of the person issuing the receipt.',
ip varchar(128) NOT NULL COMMENT 'IP of the user who issued the receipt.',
issue_type varchar(16) NOT NULL COMMENT 'The type of receipt (single or annual).',
issue_method varchar(16) NULL COMMENT 'The send method (email or print).',
receipt_status varchar(10) DEFAULT 'issued' COMMENT 'The status of the receipt (issued or cancelled)',
email_tracking_id varchar(64) NULL COMMENT 'A unique id to track email opens.',
email_opened datetime NULL COMMENT 'Timestamp an email open event was detected.',
PRIMARY KEY (id),
INDEX contact_id (contact_id),
INDEX receipt_no (receipt_no)
) ENGINE=InnoDB DEFAULT CHARSET={$character_settings['charset']} COLLATE {$character_settings['collation']} COMMENT='Log file of tax receipt issuing.'");

    // The contribution_id is *deliberately* not a foreign key to civicrm_contribution.
    // We don't want to destroy audit records if contributions are deleted.
    CRM_Core_DAO::executeQuery("CREATE TABLE cdntaxreceipts_log_contributions (
id int(11) NOT NULL AUTO_INCREMENT COMMENT 'The internal id of this line.',
receipt_id int(11) NOT NULL COMMENT 'The internal receipt ID this line belongs to.',
contribution_id int(10) unsigned NOT NULL COMMENT 'CiviCRM contribution id for which the receipt is issued.',
contribution_amount decimal(10,2) DEFAULT NULL COMMENT 'Total contribution amount.',
receipt_amount decimal(10,2) NOT NULL COMMENT 'Receiptable amount, total minus non-receiptable portion.',
receive_date datetime NOT NULL COMMENT 'Date on which the contribution was received, redundant information!',
PRIMARY KEY (id),
FOREIGN KEY (receipt_id) REFERENCES cdntaxreceipts_log(id),
INDEX contribution_id (contribution_id)
) ENGINE=InnoDB DEFAULT CHARSET={$character_settings['charset']} COLLATE {$character_settings['collation']} COMMENT='Contributions for each tax receipt issuing.'");

    CRM_Core_DAO::executeQuery("CREATE TABLE cdntaxreceipts_advantage (
id int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
contribution_id int(10) UNSIGNED NOT NULL,
advantage_description varchar(255) DEFAULT NULL,
PRIMARY KEY (id),
INDEX contribution_id (contribution_id)
) ENGINE=InnoDB DEFAULT CHARSET={$character_settings['charset']} COLLATE {$character_settings['collation']}");
  }

  /**
   * @TODO This function is buggy - it returns false when the field already
   * exists. Also the entire function could just be replaced with CRM_Upgrade...addColumn().
   */
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
        if ($ndao->fetch()) {
          if ($ndao->col_count == 1) {
            return TRUE;
          }
        }
      }
    }
    return FALSE;
  }

  /**
   * @TODO replace with CRM_Upgrade...addColumn and also there's one called
   * safeIndex() or something like that.
   */
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

  /**
   * Update uploaded file paths to be relative instead of absolute.
   */
  public function upgrade_1411() {
    $this->ctx->log->info('Applying update 1411: uploaded file paths');
    foreach (array('receipt_logo', 'receipt_signature', 'receipt_watermark', 'receipt_pdftemplate') as $fileSettingName) {
      $path = Civi::settings()->get($fileSettingName);
      if (!empty($path)) {
        Civi::settings()->set($fileSettingName, basename($path));
      }
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
    $financialType = new CRM_Financial_DAO_FinancialType();
    $financialType->name = 'In-kind';

    if ($financialType->find(TRUE)) {
      try {
        CRM_Cdntaxreceipts_Upgrader::createDefaultFinancialAccounts($financialType);
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

  public function upgrade_1413() {
    $this->_setSourceDefaults();
    return TRUE;
  }

  public function _create_message_template($email_message, $email_subject) {

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

  private function _setSourceDefaults() {
    \Civi::settings()->set('cdntaxreceipts_source_field', '{contribution.source}');
    $locales = CRM_Core_I18n::getMultilingual();
    if ($locales) {
      foreach ($locales as $locale) {
        // The space in "Source: " is not a typo.
        \Civi::settings()->set('cdntaxreceipts_source_label_' . $locale, ts('Source: ', array('domain' => 'org.civicrm.cdntaxreceipts')));
      }
    }
    else {
      // The space in "Source: " is not a typo.
      \Civi::settings()->set('cdntaxreceipts_source_label_' . CRM_Core_I18n::getLocale(), ts('Source: ', array('domain' => 'org.civicrm.cdntaxreceipts')));
    }
  }

  /**
   * Copied core function CRM_Financial_BAO_FinancialTypeAccount::createDefaultFinancialAccounts() to get rid of Cost of Sale GL account mapping with Fund
   * (this was in the civix file, moved here by ML)
   */
  public static function createDefaultFinancialAccounts($financialType) {
    $titles = [];
    $financialAccountTypeID = CRM_Core_OptionGroup::values('financial_account_type', FALSE, FALSE, FALSE, NULL, 'name');
    $accountRelationship    = CRM_Core_OptionGroup::values('account_relationship', FALSE, FALSE, FALSE, NULL, 'name');

    $relationships = [
      array_search('Accounts Receivable Account is', $accountRelationship) => array_search('Asset', $financialAccountTypeID),
      array_search('Expense Account is', $accountRelationship) => array_search('Expenses', $financialAccountTypeID),
      array_search('Income Account is', $accountRelationship) => array_search('Revenue', $financialAccountTypeID),
    ];

    $dao = CRM_Core_DAO::executeQuery('SELECT id, financial_account_type_id FROM civicrm_financial_account WHERE name LIKE %1',
      [1 => [$financialType->name, 'String']]
    );
    $dao->fetch();
    $existingFinancialAccount = [];
    if (!$dao->N) {
      $params = [
        'name' => $financialType->name,
        'contact_id' => CRM_Core_DAO::getFieldValue('CRM_Core_DAO_Domain', CRM_Core_Config::domainID(), 'contact_id'),
        'financial_account_type_id' => array_search('Revenue', $financialAccountTypeID),
        'description' => $financialType->description,
        'account_type_code' => 'INC',
        'accounting_code' => '4300',
        'is_active' => 1,
      ];
      $financialAccount = CRM_Financial_BAO_FinancialAccount::add($params);
    }
    else {
      $existingFinancialAccount[$dao->financial_account_type_id] = $dao->id;
    }
    $params = [
      'entity_table' => 'civicrm_financial_type',
      'entity_id' => $financialType->id,
    ];
    foreach ($relationships as $key => $value) {
      if (!array_key_exists($value, $existingFinancialAccount)) {
        if ($accountRelationship[$key] == 'Accounts Receivable Account is') {
          $params['financial_account_id'] = CRM_Core_DAO::getFieldValue('CRM_Financial_DAO_FinancialAccount', 'Accounts Receivable', 'id', 'name');
          if (!empty($params['financial_account_id'])) {
            $titles[] = 'Accounts Receivable';
          }
          else {
            $query = "SELECT financial_account_id, name FROM civicrm_entity_financial_account
            LEFT JOIN civicrm_financial_account ON civicrm_financial_account.id = civicrm_entity_financial_account.financial_account_id
            WHERE account_relationship = {$key} AND entity_table = 'civicrm_financial_type' LIMIT 1";
            $dao = CRM_Core_DAO::executeQuery($query);
            $dao->fetch();
            $params['financial_account_id'] = $dao->financial_account_id;
            $titles[] = $dao->name;
          }
        }
        elseif ($accountRelationship[$key] == 'Income Account is' && empty($existingFinancialAccount)) {
          $params['financial_account_id'] = $financialAccount->id;
        }
        else {
          $query = "SELECT id, name FROM civicrm_financial_account WHERE is_default = 1 AND financial_account_type_id = {$value}";
          $dao = CRM_Core_DAO::executeQuery($query);
          $dao->fetch();
          $params['financial_account_id'] = $dao->id;
          $titles[] = $dao->name;
        }
      }
      else {
        $params['financial_account_id'] = $existingFinancialAccount[$value];
        $titles[] = $financialType->name;
      }
      $params['account_relationship'] = $key;
      CRM_Financial_BAO_FinancialTypeAccount::add($params);
    }
    if (!empty($existingFinancialAccount)) {
      $titles = [];
    }
    return $titles;
  }
}
