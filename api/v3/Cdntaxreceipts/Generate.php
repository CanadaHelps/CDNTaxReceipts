<?php
use CRM_Cdntaxreceipts_ExtensionUtil as E;

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
    return civicrm_api3_create_error('Contribution ID not found.');
  }
  if (!cdntaxreceipts_eligibleForReceipt($contribution->id)) {
    return civicrm_api3_create_success(FALSE, $params, 'Cdntaxreceipts', 'Generate');
  }
  // We don't support printing, so make sure they have an email.
  $email = new CRM_Core_DAO_Email();
  $email->contact_id = $contribution->contact_id;
  $email->on_hold = 0;
  $email->find();
  if ($email->N === 0) {
    return civicrm_api3_create_error('Contact does not have a valid email.');
  }
  $nullValue = NULL;
  [$sent] = cdntaxreceipts_issueTaxReceipt($contribution, $nullValue, CDNTAXRECEIPTS_MODE_API);
  return civicrm_api3_create_success($sent, $params, 'Cdntaxreceipts', 'Generate');
}
