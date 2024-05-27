<div class="crm-block crm-form-block crm-miscellaneous-form-block">

<h3>{ts domain='org.civicrm.cdntaxreceipts'}Organization Details{/ts}</h3>

  <table class="form-layout">
    <tbody>
      <tr class="crm-cdntaxreceipts-form-block-org_name">
        <td class="label">{$form.org_name.label}</td>
        <td class="content">{$form.org_name.html}
          <p class="description">{ts domain='org.civicrm.cdntaxreceipts'}My Charitable Organization{/ts}</p></td>
      </tr>
      <tr class="crm-cdntaxreceipts-form-block-org_address_line1">
        <td class="label">{$form.org_address_line1.label}</td>
        <td class="content">{$form.org_address_line1.html}
          <p class="description">{ts domain='org.civicrm.cdntaxreceipts'}101 Anywhere Drive{/ts}</p></td>
      </tr>
      <tr class="crm-cdntaxreceipts-form-block-org_address_line2">
        <td class="label">{$form.org_address_line2.label}</td>
        <td class="content">{$form.org_address_line2.html}
          <p class="description">{ts domain='org.civicrm.cdntaxreceipts'}Toronto ON A1B 2C3{/ts}</p></td>
      </tr>
      <tr class="crm-cdntaxreceipts-form-block-org_tel">
        <td class="label">{$form.org_tel.label}</td>
        <td class="content">{$form.org_tel.html}
          <p class="description">(555) 555-5555</p></td>
      </tr>
      <tr class="crm-cdntaxreceipts-form-block-org_fax">
        <td class="label">{$form.org_fax.label}</td>
        <td class="content">{$form.org_fax.html}
          <p class="description">(555) 555-5555</p></td>
      </tr>
      <tr class="crm-cdntaxreceipts-form-block-org_email">
        <td class="label">{$form.org_email.label}</td>
        <td class="content">{$form.org_email.html}
          <p class="description">info@my.org</p></td>
      </tr>
      <tr class="crm-cdntaxreceipts-form-block-org_web">
        <td class="label">{$form.org_web.label}</td>
        <td class="content">{$form.org_web.html}
          <p class="description">www.my.org</p></td>
      </tr>
      <tr class="crm-cdntaxreceipts-form-block-org_charitable_no">
        <td class="label">{$form.org_charitable_no.label}</td>
        <td class="content">{$form.org_charitable_no.html}
          <p class="description">10000-000-RR0000</p></td>
      </tr>
      <tr class="crm-cdntaxreceipts-form-block-receipt_location_issued">
        <td class="label">{$form.receipt_location_issued.label}</td>
        <td class="content">{$form.receipt_location_issued.html}
          <p class="description">Toronto</p></td>
      </tr>
    </tbody>
  </table>

