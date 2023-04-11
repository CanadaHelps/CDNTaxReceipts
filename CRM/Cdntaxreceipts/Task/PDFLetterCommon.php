<?php

/**
 * This class provides the common functionality for creating PDF letter for
 * one or a group of contact ids.
 */
use Civi\Token\TokenProcessor;
class CRM_Cdntaxreceipts_Task_PDFLetterCommon extends CRM_Contact_Form_Task_PDFLetterCommon {

  /**
   * Build the form object.
   *
   * @var CRM_Core_Form $form
   */

  /**
   * Process the form after the input has been submitted and validated.
   *
   * @param CRM_Contribute_Form_Task $form
   * @param array $formValues
   */
  public static function postProcessForm(&$form, $formValues = NULL) {
    if (empty($formValues)) {
      $formValues = $form->controller->exportValues($form->getName());
    }
    [$formValues, $categories, $html_message, $messageToken, $returnProperties] = self::processMessageTemplate($formValues);
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
    $task = 'CRM_Contribution_Form_Task_PDFLetterCommon';
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
    $groupBy = $formValues['group_by'];

    // skip some contacts ?
    $skipOnHold = $form->skipOnHold ?? FALSE;
    $skipDeceased = $form->skipDeceased ?? TRUE;
    $contributionIDs = $form->getVar('_contributionIds');

    if ($form->_includesSoftCredits) {
      //@todo - comment on what is stored there
      $contributionIDs = $form->getVar('_contributionContactIds');
    }
    [$contributions, $contacts] = self::buildContributionArray($groupBy, $contributionIDs, $returnProperties, $skipOnHold, $skipDeceased, $messageToken, $task, $separator, $form->_includesSoftCredits);
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
        $html[$contributionId] = self::generateHtml($contact, $contribution, $groupBy, $contributions, $realSeparator, $tableSeparators, $messageToken, $html_message, $separator, $grouped, $groupByID);
        $contactHtml[$contact['contact_id']][] = $html[$contributionId];
        $contact['is_sent'][$groupBy][$groupByID] = TRUE;
      }
    }
    $contactIds = array_keys($contacts);
    self::createActivities($form, $html_message, $contactIds, CRM_Utils_Array::value('subject', $formValues, ts('Thank you letter')), CRM_Utils_Array::value('campaign_id', $formValues), $contactHtml);
    if (!empty($formValues['is_unit_test'])) {
      return $html;
    }
    if (!empty($html)) {
      return $html;
    }
    return false;
  }

  /**
   * Check whether any of the tokens exist in the html outside a table cell.
   * If they do the table cell separator is not supported (return false)
   * At this stage we are only anticipating contributions passed in this way but
   * it would be easy to add others
   * @param $tokens
   * @param $html
   *
   * @return bool
   */
  public static function isValidHTMLWithTableSeparator($tokens, $html) {
    $relevantEntities = ['contribution'];
    foreach ($relevantEntities as $entity) {
      if (isset($tokens[$entity]) && is_array($tokens[$entity])) {
        foreach ($tokens[$entity] as $token) {
          if (!self::isHtmlTokenInTableCell($token, $entity, $html)) {
            return FALSE;
          }
        }
      }
    }
    return TRUE;
  }

  /**
   * Check that the token only appears in a table cell. The '</td><td>' separator cannot otherwise work
   * Calculate the number of times it appears IN the cell & the number of times it appears - should be the same!
   *
   * @param string $token
   * @param string $entity
   * @param string $textToSearch
   *
   * @return bool
   */
  public static function isHtmlTokenInTableCell($token, $entity, $textToSearch) {
    $tokenToMatch = $entity . '\.' . $token;
    $pattern = '|<td(?![\w-])((?!</td>).)*\{' . $tokenToMatch . '\}.*?</td>|si';
    $within = preg_match_all($pattern, $textToSearch);
    $total = preg_match_all("|{" . $tokenToMatch . "}|", $textToSearch);
    return ($within == $total);
  }

  /**
   *
   * @param string $html_message
   * @param array $contact
   * @param array $contribution
   * @param array $messageToken
   * @param bool $grouped
   *   Does this letter represent more than one contribution.
   * @param string $separator
   *   What is the preferred letter separator.
   * @param array $contributions
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  public static function resolveTokens(string $html_message, $contact, $contribution, $messageToken, $grouped, $separator, $contributions): string {
    //CRM-1792 Refactored resolveTokens for replacing traditional token replacement method to token processor
    $tokenContext = [
      'smarty' => (defined('CIVICRM_MAIL_SMARTY') && CIVICRM_MAIL_SMARTY),
      'contactId' => $contact['contact_id'],
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
    $tokenContext['contributionId'] = $contribution['contribution_id'];
    $html_message = CRM_Core_TokenSmarty::render(['html' => $html_message], $tokenContext)['html'];
    return $html_message;
  }
  // This function performs sum of grouped contribution token values for tokens such as 'total_amount','net_amount','fee_amount','non_deductible_amount';
  public static function tokenGroupSum($token, $resolvedTokens) {
    $currencySymbol = CRM_Core_BAO_Country::defaultCurrencySymbol();
    $totalArray = [];
    $setElement = array_key_first($resolvedTokens[$token]);
      foreach ($resolvedTokens[$token] as $key=>$tokenVal) {
        $totalArray[] =  str_replace(',', "",str_replace($currencySymbol, "",$tokenVal));
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

  /**
   * Generate the contribution array from the form, we fill in the contact details and determine any aggregation
   * around contact_id of contribution_recur_id
   *
   * @param string $groupBy
   * @param array $contributionIDs
   * @param array $returnProperties
   * @param bool $skipOnHold
   * @param bool $skipDeceased
   * @param array $messageToken
   * @param string $task
   * @param string $separator
   * @param bool $isIncludeSoftCredits
   *
   * @return array
   */
  public static function buildContributionArray($groupBy, $contributionIDs, $returnProperties, $skipOnHold, $skipDeceased, $messageToken, $task, $separator, $isIncludeSoftCredits) {
    $contributions = $contacts = [];
    foreach ($contributionIDs as $item => $contributionId) {
      $contribution = CRM_Contribute_BAO_Contribution::getContributionTokenValues($contributionId, $messageToken)['values'][$contributionId];
      $contribution['campaign'] = $contribution['contribution_campaign_title'] ?? NULL;
      $contributions[$contributionId] = $contribution;

      if ($isIncludeSoftCredits) {
        //@todo find out why this happens & add comments
        [$contactID] = explode('-', $item);
        $contactID = (int) $contactID;
      }
      else {
        $contactID = $contribution['contact_id'];
      }
      if (!isset($contacts[$contactID])) {
        $contacts[$contactID] = [];
        $contacts[$contactID]['contact_aggregate'] = 0;
        $contacts[$contactID]['combined'] = $contacts[$contactID]['contribution_ids'] = [];
      }

      $contacts[$contactID]['contact_aggregate'] += $contribution['total_amount'];
      $groupByID = empty($contribution[$groupBy]) ? 0 : $contribution[$groupBy];

      $contacts[$contactID]['contribution_ids'][$groupBy][$groupByID][$contributionId] = TRUE;
      if (!isset($contacts[$contactID]['combined'][$groupBy]) || !isset($contacts[$contactID]['combined'][$groupBy][$groupByID])) {
        $contacts[$contactID]['combined'][$groupBy][$groupByID] = $contribution;
        $contacts[$contactID]['aggregates'][$groupBy][$groupByID] = $contribution['total_amount'];
      }
      else {
        $contacts[$contactID]['combined'][$groupBy][$groupByID] = self::combineContributions($contacts[$contactID]['combined'][$groupBy][$groupByID], $contribution, $separator);
        $contacts[$contactID]['aggregates'][$groupBy][$groupByID] += $contribution['total_amount'];
      }
    }
    // Assign the available contributions before calling tokens so hooks parsing smarty can access it.
    // Note that in core code you can only use smarty here if enable if for the whole site, incl
    // CiviMail, with a big performance impact.
    // Hooks allow more nuanced smarty usage here.
    CRM_Core_Smarty::singleton()->assign('contributions', $contributions);
    foreach ($contacts as $contactID => $contact) {
      [$tokenResolvedContacts] = CRM_Utils_Token::getTokenDetails(['contact_id' => $contactID],
        $returnProperties,
        $skipOnHold,
        $skipDeceased,
        NULL,
        $messageToken,
        $task
      );
      $contacts[$contactID] = array_merge($tokenResolvedContacts[$contactID], $contact);
    }
    return [$contributions, $contacts];
  }

  /**
   * We combine the contributions by adding the contribution to each field with the separator in
   * between the existing value and the new one. We put the separator there even if empty so it is clear what the
   * value for previous contributions was
   *
   * @param array $existing
   * @param array $contribution
   * @param string $separator
   *
   * @return array
   */
  public static function combineContributions($existing, $contribution, $separator) {
    foreach ($contribution as $field => $value) {
      $existing[$field] = isset($existing[$field]) ? $existing[$field] . $separator : '';
      $existing[$field] .= $value;
    }
    return $existing;
  }

  /**
   * We are going to retrieve the combined contribution and if smarty mail is enabled we
   * will also assign an array of contributions for this contact to the smarty template
   *
   * @param array $contact
   * @param array $contributions
   * @param $groupBy
   * @param int $groupByID
   */
  public static function assignCombinedContributionValues($contact, $contributions, $groupBy, $groupByID) {
    CRM_Core_Smarty::singleton()->assign('contact_aggregate', $contact['contact_aggregate']);
    CRM_Core_Smarty::singleton()
      ->assign('contributions', $contributions);
    CRM_Core_Smarty::singleton()->assign('contribution_aggregate', $contact['aggregates'][$groupBy][$groupByID]);

  }

  /**
   * Send pdf by email.
   *
   * @param array $contact
   * @param string $html
   *
   * @param $is_pdf
   * @param array $format
   * @param array $params
   *
   * @return bool
   */
  public static function emailLetter($contact, $html, $is_pdf, $format = [], $params = []) {
    try {
      if (empty($contact['email'])) {
        return FALSE;
      }
      $mustBeEmpty = ['do_not_email', 'is_deceased', 'on_hold'];
      foreach ($mustBeEmpty as $emptyField) {
        if (!empty($contact[$emptyField])) {
          return FALSE;
        }
      }

      $defaults = [
        'toName' => $contact['display_name'],
        'toEmail' => $contact['email'],
        'text' => '',
        'html' => $html,
      ];
      if (empty($params['from'])) {
        $emails = CRM_Core_BAO_Email::getFromEmail();
        $emails = array_keys($emails);
        $defaults['from'] = array_pop($emails);
      }
      else {
        $defaults['from'] = $params['from'];
      }
      if (!empty($params['subject'])) {
        $defaults['subject'] = $params['subject'];
      }
      else {
        $defaults['subject'] = ts('Thank you for your contribution/s');
      }
      if ($is_pdf) {
        $defaults['html'] = ts('Please see attached');
        $defaults['attachments'] = [CRM_Utils_Mail::appendPDF('ThankYou.pdf', $html, $format)];
      }
      $params = array_merge($defaults);
      return CRM_Utils_Mail::send($params);
    }
    catch (CRM_Core_Exception $e) {
      return FALSE;
    }
  }

  /**
   * @param $contact
   * @param $formValues
   * @param $contribution
   * @param $groupBy
   * @param $contributions
   * @param $realSeparator
   * @param $tableSeparators
   * @param $messageToken
   * @param $html_message
   * @param $separator
   * @param $categories
   * @param bool $grouped
   * @param int $groupByID
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  public static function generateHtml(&$contact, $contribution, $groupBy, $contributions, $realSeparator, $tableSeparators, $messageToken, $html_message, $separator, $grouped, $groupByID) {
    static $validated = FALSE;
    $html = NULL;

    $groupedContributions = array_intersect_key($contributions, $contact['contribution_ids'][$groupBy][$groupByID]);
    self::assignCombinedContributionValues($contact, $groupedContributions, $groupBy, $groupByID);

    if (empty($groupBy) || empty($contact['is_sent'][$groupBy][$groupByID])) {
      if (!$validated && in_array($realSeparator, $tableSeparators) && !self::isValidHTMLWithTableSeparator($messageToken, $html_message)) {
        $realSeparator = ', ';
        CRM_Core_Session::setStatus(ts('You have selected the table cell separator, but one or more token fields are not placed inside a table cell. This would result in invalid HTML, so comma separators have been used instead.'));
      }
      $validated = TRUE;
      $html = str_replace($separator, $realSeparator, self::resolveTokens($html_message, $contact, $contribution, $messageToken, $grouped, $separator, $groupedContributions));
    }

    return $html;
  }

  public static function listTokens() {
    $tokens = CRM_Core_SelectValues::contactTokens();
    $tokens = array_merge(CRM_Core_SelectValues::contributionTokens(), $tokens);
    $tokens = array_merge(CRM_Core_SelectValues::domainTokens(), $tokens);
    return $tokens;
  }


  /**
   * Get the categories required for rendering tokens.
   *
   * @return array
   */
  public static function getTokenCategories() {
    if (!isset(Civi::$statics[__CLASS__]['token_categories'])) {
      $tokens = [];
      CRM_Utils_Hook::tokens($tokens);
      Civi::$statics[__CLASS__]['token_categories'] = array_keys($tokens);
    }
    return Civi::$statics[__CLASS__]['token_categories'];
  }

}
