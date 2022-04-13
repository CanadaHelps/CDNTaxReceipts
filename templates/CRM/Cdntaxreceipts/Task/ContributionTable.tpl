{*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.7                                                |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2017                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
*}

{strip}
  <table class="selector row-highlight table-of-users">
    <thead class="sticky">
    <tr>
      {if !$single and $context eq 'Search' }
        <th scope="col" title="Select Rows">{$form.toggleSelect.html}</th>
      {/if}

      {foreach from=$columnHeaders item=header}
        <th scope="col" class="crm-contribution-{$header}">
          {$header}
        </th>
      {/foreach}
    </tr>
    </thead>
    <tbody id='table-of-users-receipt'>
    {foreach from=$receiptTypes item=receiptType}
      {if $receipt_type eq 'single'}
      {assign var=contact_ids value=$receiptList.$receiptType.contact_ids}
      {else}
      {assign var=contact_ids value=$receiptList.$receiptType.$defaultYear.contact_ids}
      {/if}
      {foreach from=$contact_ids item=contact}
        {foreach from=$contact.contributions item=contribution}
        <tr class="{$receiptType}-receipt-contributions contribution-id-{$contribution.contribution_id}">
          <td>{$contribution.receive_date}<br/>{$contribution.receive_time}</td>
          <td><a href="{crmURL p='dms/contact/view' q="reset=1&cid=`$contribution.contact_id`"}">{$contact.display_name}</a></td>
          <td><a href="{crmURL p='dms/contact/view/contribution' q="reset=1&cid=`$contribution.contact_id`&id=`$contribution.contribution_id`&action=view&context=search&selectedChild=contribute"}">{$contribution.total_amount|crmMoney:$row.currency}</a></td>
          <td>{$contribution.fund}</td>
          <td>{$contribution.campaign}</td>
          <td>{$contribution.contribution_source}</td>
          <td>{$contribution.payment_instrument}</td>
          <td>{$contribution.contribution_status}</td>
          {if $contribution.eligible}
            <td>Eligible</td>
          {else}
            <td>Not Eligible <br/> {$contribution.eligibility_reason}</td>
          {/if}
        </tr>
        {/foreach}
      {/foreach}
    {/foreach}
    </tbody>
  </table>
{/strip}