<h3>{ts domain='org.civicrm.cdntaxreceipts'}Receipt Configuration{/ts}</h3>

  <table class="form-layout">
    <tbody>
      <tr class="crm-cdntaxreceipts-form-block-receipt_prefix">
        <td class="label">{$form.receipt_prefix.label}</td>
        <td class="content">{$form.receipt_prefix.html}
          <p class="description">{ts domain='org.civicrm.cdntaxreceipts'}Receipt numbers are formed by appending the CiviCRM Contribution ID to this prefix. Receipt numbers must be unique within your organization. If you also issue tax receipts using another system, you can use the prefix to ensure uniqueness (e.g. enter 'WEB-' here so all receipts issued through CiviCRM are WEB-00000001, WEB-00000002, etc.){/ts}</p></td>
      </tr>
      <tr class="crm-cdntaxreceipts-form-block-receipt_authorized_signature_text hidden">
        <td class="label">{$form.receipt_authorized_signature_text.label}</td>
        <td class="content">{$form.receipt_authorized_signature_text.html}
          <p class="description">{ts domain='org.civicrm.cdntaxreceipts'}Name and position of the authorizing official to be displayed under the signature line. Defaults to "Authorized Signature" if no name is specified.{/ts}</p></td>
      </tr>
      {* CH Customization: Preview image *}
      <tr class="crm-cdntaxreceipts-form-block-receipt_logo">
        <td class="label">{$form.receipt_logo.label}</td>
        <td class="content">{$form.receipt_logo.html}
          <p class="description">{ts domain='org.civicrm.cdntaxreceipts'}Logo size: 280x120 pixels; File types allowed: .jpg .png.{/ts}</p>
          <img id="ReceiptLogoPreview" src="#" class="preview_image" alt="receipt_logo_preview" />
          <p id="receipt_logo-error-message"></p>
	  {if isset($receipt_logo)}
	      {if $receipt_logo_class}<span class="crm-error">The file {$receipt_logo} was not found</span>
	      {else}<p class="label">Current {$form.receipt_logo.label}: {$receipt_logo}<span class="cdntaxreceipts-imagedelete"><a href="{crmURL p='civicrm/cdntaxreceipts/imagedelete' q='type=receipt_logo'}">{ts}Delete{/ts}</a></span></p>{/if}
	  {/if}</td>
      </tr>
      {* CH Customization: Preview image *}
      <tr class="crm-cdntaxreceipts-form-block-receipt_signature">
        <td class="label">{$form.receipt_signature.label}</td>
        <td class="content">{$form.receipt_signature.html}
          <p class="description">{ts domain='org.civicrm.cdntaxreceipts'}Signature size: 141x58 pixels; File types allowed: .jpg .png.{/ts}</p>
          <img id="ReceiptSignaturePreview" src="#" class="preview_image" alt="receipt_signatur_preview" />
          <p id="receipt_signature-error-message"></p>
	  {if isset($receipt_signature)}
	      {if $receipt_signature_class}<span class="crm-error">The file {$receipt_signature} was not found</span>
	      {else}<p class="label">Current {$form.receipt_signature.label}: {$receipt_signature}<span class="cdntaxreceipts-imagedelete"><a href="{crmURL p='civicrm/cdntaxreceipts/imagedelete' q='type=receipt_signature'}">{ts}Delete{/ts}</a></span></p>{/if}
	  {/if}</td>
      </tr>
      {* CH Customization: DISABLED
      <tr class="crm-cdntaxreceipts-form-block-receipt_watermark">
        <td class="label">{$form.receipt_watermark.label}</td>
        <td class="content">{$form.receipt_watermark.html}
          <p class="description">{ts domain='org.civicrm.cdntaxreceipts'}Watermark Image size: 250x250 pixels; File types allowed: .jpg .png.{/ts}</p>
	  {if $receipt_watermark}
	      {if $receipt_watermark_class}<span class="crm-error">The file {$receipt_watermark} was not found</span>
	      {else}<p class="label">Current {$form.receipt_watermark.label}: {$receipt_watermark}<span class="cdntaxreceipts-imagedelete"><a href="{crmURL p='civicrm/cdntaxreceipts/imagedelete' q='type=receipt_watermark'}">{ts}Delete{/ts}</a></span></p>{/if}
	  {/if}</td>
      </tr>
      <tr class="crm-cdntaxreceipts-form-block-receipt_pdftemplate">
        <td class="label">{$form.receipt_pdftemplate.label}</td>
        <td class="content">{$form.receipt_pdftemplate.html}
          <p class="description">{ts domain='org.civicrm.cdntaxreceipts'}Upload your own PDF template: .pdf{/ts}</p>
	  {if $receipt_pdftemplate}
	      {if $receipt_pdftemplate_class}<span class="crm-error">The file {$receipt_pdftemplate} was not found</span>
	      {else}<p class="label">Current {$form.receipt_pdftemplate.label}: {$receipt_pdftemplate}<span class="cdntaxreceipts-imagedelete"><a href="{crmURL p='civicrm/cdntaxreceipts/imagedelete' q='type=receipt_pdftemplate'}">{ts}Delete{/ts}</a></span></p>{/if}
	  {/if}</td>
      </tr>
      *}
      <tr class="crm-cdntaxreceipts-form-block-source_field hidden">
        <td class="label">{$form.source_field.label}</td>
        <td class="content">{$form.source_field.html|crmAddClass:huge}&nbsp;<input class="crm-token-selector big" data-field="source_field" />
          <p class="description">{ts domain='org.civicrm.cdntaxreceipts'}Either replace with a token string or blank out to hide the field.{/ts}</td>
      </tr>
      <tr class="crm-cdntaxreceipts-form-block-source_label hidden">
        <td class="label">{$form.source_label.label}</td>
        <td class="content">{$form.source_label.html}
          <p class="description">{ts domain='org.civicrm.cdntaxreceipts'}Label to use for the Source field. Include a space at the end to provide spacing between the label and the value.{/ts}</td>
      </tr>
    </tbody>
  </table>

