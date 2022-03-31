{* Confirmation of tax receipts  *}
<div class="crm-block crm-content-block crm-contribution-view-form-block">
  <h3>Receipts Details</h3>
  <table class="crm-info-panel">
    <tr>
      <td class="label bold-text">{ts domain='org.civicrm.cdntaxreceipts'}You have selected <strong>{$totalSelectedContributions}</strong> contributions. Of these, <strong>{$receiptList.totals.total_eligibles_contrib}</strong> are eligible originals.{/ts}</td>
      <td></td><td></td><td></td>
    </tr>
  </table>
  <table class="crm-info-panel">
    <tr>
      <td class="label bold-text">{ts}Tax Year{/ts}</td>
      <td id="receipt_year">
        {$form.receipt_year.html}
      </td>
      <td></td><td></td>
    </tr>
  </table>
  <table class="crm-stripes-rows crm-info-panel border-top-td crm-stripes-tr">
    <tr>
      <td class="label bold-weight">{ts}Contacts{/ts}</td>
      <td id="total_contacts" class="label">{$receiptList.original.$defaultYear.total_contacts}</td>
      <td class="label display-cell-padding bold-weight">{ts}Contributions{/ts}</td>
      {math equation="(x - y)" x=$receiptList.original.$defaultYear.total_contrib y=$receiptList.original.$defaultYear.not_eligible assign="total_contributions"}
      <td id="total_contributions" class="label">{$total_contributions}</td>
      <td></td>
    </tr>
    <tr>
      <td class="label bold-weight">{ts}Total Amount{/ts}</td>
      <td id="total_amount">{$receiptList.totals.total_eligible_amount.$defaultYear|crmMoney}</td>
      <td class="label display-cell-padding bold-weight">{ts}Skipped Contributions{/ts}</td>
      <td id="skipped_contributions" class="label">{$receiptList.original.$defaultYear.not_eligible+$receiptList.duplicate.$defaultYear.total_contrib}</td>
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
<div class="hidden-receipt-page">
  <p>{$form.receipt_option.original_only.html}<br />
     {$form.receipt_option.include_duplicates.html}</p>
  <p>{ts domain='org.civicrm.cdntaxreceipts'}Clicking 'Issue Tax Receipts' will issue the selected tax receipts.
    <strong>This action cannot be undone.</strong> Tax receipts will be logged for auditing purposes,
    and a copy of each receipt will be submitted to the tax receipt archive.{/ts}</p>
</div>
<div class="crm-block crm-content-block crm-contribution-view-form-block">
  <h3>{ts domain='org.civicrm.cdntaxreceipts'}Delivery Preference{/ts}</h3>
  <table class="crm-info-panel">
    <tr>
      <td class="label bold-text">{$form.delivery_method.label}</td>
      <td class="content">{$form.delivery_method.html}</td>
    </tr>
  </table>
  <div class="crm-submit-buttons">{include file="CRM/common/formButtons.tpl"}</div>
</div>

{literal}
  <script type="text/javascript">
    CRM.$(function($) {
      var receipts = {/literal}{$receiptList|@json_encode}{literal};
      $("#receipt_year").change(function(){
        var tax_year = $('option:selected', this).text();
        var total_contributions = receipts.original[tax_year].total_contrib-receipts.original[tax_year].not_eligible;
        var total_amount = receipts.totals.total_eligible_amount[tax_year];
        $('#total_contributions').text(total_contributions);
        $('#total_contacts').text(receipts.original[tax_year].total_contacts);
        $('#total_amount').text("$ "+ (total_amount).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,'));
        $('#skipped_contributions').text(receipts.original[tax_year].not_eligible+receipts.duplicate[tax_year].total_contrib);
      });
    });
  </script>
{/literal}
