{* Confirmation of tax receipts  *}
<div class="crm-block crm-content-block crm-contribution-view-form-block">
  <h3>Receipts Details</h3>
  <table class="crm-stripes-rows crm-info-panel">
    <tr>
      <td class="label bold-text">{ts}Tax Year{/ts}</td>
      <td id="receipt_year">
        {$form.receipt_year.html}
      </td>
      <td class="label display-cell-padding-left bold-weight">{ts}Contributions{/ts}</td>
      {math equation="(x + y + z)" x=$receiptList.original.$defaultYear.total_contrib y=$receiptList.duplicate.$defaultYear.total_contrib z=$receiptList.ineligibles.$defaultYear.total_contrib assign="count_contributions"}
      <td id="count_contributions">{$count_contributions}</td>
    </tr>
  </table>
  <table class="crm-stripes-rows crm-info-panel border-top-td crm-stripes-tr">
    <tr>
      <td class="label bold-weight">{ts}Eligible Contacts{/ts}</td>
      {math equation="(x + y)" x=$receiptList.original.$defaultYear.total_contacts y=$receiptList.duplicate.$defaultYear.total_contacts assign="total_contacts"}
      {if $receiptList.original.$defaultYear.total_contacts eq 0 }
      {math equation="(x + y + z)" x=$receiptList.original.$defaultYear.total_contacts y=$receiptList.duplicate.$defaultYear.total_contacts z=$receiptList.ineligibles.$defaultYear.total_contacts assign="total_contacts"}
      {/if}
      <td id="total_contacts" class="label">{$total_contacts}</td>
      <td class="label display-cell-padding bold-weight">{ts}Eligible Contributions{/ts}</td>
      {math equation="(x + y)" x=$receiptList.original.$defaultYear.total_contrib y=$receiptList.duplicate.$defaultYear.total_contrib assign="total_contributions"}
      <td id="total_contributions" class="label">{$total_contributions}</td>
      <td></td>
    </tr>
    <tr>
      <td class="label bold-weight">{ts}Total Eligible Amount{/ts}</td>
      {math equation="(x + y)" x=$receiptList.original.$defaultYear.total_amount y=$receiptList.duplicate.$defaultYear.total_amount assign="total_amount"}
      <td id="total_amount">{$total_amount|crmMoney}</td>
      <td class="label display-cell-padding bold-weight">{ts}Ineligible Contributions{/ts}</td>
      <td id="skipped_contributions" class="label">{$receiptList.ineligibles.$defaultYear.total_contrib}</td>
      <td></td>
    </tr>
  </table>
  <!-- <table class="crm-info-panel">
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
  </table> -->
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

{literal}
  <script type="text/javascript">
    CRM.$(function($) {
      var receipts = {/literal}{$receiptList|@json_encode}{literal};
      var receiptTypes = {/literal}{$receiptTypes|@json_encode}{literal};
      $("#receipt_year").change(function(){
        var tax_year = $('option:selected', this).text();
        if (typeof receipts.original[tax_year] === "undefined" || receipts.original[tax_year] === null) {
          receipts.original[tax_year] = {'total_contrib': 0, 'total_contacts': 0, 'total_amount': 0};
        }
        if (typeof receipts.duplicate[tax_year] === "undefined" || receipts.duplicate[tax_year] === null) {
          receipts.duplicate[tax_year] = {'total_contrib': 0, 'total_contacts': 0, 'total_amount': 0};
        }
        if (typeof receipts.ineligibles[tax_year] === "undefined" || receipts.ineligibles[tax_year] === null) {
          receipts.ineligibles[tax_year] = {'total_contrib': 0, 'total_contacts': 0, 'total_amount': 0};
        }
        var total_contributions = receipts.original[tax_year].total_contrib+receipts.duplicate[tax_year].total_contrib;
        var total_amount = receipts.original[tax_year].total_amount+receipts.duplicate[tax_year].total_amount;
        var count_contributions = receipts.original[tax_year].total_contrib + receipts.duplicate[tax_year].total_contrib + receipts.ineligibles[tax_year].total_contrib;
        var total_contacts = receipts.original[tax_year].total_contacts+receipts.duplicate[tax_year].total_contacts;
        var myTable = '';
        if(total_contacts === 0) {
          total_contacts = receipts.original[tax_year].total_contacts + receipts.duplicate[tax_year].total_contacts + receipts.ineligibles[tax_year].total_contacts;
        }
        $('#total_contributions').text(total_contributions);
        $('#count_contributions').text(count_contributions);
        $('#total_contacts').text(total_contacts);
        $('#total_amount').text("$ "+ (total_amount).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,'));
        $('#skipped_contributions').text(receipts.ineligibles[tax_year].total_contrib);

        //Append new values for table-of-users
        $.each(receiptTypes, function(key, receiptType){
          $.each(receipts[receiptType][tax_year].contact_ids, function(cid, contact){
            $.each(contact.contributions, function(contrId, contribution){
              if(!contribution.campaign) {
                contribution.campaign = '';
              }
              if(contribution.eligible === true) {
                contribution.eligible = 'Eligible';
                contribution.eligibility_reason = '';
              } else {
                contribution.eligible = 'Not Eligible';
                if(!contribution.eligibility_reason) {
                  contribution.eligibility_reason = '';
                }
              }
              var contactUrl = '/dms/contact/view?reset=1&cid='+contribution.contact_id;
              var contributionUrl = '/dms/contact/view/contribution?reset=1&cid='+contribution.contact_id+'&id='+contribution.contribution_id+'&action=view&context=search&selectedChild=contribute';
              if(receiptType == 'duplicate') {
                receiptType = 'duplicate-single';
              }
              myTable += '<tr class="'+receiptType+'-receipt-contributions contribution-id-'+contribution.contribution_id+'"><td>'+contribution.receive_date+'<br/>'+contribution.receive_time+'</td><td><a href="'+contactUrl+'">'+contact.display_name+'</a></td><td><a href="'+contributionUrl+'">$ '+contribution.total_amount+'</a></td><td>'+contribution.fund+'</td><td>'+contribution.campaign+'</td><td>'+contribution.contribution_source+'</td><td>'+contribution.payment_instrument+'</td><td>'+contribution.contribution_status+'</td><td>'+contribution.eligible+'<br/>'+contribution.eligibility_reason+'</td></tr>';
            });
          });
        });
        $('.table-of-users tbody').html(myTable);
      });
    });
  </script>
{/literal}
