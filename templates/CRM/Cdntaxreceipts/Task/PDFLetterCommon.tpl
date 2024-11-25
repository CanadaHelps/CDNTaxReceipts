{*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
*}
{*common template for compose PDF letters*}
{if ($form.template.html || $form.template_FR.html)}
<table class="form-layout-compressed">
    <tr class="language_en">
      <td class="label-left">
        {$form.template.label}
        {help id="template" title=$form.template.label file="CRM/Contact/Form/Task/PDFLetterCommon.hlp"}
      </td>
      <td class="tab-left">
        {$form.template.html}
      </td>
      <td class="href-left">
        <a class="html_view_toggle" data-id="crm-html_email-accordion" href="#">Edit</a>
      </td>
    </tr>
    <tr class="language_fr">
      <td class="label-left">
        {$form.template_FR.label}
        {help id="template_FR" title=$form.template_FR.label file="CRM/Contact/Form/Task/PDFLetterCommon.hlp"}
      </td>
      <td class="tab-left">
        {$form.template_FR.html}
      </td>
      <td class="href-left">
        <a class="html_view_toggle" data-id="crm-html_email-accordion-fr" href="#">Edit</a>
      </td>
    </tr>
    <tr class="hidden-receipt-page">
      <td class="label-left">{$form.subject.label}</td>
      <td>{$form.subject.html}</td>
    </tr>
    {if $form.campaign_id}
    <tr class="hidden-receipt-page">
      <td class="label-left">{$form.campaign_id.label}</td>
      <td>{$form.campaign_id.html}</td>
    </tr>
    {/if}
</table>
{/if}

<div class="crm-accordion-wrapper crm-html_email-accordion hidden-receipt-page">
  <div class="crm-accordion-body">
    <table class="form-layout-compressed">
      <tr>
        <td class="label-left">{$form.from_email_address.label}</td>
        <td>{$form.from_email_address.html}</td>
      </tr>
      <tr>
        <td class="label-left">{$form.email_options.label} {help id="id-contribution-email-print"}</td>
        <td>{$form.email_options.html}</td>
      </tr>
      <tr>
        <td class="label-left">{$form.group_by_separator.label}</td>
        <td>{$form.group_by_separator.html}</td>
      </tr>
    </table>
  </div><!-- /.crm-accordion-body -->
</div><!-- /.crm-accordion-wrapper -->

<div class="crm-accordion-wrapper collapsed crm-pdf-format-accordion hidden-receipt-page">
    <div class="crm-accordion-header">
      {ts}Page Format:{/ts} <span class="pdf-format-header-label"></span>
    </div>
    <div class="crm-accordion-body">
      <div class="crm-block crm-form-block">
    <table class="form-layout-compressed">
      <tr>
        <td class="label-left">{$form.format_id.label} {help id="id-pdf-format" file="CRM/Contact/Form/Task/PDFLetterCommon.hlp"}</td>
        <td>{$form.format_id.html}</td>
      </tr>
      <tr>
        <td class="label-left">{$form.paper_size.label}</td><td>{$form.paper_size.html}</td>
        <td class="label-left">{$form.orientation.label}</td><td>{$form.orientation.html}</td>
      </tr>
      <tr>
        <td class="label-left">{$form.metric.label}</td><td>{$form.metric.html}</td>
        <td colspan="2">&nbsp;</td>
      </tr>
      <tr>
        <td>{ts}Width x Height{/ts}</td><td id="paper_dimensions">&nbsp;</td>
        <td colspan="2">&nbsp;</td>
      </tr>
      <tr>
        <td class="label-left">{$form.margin_top.label}</td><td>{$form.margin_top.html}</td>
        <td class="label-left">{$form.margin_bottom.label}</td><td>{$form.margin_bottom.html}</td>
      </tr>
      <tr>
        <td class="label-left">{$form.margin_left.label}</td><td>{$form.margin_left.html}</td>
        <td class="label-left">{$form.margin_right.label}</td><td>{$form.margin_right.html}</td>
      </tr>
      {* CRM-15883 Suppressing stationery until switch from DOMPDF.
      <tr>
        <td class="label-left">{$form.stationery.label}</td><td>{$form.stationery.html}</td>
        <td colspan="2">&nbsp;</td>
      </tr>
      *}
    </table>
        <div id="bindFormat">{$form.bind_format.html}&nbsp;{$form.bind_format.label}</div>
        <div id="updateFormat" style="display: none">{$form.update_format.html}&nbsp;{$form.update_format.label}</div>
      </div>
  </div>
