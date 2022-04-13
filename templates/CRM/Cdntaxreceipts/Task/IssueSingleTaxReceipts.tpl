{* Confirmation of tax receipts  *}
<div class="crm-block crm-content-block crm-contribution-view-form-block">
  <h3>Receipts Details</h3>
  <table class="crm-info-panel">
    <tr>
      <td class="label bold-text">{ts domain='org.civicrm.cdntaxreceipts'}You have selected <strong>{$totalSelectedContributions}</strong> contributions. Of these, <strong>{$receiptTotal}</strong> are eligible to receive tax receipts.{/ts}</td>
      <td></td><td></td><td></td>
    </tr>
  </table>
  <table class="crm-info-panel border-top-td crm-stripes-tr">
    <tr>
      <td class="label bold-weight">{ts}Not Yet Receipted{/ts}</td>
      <td class="label">{$originalTotal}</td>
      <td class="label display-cell-padding bold-weight">{ts}Already Receipted{/ts}</td>
      <td class="label">{$duplicateTotal}</td>
      <td></td>
    </tr>
  </table>
</div>
<div class="crm-block crm-content-block crm-contribution-thank-you-block">
  <h3>{ts domain='org.civicrm.cdntaxreceipts'}Thank You Settings{/ts}</h3>
  <table class="crm-info-panel">
    <tr>
      <td class="content">{$form.thankyou_date.html}</td>
      <td class="label">{$form.thankyou_date.label}</td>
    </tr>
    <tr>
      <td class="content">{$form.thankyou_email.html}</td>
      <td class="label">{$form.thankyou_email.label}</td>
    </tr>
    {include file="CRM/Cdntaxreceipts/Task/PDFLetterCommon.tpl"}
  </table>
</div>
<div class="crm-block crm-content-block">
  <h3>{ts domain='org.civicrm.cdntaxreceipts'}Table of Users{/ts}</h3>
  {include file="CRM/Cdntaxreceipts/Task/ContributionTable.tpl"}
</div>
<div class="crm-block crm-content-block crm-contribution-thank-you-block">
  <h3>{ts domain='org.civicrm.cdntaxreceipts'}Delivery Preference{/ts}</h3>
  <table class="crm-info-panel">
    <tr>
      <td class="content">{$form.receipt_option.html}</td>
      <td class="label">{$form.receipt_option.label}</td>
    </tr>
    <tr>
      <td class="label bold-text">{$form.delivery_method.label}</td>
      <td class="content">{$form.delivery_method.html}</td>
    </tr>
  </table>
  <div class="crm-submit-buttons">{include file="CRM/common/formButtons.tpl"}</div>
</div>