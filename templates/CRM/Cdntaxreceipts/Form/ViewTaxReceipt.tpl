
{if $pdf_file}
{capture assign="file_url"}{crmURL p='civicrm/cdntaxreceipts/view' q="id=$contribution_id&cid=$contact_id&download=1"}{/capture}
<script type="text/javascript">
cj(document).ready(
  function() {ldelim}
    window.location = "{$file_url|replace:'&amp;':'&'}";
  {rdelim}
);
</script>
{/if}

{if $reissue eq 1 and $receipt}
<div class="crm-block crm-content-block crm-contribution-view-form-block crm-stripes">
<h3>Receipt Details</h3>
  <table class="crm-info-panel">
      <tr>
          <td class="label bold-weight">{ts}Receipt No.{/ts}</td>
          <td class="label">{$receipt.receipt_no}</td>
          <td class="label display-cell-padding bold-weight">{ts}Issue Date{/ts}</td>
          <td>{$receipt.issued_on|crmDate}</td>
      </tr>
      <tr>
        <td class="label bold-weight">{ts}Receipt Status{/ts}</td>
        <td>{if $receipt.receipt_status eq 'issued'}{ts}Issued{/ts}{elseif $receipt.receipt_status eq 'cancelled'}<span class="cancelled">{ts}Cancelled{/ts}</span>{/if}</td>
        <td class="label display-cell-padding bold-weight">{ts}Issued By{/ts}</td>
        <td>{$receipt.uname}</td>
      </tr>
      <tr>
          <td class="label bold-weight">{ts}Type{/ts}</td>
          <td>{ts}{$receipt.display_type}{/ts}</td>
          <td class="label display-cell-padding bold-weight">{ts}Method{/ts}</td>
          <td>{if $receipt.issue_method eq 'email'}{ts}Email{/ts}{elseif $receipt.issue_method eq 'print'}{ts}Print{/ts}{elseif $receipt.issue_method eq 'data'}{ts}Data{/ts}{/if}</td>
      </tr>
      <tr>
          <td class="label bold-weight">{ts}Amount{/ts}</td>
          <td class="label"><a href="{crmURL p='civicrm/contact/view/contribution' q="action=view&reset=1&id=$contribution_id&cid=$contact_id&context=home"}">{$receipt.receipt_amount|crmMoney}</a></td>
          <td class="label display-cell-padding bold-weight">Email Opened</td>
          <td>{$receipt.email_opened|crmDate}</td>
      </tr>
      {if $receipt.cancelled_receipt_number}
      <tr class="replaces">
        <td class="label bold-weight">{ts}Cancels and Replaces Receipt No.{/ts}</td>
        <td class="content">{$receipt.cancelled_receipt_number}</td>
      </tr>
      {/if}
  </table>
</div>
{/if}

{if $reissue eq 0}
  <div class="crm-block crm-content-block crm-contribution-view-form-block">
    <h3>{ts domain='org.civicrm.cdntaxreceipts'}Receipt Details{/ts}</h3>
    <table class="crm-info-panel">
      <tr>
        <td class="label bold-text">{ts}Receipt Status{/ts}</td>
        <td class="label">{ts}Not Issued Yet{/ts}</td>
        <td></td>
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
  {if call_user_func(array('CRM_Core_Permission','check'), 'issue cdn tax receipts')}
  <div class="crm-block crm-content-block crm-contribution-view-form-block">
    <h3>{ts domain='org.civicrm.cdntaxreceipts'}Delivery Method{/ts}</h3>
    <table class="crm-info-panel">
      <tr>
        <td class="label bold-text">{$form.delivery_method.label}</td>
        <td class="content">{$form.delivery_method.html}</td>
      </tr>
    </table>
  {else}
    <p>{ts domain='org.civicrm.cdntaxreceipts'}You do not have sufficient authorization to issue tax receipts.{/ts}</p>
  {/if}
{elseif $reissue eq 1}
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
<div class="crm-block crm-content-block crm-contribution-view-form-block">
  <h3>{ts domain='org.civicrm.cdntaxreceipts'}Delivery Method{/ts}</h3>
  <table class="crm-info-panel">
    <tr>
      <td class="label bold-text">{$form.delivery_method.label}</td>
      <td class="content">{$form.delivery_method.html}</td>
    </tr>
  </table>
  {if call_user_func(array('CRM_Core_Permission','check'), 'issue cdn tax receipts')}
    {if $isCancelled}
    {else}
    {/if}
  {else}
    <p>{ts domain='org.civicrm.cdntaxreceipts'}You do not have sufficient authorization to re-issue tax receipts.{/ts}</p>
  {/if}
{/if}

<div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
</div>
</div>