</div>

<div class="crm-accordion-wrapper crm-html_email-accordion crm-html-ch">
  <div class="crm-accordion-header">
      {$form.html_message_en.label}
  </div><!-- /.crm-accordion-header -->
  <div class="crm-accordion-body">
    <div class="helpIcon" id="helphtml">
      <input class="crm-token-selector big" data-field="html_message_en" />
    </div>
    <div class="clear"></div>
    <div class='html'>
      {$form.html_message_en.html}<br />
    </div>
    <div id="editMessageDetails" class="hidden-receipt-page">
      <div id="updateDetails" >
        {$form.updateTemplate.html}&nbsp;{$form.updateTemplate.label}
      </div>
      <div>
        {$form.saveTemplate.html}&nbsp;{$form.saveTemplate.label}
      </div>
    </div>
    <div id="saveDetails" class="section hidden-receipt-page">
      <div class="label">{$form.saveTemplateName.label}</div>
      <div class="content">{$form.saveTemplateName.html|crmAddClass:huge}</div>
    </div>
  </div><!-- /.crm-accordion-body -->
</div><!-- /.crm-accordion-wrapper -->

<div class="crm-accordion-wrapper crm-html_email-accordion-fr crm-html-ch">
  <div class="crm-accordion-header">
      {$form.html_message_fr.label}
  </div><!-- /.crm-accordion-header french-->
  <div class="crm-accordion-body">
    <div class="helpIcon" id="helphtml">
      <input class="crm-token-selector big" data-field="html_message_fr" />
    </div>
    <div class="clear"></div>
    <div class='html'>
      {$form.html_message_fr.html}<br />
    </div>
    <div id="editMessageDetails" class="hidden-receipt-page">
      <div id="updateDetails" >
        {$form.updateTemplate.html}&nbsp;{$form.updateTemplate.label}
      </div>
      <div>
        {$form.saveTemplate.html}&nbsp;{$form.saveTemplate.label}
      </div>
    </div>
    <div id="saveDetails" class="section hidden-receipt-page">
      <div class="label">{$form.saveTemplateName.label}</div>
      <div class="content">{$form.saveTemplateName.html|crmAddClass:huge}</div>
    </div>
  </div><!-- /.crm-accordion-body -->
</div><!-- /.crm-accordion-wrapper -->
<table class="form-layout-compressed">
  <tr class="hidden-receipt-page">
    <td class="label-left">{$form.document_type.label}</td>
    <td>{$form.document_type.html}</td>
  </tr>
</table>

{include file="CRM/Mailing/Form/InsertTokens.tpl"}

