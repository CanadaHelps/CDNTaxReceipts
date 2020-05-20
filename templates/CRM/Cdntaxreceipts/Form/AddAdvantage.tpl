{if $isView}
    <table id="advantage-description" class="hiddenElement">
        <tr class="crm-contribution-form-block-advantage_description">
            <td class="label">{ts}Description of advantage{/ts}</td>
            <td>{if $advantage_description}{$advantage_description}{else}-{/if}</td>
        </tr>
    </table>
{literal}
    <script type="text/javascript">
    CRM.$(function($) {
        amount = $('form#ContributionView').find('td.label:contains("Non-receiptable Amount")');
        amount.text("Advantage Amount");
        $('#advantage-description tr').insertAfter(amount.parent('tr'));
    });
    </script>
{/literal}
{else}
    <table id="advantage-description" class="hiddenElement">
        <tr class="crm-contribution-form-block-advantage_description">
            <td class="label">{$form.advantage_description.label}</td>
            <td>{$form.advantage_description.html}</td>
        </tr>
    </table>
{literal}
<script type="text/javascript">
    CRM.$(function($) {
        $( document ).ajaxComplete(function() {
            $('#advantage-description tr').insertAfter('tr.crm-contribution-form-block-non_deductible_amount');

            // Add required mark if advantage amount is filled.
            addRequired($('#non_deductible_amount').val());
            $('#non_deductible_amount').blur(function() {
                addRequired($(this).val());
            });

            function addRequired(mode) {
                mode = parseInt(mode);
                if (mode != '' && mode > 0) {
                    $('label[for="advantage_description"]').find('span.crm-marker').remove();
                    $('label[for="advantage_description"]').append("<span class=\"crm-marker\" title=\"This field is required.\">&nbsp;*</span>");
                }
                else {
                    $('label[for="advantage_description"]').find('span.crm-marker').remove();
                }
            }
        });
    });
</script>
{/literal}
{/if}