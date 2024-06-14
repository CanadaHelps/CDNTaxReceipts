<?php
use CRM_Cdntaxreceipts_ExtensionUtil as E;

define('CDNTAXRECEIPTS_API_NOTFOUND', 1);
define('CDNTAXRECEIPTS_API_INELIGIBLE', 2);
define('CDNTAXRECEIPTS_API_NOEMAIL', 3);
define('CDNTAXRECEIPTS_API_NOTSENT', 4);
define('CDNTAXRECEIPTS_API_LOGMISSING', 5);

/**
 * Cdntaxreceipts.Generate API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_cdntaxreceipts_Generate_spec(&$spec) {
  $spec['contribution_id']['api.required'] = TRUE;
}

/**
 * Cdntaxreceipts.Generate API
 *
 * @param array $params
 *
 * @return array
 *   API result descriptor
 *
 * @see civicrm_api3_create_success
 *
 * @throws API_Exception
 */
function civicrm_api3_cdntaxreceipts_Generate($params) {
  $contribution = new CRM_Contribute_DAO_Contribution();
  $contribution->id = $params['contribution_id'];
  $contribution->find(TRUE);

  if ($contribution->N === 0) {
    return civicrm_api3_create_error('Contribution ID not found.', ['error_code' => CDNTAXRECEIPTS_API_NOTFOUND]);
  }
  if (!cdntaxreceipts_eligibleForReceipt($contribution->id)) {
    return civicrm_api3_create_error('Contribution not eligible.', ['error_code' => CDNTAXRECEIPTS_API_INELIGIBLE]);
  }
  // We don't support printing, so make sure they have an email.
  $email = new CRM_Core_DAO_Email();
  $email->contact_id = $contribution->contact_id;
  $email->on_hold = 0;
  $email->find();
  if ($email->N === 0) {
    return civicrm_api3_create_error('Contact does not have a valid email.', ['error_code' => CDNTAXRECEIPTS_API_NOEMAIL]);
  }
  $nullValue = NULL;
  [$sent] = cdntaxreceipts_issueTaxReceipt($contribution, $nullValue, CDNTAXRECEIPTS_MODE_API);
  if ($sent) {
    // @todo There was some suggestion of providing true DAO support for these log tables. When that happens this could be converted.
    $log = CRM_Core_DAO::executeQuery("SELECT lc.*, l.* FROM cdntaxreceipts_log_contributions lc INNER JOIN cdntaxreceipts_log l ON l.id = lc.receipt_id WHERE lc.contribution_id = %1", [1 => [$contribution->id, 'Integer']]);
    if ($log->fetch()) {
      return civicrm_api3_create_success([$log->id => $log->toArray()], $params, 'Cdntaxreceipts', 'Generate');
    }
    else {
      return civicrm_api3_create_error('Unable to find/create log entry.', ['error_code' => CDNTAXRECEIPTS_API_LOGMISSING]);
    }
  }
  return civicrm_api3_create_error('Error while sending email.', ['error_code' => CDNTAXRECEIPTS_API_NOTSENT]);
}
