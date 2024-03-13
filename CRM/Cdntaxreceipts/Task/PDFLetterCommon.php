<?php

/**
 * This class provides the common functionality for creating PDF letter for
 * one or a group of contact ids.
 */
use Civi\Token\TokenProcessor;
class CRM_Cdntaxreceipts_Task_PDFLetterCommon extends CRM_Contribute_Form_Task_PDFLetter {
    
    /**
     * Get the token processor schema required to list any tokens for this task.
     *
     * @return array
     */
    function getTokenSchema(): array {
        return ['contactId','contributionId'];
    }

    //get thankyou HTML
    function getThankYouHTML(&$form){
        $this->controller = $form->controller;
        $this->_name = $form->_name;
        $this->_contributionIds = $form->_contributionIds;
        $this->ids = $form->_contributionIds;
        return $this->postProcess();
    }

    /**
   *
   * @param string $html_message
   * @param int $contactID
   * @param int $contributionID
   * @param bool $grouped
   *   Does this letter represent more than one contribution.
   * @param string $separator
   *   What is the preferred letter separator.
   * @param array $contributions
   *
   * @return string
   */
  public function replaceTokens(string $html_message, int $contactID, $contributionID, $grouped, $separator, $contributions): string {
    return $this->resolveTokens($html_message,$contactID, $contributionID, $grouped, $separator, $contributions);
  }

  /**
   * Process the form after the input has been submitted and validated.
   * Copied from CRM_Contribute_Form_Task_PDFLetter 
   *
   * @throws \CRM_Core_Exception
   */
  public function postProcess() {
    $formValues = $this->controller->exportValues($this->getName());
    [$formValues, $html_message] = $this->processMessageTemplate($formValues);
    
    $messageToken = CRM_Utils_Token::getTokens($html_message);

    $returnProperties = [];
    if (isset($messageToken['contact'])) {
      foreach ($messageToken['contact'] as $key => $value) {
        $returnProperties[$value] = 1;
      }
    }

    $isPDF = FALSE;
    $emailParams = [];
    if (!empty($formValues['email_options'])) {
      $returnProperties['email'] = $returnProperties['on_hold'] = $returnProperties['is_deceased'] = $returnProperties['do_not_email'] = 1;
      $emailParams = [
        'subject' => $formValues['subject'] ?? NULL,
        'from' => $formValues['from_email_address'] ?? NULL,
      ];

      $emailParams['from'] = CRM_Utils_Mail::formatFromAddress($emailParams['from']);
      // We need display_name for emailLetter() so add to returnProperties here
      $returnProperties['display_name'] = 1;
      if (stristr($formValues['email_options'], 'pdfemail')) {
        $isPDF = TRUE;
      }
    }
    // update dates ?
    $receipt_update = $formValues['receipt_update'] ?? FALSE;
    $thankyou_update = $formValues['thankyou_update'] ?? FALSE;
    $nowDate = date('YmdHis');
    $receipts = $thanks = $emailed = 0;
    $updateStatus = '';
    $realSeparator = ', ';
    $tableSeparators = [
      'td' => '</td><td>',
      'tr' => '</td></tr><tr><td>',
    ];
    //the original thinking was mutliple options - but we are going with only 2 (comma & td) for now in case
    // there are security (& UI) issues we need to think through
    if (isset($formValues['group_by_separator'])) {
      if (in_array($formValues['group_by_separator'], ['td', 'tr'])) {
        $realSeparator = $tableSeparators[$formValues['group_by_separator']];
      }
      elseif ($formValues['group_by_separator'] == 'br') {
        $realSeparator = "<br />";
      }
    }
    // a placeholder in case the separator is common in the string - e.g ', '
    $separator = '****~~~~';
    $groupBy = $this->getSubmittedValue('group_by');
    $contributionIDs = $this->getIDs();
    if ($this->isQueryIncludesSoftCredits()) {
      $contributionIDs = [];
      $result = $this->getSearchQueryResults();
      while ($result->fetch()) {
        $this->_contactIds[$result->contact_id] = $result->contact_id;
        $contributionIDs["{$result->contact_id}-{$result->contribution_id}"] = $result->contribution_id;
      }
    }
    [$contributions, $contacts] = $this->buildContributionArray($groupBy, $contributionIDs, $returnProperties, $messageToken, $separator, $this->isQueryIncludesSoftCredits());
    $html = [];
    $contactHtml = $emailedHtml = [];
    foreach ($contributions as $contributionId => $contribution) {
      $contact = &$contacts[$contribution['contact_id']];
      $grouped = FALSE;
      $groupByID = 0;
      if ($groupBy) {
        $groupByID = empty($contribution[$groupBy]) ? 0 : $contribution[$groupBy];
        $contribution = $contact['combined'][$groupBy][$groupByID];
        $grouped = TRUE;
      }
      if (empty($groupBy) || empty($contact['is_sent'][$groupBy][$groupByID])) {
        $html[$contributionId] = $this->generateHtml($contact, $contribution, $groupBy, $contributions, $realSeparator, $tableSeparators, $messageToken, $html_message, $separator, $grouped, $groupByID);
      }
    }
    $contactIds = array_keys($contacts);
    // CRM-16725 Skip creation of activities if user is previewing their PDF letter(s)
    if ($this->isLiveMode()) {
      $this->createActivities($html_message, $contactIds, CRM_Utils_Array::value('subject', $formValues, ts('Thank you letter')), CRM_Utils_Array::value('campaign_id', $formValues), $contactHtml);
    }
    $html = array_diff_key($html, $emailedHtml);
    $this->postProcessHook();
    if (!empty($html)) {
      return $html;
    }
    return false;
  }

