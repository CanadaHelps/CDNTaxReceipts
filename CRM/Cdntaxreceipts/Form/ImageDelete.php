<?php

use CRM_Cdntaxreceipts_ExtensionUtil as E;

/**
 * Callback for deleting template images
 */
class CRM_Cdntaxreceipts_Form_ImageDelete extends CRM_Core_Form {

  public function buildQuickForm() {
    parent::buildQuickForm();

    $type = CRM_Utils_Request::retrieve('type', 'String', $this);
    if (empty($type)) {
      CRM_Core_Error::statusBounce(E::ts('Missing type'));
      return;
    }

    $typeLabels = $this->getTypeLabels();
    if (!isset($typeLabels[$type])) {
      CRM_Core_Error::statusBounce(E::ts('Unknown type'));
      return;
    }

    CRM_Utils_System::setTitle(E::ts('Delete Image'));

    $this->add('hidden', 'imagetype', $type);
    $this->assign('imagetype', $typeLabels[$type]);

    $this->addButtons(array(
      array(
        'type' => 'submit',
        'name' => E::ts('Delete'),
        'isDefault' => TRUE,
      ),
      array(
        'type' => 'cancel',
        'name' => E::ts('Cancel'),
      ),
    ));
  }

  public function postProcess() {
    parent::postProcess();

    $type = $this->exportValues()['imagetype'];
    if (empty($type)) {
      CRM_Core_Error::statusBounce(E::ts('Missing type'));
      return;
    }

    $filename = Civi::settings()->get($type);
    if (empty($filename)) {
      CRM_Core_Error::statusBounce(E::ts('Setting does not exist'));
      return;
    }

    $filename = CRM_Core_Config::singleton()->customFileUploadDir . $filename;
    if (file_exists($filename)) {
      Civi::settings()->set($type, NULL);
      unlink($filename);
    }

    CRM_Core_Session::setStatus(E::ts('The %1 file has been deleted.', array(1 => basename($filename))), '', 'success');
    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/cdntaxreceipts/settings', 'reset=1'));
  }

  /**
   * Needs to be a function since we need to use ts().
   * @return array
   */
  private function getTypeLabels(): array {
    return array(
      'receipt_logo' => E::ts('Logo'),
      'receipt_signature' => E::ts('Signature'),
      'receipt_watermark' => E::ts('Watermark'),
      'receipt_pdftemplate' => E::ts('PDF Template'),
    );
  }

}
