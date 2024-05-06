{* Confirmation of tax receipts  *}
{* CH Customization: same as aggregate *}
<div class="crm-block crm-content-block crm-contribution-view-form-block">
  <h3>Receipts Details</h3>
  <table class="crm-stripes-rows crm-info-panel border-top-td crm-stripes-tr">
    <tr>
      <td class="label bold-text" id="receipt_year">{ts}Tax Year{/ts}</td>
      <td colspan="3">
        {$form.receipt_year.html}
      </td>
      <td></td>
    </tr>
    <tr>
      <td class="label bold-weight">{ts}Contributions{/ts}</td>
      <td id="count_contributions">{$receiptList.totals.$defaultYear.total_contrib}</td>
      <td class="label bold-weight">{ts}Eligible Contributions{/ts}</td>
      <td id="total_contributions" class="label">{$receiptList.totals.$defaultYear.eligible_contrib}</td>
      <td></td>
    </tr>
    <tr>
      <td class="label bold-weight">{ts}Duplicates{/ts}</td>
      <td id="duplicate_contributions" class="label display-cell-padding-right">{$receiptList.totals.$defaultYear.duplicate_contrib}</td>
      <td class="label bold-weight">{ts}Eligible Contacts{/ts}</td>
      <td id="total_contacts" class="label">{$receiptList.totals.$defaultYear.eligible_contacts}</td>
      <td></td>
    </tr>
    <tr>
      <td class="label bold-weight">{ts}Ineligible contributions{/ts}</td>
      <td id="skipped_contributions" class="label">{$receiptList.totals.$defaultYear.skipped_contrib}</td>
      <td class="label bold-weight">{ts}Total Eligible Amount{/ts}</td>
      <td id="total_amount">{$receiptList.totals.$defaultYear.eligible_amount|crmMoney}</td>
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
  </table>
  {include file="CRM/Cdntaxreceipts/Task/PDFLetterCommon.tpl"}
</div>

<div class="crm-block crm-content-block">
  <h3>{ts}Contributions{/ts}</h3>
  {include file="CRM/Cdntaxreceipts/Task/ContributionTable.tpl"}
</div>

<div class="crm-block crm-content-block crm-contribution-action-block">
  <h3>{ts domain='org.civicrm.cdntaxreceipts'}Delivery Method{/ts}</h3>
  <table class="crm-info-panel">
    <tr>
      <td class="content" colspan="2" >{$form.receipt_option.html} {$form.receipt_option.label}</td>
    </tr>
    <tr>
      <td class="label bold-text">{$form.delivery_method.label}</td>
      <td class="content">{$form.delivery_method.html}</td>
    </tr>
  </table>
  <div class="crm-submit-buttons">{include file="CRM/common/formButtons.tpl"}</div>
</div>
