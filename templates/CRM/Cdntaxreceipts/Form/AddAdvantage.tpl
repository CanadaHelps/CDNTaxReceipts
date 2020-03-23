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
                if (mode != '') {
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