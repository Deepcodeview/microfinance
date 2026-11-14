-- Plans can offer several tenures ("45 days", "3 months", "1 year"); the one chosen is kept on the account.

ALTER TABLE deposit_accounts
    ADD COLUMN tenure_value INT UNSIGNED NULL AFTER maturity_date,
    ADD COLUMN tenure_unit ENUM('days','weeks','months','years') NULL AFTER tenure_value;

-- Savings applications from the customer app: tenure + tenure_unit (loans keep tenure = installments).
ALTER TABLE customer_applications
    ADD COLUMN tenure_unit ENUM('days','weeks','months','years') NULL AFTER tenure;

-- Existing savings plans: their single tenure_months becomes the plan's one tenure choice.
UPDATE deposit_product_versions
   SET terms_json = JSON_SET(terms_json, '$.tenure_options',
       JSON_ARRAY(JSON_OBJECT('value', CAST(JSON_UNQUOTE(JSON_EXTRACT(terms_json, '$.tenure_months')) AS UNSIGNED), 'unit', 'months')))
 WHERE JSON_EXTRACT(terms_json, '$.tenure_options') IS NULL
   AND JSON_EXTRACT(terms_json, '$.tenure_months') IS NOT NULL;

-- Existing accounts keep their maturity date; record the tenure they were opened with.
UPDATE deposit_accounts d
  JOIN deposit_product_versions v ON v.id = d.product_version_id
   SET d.tenure_value = CAST(JSON_UNQUOTE(JSON_EXTRACT(v.terms_json, '$.tenure_months')) AS UNSIGNED),
       d.tenure_unit = 'months'
 WHERE d.tenure_value IS NULL
   AND JSON_EXTRACT(v.terms_json, '$.tenure_months') IS NOT NULL;
