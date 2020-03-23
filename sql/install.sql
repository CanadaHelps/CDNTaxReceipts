-- CDN Tax Receipts Extension
-- last change: 0.9.beta1

-- NOTE: We avoid direct foreign keys to CiviCRM schema because this log should
-- remain intact even if a particular contact or contribution is deleted (for
-- auditing purposes).

DROP TABLE IF EXISTS cdntaxreceipts_log_contributions;
DROP TABLE IF EXISTS cdntaxreceipts_log;

--
-- Table structure for table `cdntaxreceipts_log`
--

CREATE TABLE cdntaxreceipts_log (
  id int(11) NOT NULL AUTO_INCREMENT COMMENT 'The internal id of the issuance.',
  receipt_no varchar(128) NOT NULL  COMMENT 'Receipt Number.',
  issued_on int(11) NOT NULL COMMENT 'Unix timestamp of when the receipt was issued, or re-issued.',
  contact_id int(10) unsigned NOT NULL COMMENT 'CiviCRM contact id to whom the receipt is issued.',
  receipt_amount decimal(10,2) NOT NULL COMMENT 'Receiptable amount, total minus non-receiptable portion.',
  is_duplicate tinyint(4) NOT NULL COMMENT 'Boolean indicating whether this is a re-issue.',
  uid int(10) unsigned NOT NULL COMMENT 'Drupal user id of the person issuing the receipt.',
  ip varchar(128) NOT NULL COMMENT 'IP of the user who issued the receipt.',
  issue_type varchar(16) NOT NULL COMMENT 'The type of receipt (single or annual).',
  issue_method varchar(16) NULL COMMENT 'The send method (email or print).',
  receipt_status varchar(10) DEFAULT 'issued' COMMENT 'The status of the receipt (issued or cancelled)',
  email_tracking_id varchar(64) NULL COMMENT 'A unique id to track email opens.',
  email_opened datetime NULL COMMENT 'Timestamp an email open event was detected.',
  PRIMARY KEY (id),
  INDEX contact_id (contact_id),
  INDEX receipt_no (receipt_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Log file of tax receipt issuing.';


--
-- Table structure for table `cdntaxreceipts_log_contributions`
-- The contribution_id is *deliberately* not a foreign key to civicrm_contribution.
-- We don't want to destroy audit records if contributions are deleted.
--

CREATE TABLE cdntaxreceipts_log_contributions (
  id int(11) NOT NULL AUTO_INCREMENT COMMENT 'The internal id of this line.',
  receipt_id int(11) NOT NULL COMMENT 'The internal receipt ID this line belongs to.',
  contribution_id int(10) unsigned NOT NULL COMMENT 'CiviCRM contribution id for which the receipt is issued.',
  contribution_amount decimal(10,2) DEFAULT NULL COMMENT 'Total contribution amount.',
  receipt_amount decimal(10,2) NOT NULL COMMENT 'Receiptable amount, total minus non-receiptable portion.',
  receive_date datetime NOT NULL COMMENT 'Date on which the contribution was received, redundant information!',
  PRIMARY KEY (id),
  FOREIGN KEY (receipt_id) REFERENCES cdntaxreceipts_log(id),
  INDEX contribution_id (contribution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Contributions for each tax reciept issuing.';

CREATE TABLE `cdntaxreceipts_advantage` (
  `id` int(11) UNSIGNED NOT NULL,
  `contribution_id` int(10) UNSIGNED NOT NULL,
  `advantage_description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (id),
  INDEX contribution_id (contribution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