<h3 class="hidden">{ts domain='org.civicrm.cdntaxreceipts'}System Options{/ts}</h3>

  <table class="form-layout hidden">
    <tbody>
      {* If In-kind appears to already be at least partly configured, no point displaying the checkbox. *}
      <tr class="crm-cdntaxreceipts-form-block-issue_inkind" {if $has_inkind_custom || $inkind_financial_type_is_known}style="display:none;"{/if}>
        <td class="label">{$form.issue_inkind.label}</td>
        <td class="content">{$form.issue_inkind.html}
          <p class="description">{ts domain='org.civicrm.cdntaxreceipts'}Checking this box will set up the fields required to generate in-kind tax receipts.{/ts}</p></td>
      </tr>
      {* If they appear to have the custom fields but we don't know the financial type, this is probably a site where they changed the name before 1.9, so let them pick a type. *}
      <tr class="crm-cdntaxreceipts-form-block-inkind_financial_type" {if !$has_inkind_custom || $inkind_financial_type_is_known}style="display:none;"{/if}>
        <td class="label">{$form.inkind_financial_type.label}</td>
        <td class="content">{$form.inkind_financial_type.html}
          <p class="description">{ts domain='org.civicrm.cdntaxreceipts'}In-kind appears to have been previously enabled but the financial type for it may have changed. Please select the type you are using for In-kind.{/ts}</p></td>
      </tr>
      {* If we're sure about In-kind, display a confirmation it's set up. *}
      {if $has_inkind_custom && $inkind_financial_type_is_known}
      <tr class="crm-cdntaxreceipts-form-block-inkind_configured">
        <td class="label"><label>{ts domain='org.civicrm.cdntaxreceipts'}In-kind receipts:{/ts}</label></td>
        <td class="content">{ts domain='org.civicrm.cdntaxreceipts'}Configured{/ts}
          <p class="description">{ts domain='org.civicrm.cdntaxreceipts'}To disable in-kind receipts you will need to either disable the In-kind contribution type or make it non-deductible.{/ts}</p></td>
      </tr>
      {/if}
      <tr class="crm-cdntaxreceipts-form-block-delivery_method">
        <td class="label">{$form.delivery_method.label}</td>
        <td class="content">{$form.delivery_method.html}
          <p class="description">{ts domain='org.civicrm.cdntaxreceipts'}Print only: all tax receipts are generated in PDF format.<br />Email or print: tax receipts are emailed if possible, otherwise generated in PDF format.<br />Data only: Tax receipts are generated internally in CiviCRM. Data can be exported for mail merge/mail house via the Tax Receipts Issued report.{/ts}</p></td>
      </tr>
      <tr class="crm-cdntaxreceipts-form-block-attach_to_workflows">
        <td class="label">{$form.attach_to_workflows.label}</td>
        <td class="content">{$form.attach_to_workflows.html}
          <p class="description">{ts domain='org.civicrm.cdntaxreceipts'}** Not recommended if you have ACHEFT payments. **<br />If enabled, tax receipts will be attached to the automated emails that CiviCRM sends via online contribution page, and when "send receipt" is selected during backoffice gift entry. Be sure to alter the Contributions - Receipt (on-line/off-line) message templates to alert the donor that their tax receipt is attached.{/ts}</p></td>
      </tr>
      <tr class="crm-cdntaxreceipts-form-block-enable_advanced_eligibility_report">
        <td class="label">{$form.enable_advanced_eligibility_report.label}</td>
        <td class="content">{$form.enable_advanced_eligibility_report.html}
          <p class="description">{ts domain='org.civicrm.cdntaxreceipts'}If enabled, the Receipts not issued Report will have the Advanced Eligibility Check enabled by default. Required for accurate reports, but can slow reports.{/ts}</p></td>
      </tr>
    </tbody>
  </table>

<h3>{ts domain='org.civicrm.cdntaxreceipts'}Email Message{/ts}</h3>

  <table class="form-layout">
    <tbody>
      <tr class="crm-cdntaxreceipts-form-block-email_from">
        <td class="label">{$form.email_from.label}</td>
        <td class="content">{$form.email_from.html}
          <p class="description">{ts domain='org.civicrm.cdntaxreceipts'}Address you would like to Email the Tax Receipt from.{/ts}</p></td>
      </tr>
      <tr class="crm-cdntaxreceipts-form-block-email_archive">
        <td class="label">{$form.email_archive.label}</td>
        <td class="content">{$form.email_archive.html}
          <p class="description">{ts domain='org.civicrm.cdntaxreceipts'}Address you would like to Send a copy of the Email containing the Tax Receipt to. This is useful to create an archive.{/ts}</p></td>
      </tr>
      <tr class="crm-cdntaxreceipts-form-block-email_message">
        <td class="label">{ts domain='org.civicrm.cdntaxreceipts'}Message{/ts}</td>
        <td class="content"><p class="description">{ts domain='org.civicrm.cdntaxreceipts'}The email message is configured under "System Workflow Messages" in Communication Templates.{/ts}</p></td>
      </tr>
    </tbody>
  </table>

<div class="status message"><strong>Tip:</strong>{ts domain='org.civicrm.cdntaxreceipts'}After you fill out this form and save your Configuration, create a fake Donation in CiviCRM and issue a Tax Receipt for it to check the graphics/layout of the Tax Receipt that is generated. If necessary - rework your graphics and come back to this Form to upload the new version(s).{/ts}</div>

{* FOOTER *}
<div class="crm-submit-buttons">
{include file="CRM/common/formButtons.tpl" location="bottom"}
</div>

</div>
{* the InsertTokens needs this for some reason *}
<div id="editMessageDetails"></div>
{include file='CRM/Mailing/Form/InsertTokens.tpl'}