    /**
   *
   * @param string $html_message
   * @param int $contactID
   * @param int $contributionID
   * @param bool $grouped
   *   Does this letter represent more than one contribution.
   * @param string $separator
   *   What is the preferred letter separator.
   * @param array $contributions
   *
   * @return string
   * Copied from CRM_Contribute_Form_Task_PDFLetter
   * Reason for overriding : For aggregated and Annual tax receipt few token values (total_amount,net_amount etc ) were fetching incorrect data
   */
  public function resolveTokens(string $html_message, int $contactID, $contributionID, $grouped, $separator, $contributions): string {
    $tokenContext = [
      'smarty' => (defined('CIVICRM_MAIL_SMARTY') && CIVICRM_MAIL_SMARTY),
      'contactId' => $contactID,
      'schema' => ['contributionId'],
    ];
    if ($grouped) {
      // First replace the contribution tokens. These are pretty ... special.
      // if the text looks like `<td>{contribution.currency} {contribution.total_amount}</td>'
      // and there are 2 rows with a currency separator of
      // you wind up with a string like
      // '<td>USD</td><td>USD></td> <td>$50</td><td>$89</td>
      // see https://docs.civicrm.org/user/en/latest/contributions/manual-receipts-and-thank-yous/#grouped-contribution-thank-you-letters
      $tokenProcessor = new TokenProcessor(\Civi::dispatcher(), $tokenContext);
      $contributionTokens = CRM_Utils_Token::getTokens($html_message)['contribution'] ?? [];
      foreach ($contributionTokens as $token) {
        $tokenProcessor->addMessage($token, '{contribution.' . $token . '}', 'text/html');
      }
      foreach ($contributions as $contribution) {
        $tokenProcessor->addRow([
          'contributionId' => $contribution['id'],
          'contribution' => $contribution,
        ]);
      }
      $tokenProcessor->evaluate();
      $resolvedTokens = [];
      foreach ($contributionTokens as $token) {
        foreach ($tokenProcessor->getRows() as $row) {
          $resolvedTokens[$token][$row->context['contributionId']] = $row->render($token);
        }
        switch ($token) {
          case 'total_amount':
          case 'net_amount':
          case 'fee_amount':
          case 'non_deductible_amount':
            $resolvedTokens = self::tokenGroupSum($token,$resolvedTokens);
            break;
          case 'contribution_status_id:label':
            break;
          case 'source':
            break;
          default:
          $setElement = array_key_first($resolvedTokens[$token]);
          foreach ($resolvedTokens[$token] as $key => $tokenVal) {
            if($key !== $setElement){
              unset($resolvedTokens[$token][$key]);
            }
          }
        }
        $html_message = str_replace('{contribution.' . $token . '}', implode($separator, $resolvedTokens[$token]), $html_message);
      }
    }
    $tokenContext['contributionId'] = $contributionID;
    return CRM_Core_TokenSmarty::render(['html' => $html_message], $tokenContext)['html'];
  }
  // This function performs sum of grouped contribution token values for tokens such as 'total_amount','net_amount','fee_amount','non_deductible_amount';
  public static function tokenGroupSum($token, $resolvedTokens) {
    $currencySymbol = CRM_Core_BAO_Country::defaultCurrencySymbol();
    $totalArray = [];
    $setElement = array_key_first($resolvedTokens[$token]);
      foreach ($resolvedTokens[$token] as $key=>$tokenVal) {
        $res = preg_replace("/[^0-9.]/", "", $tokenVal);
        $totalArray[] =  str_replace(',', "",$res);
      }
      $finalValue = $currencySymbol.' '.number_format(array_sum($totalArray), 2, '.',',');
      $resolvedTokens[$token][$setElement] = $finalValue;
      foreach ($resolvedTokens[$token] as $key=>$tokenVal) {
        if($key !== $setElement){
          unset($resolvedTokens[$token][$key]);
        }
      }
    return $resolvedTokens;
  }
}