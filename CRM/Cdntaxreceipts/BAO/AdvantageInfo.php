<?php
use CRM_Cdntaxreceipts_ExtensionUtil as E;

class CRM_Cdntaxreceipts_BAO_AdvantageInfo extends CRM_Cdntaxreceipts_DAO_AdvantageInfo {

  /**
   * Create a new AdvantageInfo based on array-data
   *
   * @param array $params key-value pairs
   * @return CRM_Cdntaxreceipts_DAO_AdvantageInfo|NULL
   */
  public static function create($params) {
    $className = 'CRM_Cdntaxreceipts_DAO_AdvantageInfo';
    $entityName = 'AdvantageInfo';
    $hook = empty($params['id']) ? 'create' : 'edit';

    CRM_Utils_Hook::pre($hook, $entityName, CRM_Utils_Array::value('id', $params), $params);
    $instance = new $className();
    $instance->copyValues($params);
    $instance->save();

    if (!empty($params['custom']) &&
      is_array($params['custom'])
    ) {
      CRM_Core_BAO_CustomValueTable::store($params['custom'], self::$_tableName, $instance->id);
    }

    CRM_Utils_Hook::post($hook, $entityName, $instance->id, $instance);

    return $instance;
  }

}