{literal}
<script type="text/javascript">
CRM.$(function($) {
  var $form = $('form.{/literal}{$form.formClass}{literal}');

  {/literal}{if $form.formName eq 'PDF'}{literal}
    $('.crm-document-accordion').hide();
    $('#document_file').on('change', function() {
      if (this.value) {
        $('.crm-html_email-accordion, .crm-document-accordion, .crm-pdf-format-accordion').hide();
        cj('#document_type').closest('tr').hide();
        $('#template').val('');
      }
    });
  {/literal}{/if}{literal}


  $('#format_id', $form).on('change', function() {
    selectFormat($(this).val());
  });
  // After the pdf downloads, the user has to manually close the dialog (which would be nice to fix)
  // But at least we can trigger the underlying list of activities to refresh
  $('[name=_qf_PDF_submit]', $form).click(function() {
    var $dialog = $(this).closest('.ui-dialog-content.crm-ajax-container');
    if ($dialog.length) {
      $dialog.on('dialogbeforeclose', function () {
        $(this).trigger('crmFormSuccess');
      });
      $dialog.dialog('option', 'buttons', [{
        text: {/literal}"{ts escape='js'}Done{/ts}"{literal},
        icons: {primary: 'fa-times'},
        click: function() {$(this).dialog('close');}
      }]);
    }
  });
  $('[name^=_qf_PDF_submit]', $form).click(function() {
    CRM.status({/literal}"{ts escape='js'}Downloading...{/ts}"{literal});
  });
  showSaveDetails($('input[name=saveTemplate]', $form)[0]);

  function showSaveTemplate() {
    $('#updateDetails').toggle(!!$(this).val());
  }
  $('[name=template]', $form).each(showSaveTemplate).change(showSaveTemplate);

  //CRM-921: Hide HTML box on checkbox
  $('.crm-html_email-accordion').hide();
  $('.crm-html_email-accordion-fr').hide();
  $('.html_view_toggle').hide();
  $('#template').parents().eq(2).hide();
  $('#template_FR').parents().eq(2).hide();
  $('#thankyou_email').on('change', function() {
    if($('#thankyou_email').prop('checked') == true) {
      $('#template').parents().eq(2).show();
      $('#template_FR').parents().eq(2).show();
    } else {
      $('.crm-html_email-accordion').hide();
      $('.crm-html_email-accordion-fr').hide();
      $('#template').parents().eq(2).hide();
      $('#template_FR').parents().eq(2).hide();
    }
  });

  $('.html_view_toggle').on('click', function(e) {
    e.preventDefault();
    $('.crm-html-ch').hide();
    let language_selector = $(this).data("id");
      $( '.'+language_selector ).show();
  });

  $('#template').on('change', function() {
    if($(this).find('option:selected').val() == 'default') {
      $('.crm-html_email-accordion').hide();
      $(this).parent().next('td').find('a').hide();
    } else {
      $(this).parent().next('td').find('a').show();
    }
  })
  $('#template_FR').on('change', function() {
    if($(this).find('option:selected').val() == 'default') {
      $('.crm-html_email-accordion-fr').hide();
      $(this).parent().next('td').find('a').hide();
    } else {
      $(this).parent().next('td').find('a').show();
    }
  })
  {/literal}
  {if isset($view_receipt_language) && $view_receipt_language eq 'fr_CA'}
    {literal}
    $('.language_en').hide();
    {/literal}
  {else}
    {literal}
    $('.language_fr').hide();
    {/literal}
  {/if}
  {literal}
});

var currentWidth;
var currentHeight;
var currentMetric = document.getElementById('metric').value;
showBindFormatChkBox();
selectPaper( document.getElementById('paper_size').value );

function showBindFormatChkBox()
{
    var templateExists = true;
    if ( document.getElementById('template') == null || document.getElementById('template').value == '' ) {
        templateExists = false;
    }
    var formatExists = !!cj('#format_id').val();
    if ( templateExists && formatExists ) {
        document.getElementById("bindFormat").style.display = "block";
    } else if ( formatExists && document.getElementById("saveTemplate") != null && document.getElementById("saveTemplate").checked ) {
        document.getElementById("bindFormat").style.display = "block";
        var yes = confirm( '{/literal}{$useThisPageFormat}{literal}' );
        if ( yes ) {
            document.getElementById("bind_format").checked = true;
        }
    } else {
        document.getElementById("bindFormat").style.display = "none";
        document.getElementById("bind_format").checked = false;
    }
}

function showUpdateFormatChkBox()
{
    if (cj('#format_id').val()) {
      cj("#updateFormat").show();
    }
}

function updateFormatLabel() {
  cj('.pdf-format-header-label').html(cj('#format_id option:selected').text() || cj('#format_id').attr('placeholder'));
}

updateFormatLabel();

function fillFormatInfo( data, bind ) {
  cj("#format_id").val( data.id );
  cj("#paper_size").val( data.paper_size );
  cj("#orientation").val( data.orientation );
  cj("#metric").val( data.metric );
  cj("#margin_top").val( data.margin_top );
  cj("#margin_bottom").val( data.margin_bottom );
  cj("#margin_left").val( data.margin_left );
  cj("#margin_right").val( data.margin_right );
  selectPaper( data.paper_size );
  cj("#update_format").prop({checked: false}).parent().hide();
  document.getElementById('bind_format').checked = bind;
  showBindFormatChkBox();
}

