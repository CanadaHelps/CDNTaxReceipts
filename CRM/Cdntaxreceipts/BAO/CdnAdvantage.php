<?php
use CRM_Cdntaxreceipts_ExtensionUtil as E;

class CRM_Cdntaxreceipts_BAO_CdnAdvantage extends CRM_Cdntaxreceipts_DAO_CdnAdvantage {

  /**
   * Create a new CdnAdvantage based on array-data
   *
   * @param array $params key-value pairs
   * @return CRM_Cdntaxreceipts_DAO_CdnAdvantage|NULL
   *
  public static function create($params) {
    $className = 'CRM_Cdntaxreceipts_DAO_CdnAdvantage';
    $entityName = 'CdnAdvantage';
    $hook = empty($params['id']) ? 'create' : 'edit';

    CRM_Utils_Hook::pre($hook, $entityName, CRM_Utils_Array::value('id', $params), $params);
    $instance = new $className();
    $instance->copyValues($params);
    $instance->save();
    CRM_Utils_Hook::post($hook, $entityName, $instance->id, $instance);

    return $instance;
  } */

}