function selectFormat( val, bind ) {
  updateFormatLabel();
  if (!val) {
    val = 0;
    bind = false;
  }

  var dataUrl = {/literal}"{crmURL p='civicrm/ajax/pdfFormat' h=0 }"{literal};
  cj.post( dataUrl, {formatId: val}, function( data ) {
    fillFormatInfo(data, bind);
  }, 'json');
}

function selectPaper( val )
{
    dataUrl = {/literal}"{crmURL p='civicrm/ajax/paperSize' h=0 }"{literal};
    cj.post( dataUrl, {paperSizeName: val}, function( data ) {
        cj("#paper_size").val( data.name );
        metric = document.getElementById('metric').value;
        currentWidth = convertMetric( data.width, data.metric, metric );
        currentHeight = convertMetric( data.height, data.metric, metric );
        updatePaperDimensions( );
    }, 'json');
}

function selectMetric( metric )
{
    convertField( 'margin_top', currentMetric, metric );
    convertField( 'margin_bottom', currentMetric, metric );
    convertField( 'margin_left', currentMetric, metric );
    convertField( 'margin_right', currentMetric, metric );
    currentWidth = convertMetric( currentWidth, currentMetric, metric );
    currentHeight = convertMetric( currentHeight, currentMetric, metric );
    updatePaperDimensions( );
}

function updatePaperDimensions( )
{
    metric = document.getElementById('metric').value;
    width = new String( currentWidth.toFixed( 2 ) );
    height = new String( currentHeight.toFixed( 2 ) );
    if ( document.getElementById('orientation').value == 'landscape' ) {
        width = new String( currentHeight.toFixed( 2 ) );
        height = new String( currentWidth.toFixed( 2 ) );
    }
    document.getElementById('paper_dimensions').innerHTML = parseFloat( width ) + ' ' + metric + ' x ' + parseFloat( height ) + ' ' + metric;
    currentMetric = metric;
}

function convertField( id, from, to )
{
    val = document.getElementById( id ).value;
    if ( val == '' || isNaN( val ) ) return;
    val = convertMetric( val, from, to );
    val = new String( val.toFixed( 3 ) );
    document.getElementById( id ).value = parseFloat( val );
}

function convertMetric( value, from, to ) {
    switch( from + to ) {
        case 'incm': return value * 2.54;
        case 'inmm': return value * 25.4;
        case 'inpt': return value * 72;
        case 'cmin': return value / 2.54;
        case 'cmmm': return value * 10;
        case 'cmpt': return value * 72 / 2.54;
        case 'mmin': return value / 25.4;
        case 'mmcm': return value / 10;
        case 'mmpt': return value * 72 / 25.4;
        case 'ptin': return value / 72;
        case 'ptcm': return value * 2.54 / 72;
        case 'ptmm': return value * 25.4 / 72;
    }
    return value;
}

function showSaveDetails(chkbox)  {
    var formatSelected = ( document.getElementById('format_id').value > 0 );
    var templateSelected = ( document.getElementById('template') != null && document.getElementById('template').value > 0 );
    if (chkbox.checked) {
        document.getElementById("saveDetails").style.display = "block";
        document.getElementById("saveTemplateName").disabled = false;
        if ( formatSelected && ! templateSelected ) {
            document.getElementById("bindFormat").style.display = "block";
            var yes = confirm( '{/literal}{$useSelectedPageFormat}{literal}' );
            if ( yes ) {
                document.getElementById("bind_format").checked = true;
            }
        }
    } else {
        document.getElementById("saveDetails").style.display = "none";
        document.getElementById("saveTemplateName").disabled = true;
        if ( ! templateSelected ) {
            document.getElementById("bindFormat").style.display = "none";
            document.getElementById("bind_format").checked = false;
        }
    }
}

function selectTemplateValue( val, language) {
  var html_container = 'html_message_en';
  if(language == 'FR')
  html_container = 'html_message_fr';

  if ( !val ) {
    if (document.getElementById("subject").length) {
      document.getElementById("subject").value ="";
    }
    CRM.wysiwyg.setVal('#'+ html_container , '');
    return;
  }

  var url = CRM.url('civicrm/ajax/loadTemplate');
  $.post( url, {tid: val}, function( data ) {
    cj("#subject").val( data.subject );
    CRM.wysiwyg.setVal('#' + html_container , data.msg_html || '');
  }, 'json');
}

</script>
{/literal}
