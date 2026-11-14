-- Deposit products/accounts, payments with allocations and receipts, agent cash handover, field visits.

CREATE TABLE deposit_products (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code                VARCHAR(20)  NOT NULL,
    name                VARCHAR(150) NOT NULL,
    description         VARCHAR(500) NULL,
    status              ENUM('active','inactive') NOT NULL DEFAULT 'active',
    current_version_id  BIGINT UNSIGNED NULL,
    created_by          BIGINT UNSIGNED NOT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_deposit_products_code (code),
    CONSTRAINT fk_deposit_products_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE deposit_product_versions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id      BIGINT UNSIGNED NOT NULL,
    version         INT UNSIGNED NOT NULL,
    terms_json      JSON NOT NULL,
    effective_from  DATETIME NOT NULL,
    change_reason   VARCHAR(500) NOT NULL,
    created_by      BIGINT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dpv_product_version (product_id, version),
    CONSTRAINT fk_dpv_product FOREIGN KEY (product_id) REFERENCES deposit_products(id),
    CONSTRAINT fk_dpv_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE deposit_products
    ADD CONSTRAINT fk_deposit_products_current_version
    FOREIGN KEY (current_version_id) REFERENCES deposit_product_versions(id);

-- Balance columns are a subledger projection kept in the same transaction as the journal.
CREATE TABLE deposit_accounts (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    deposit_number      VARCHAR(20) NOT NULL,
    customer_id         BIGINT UNSIGNED NOT NULL,
    branch_id           BIGINT UNSIGNED NOT NULL,
    product_id          BIGINT UNSIGNED NOT NULL,
    product_version_id  BIGINT UNSIGNED NOT NULL,
    scheme              ENUM('recurring','fixed') NOT NULL,
    installment_amount  DECIMAL(19,4) NULL,
    deposit_amount      DECIMAL(19,4) NULL,
    opened_on           DATE NOT NULL,
    maturity_date       DATE NOT NULL,
    status              ENUM('active','matured','pending_closure','closed') NOT NULL DEFAULT 'active',
    principal_balance   DECIMAL(19,4) NOT NULL DEFAULT 0,
    accrued_interest    DECIMAL(19,4) NOT NULL DEFAULT 0,
    interest_paid       DECIMAL(19,4) NOT NULL DEFAULT 0,
    last_accrual_date   DATE NULL,
    opened_by           BIGINT UNSIGNED NOT NULL,
    closed_at           DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_deposit_accounts_number (deposit_number),
    KEY ix_deposit_accounts_customer (customer_id),
    KEY ix_deposit_accounts_status (status, maturity_date),
    CONSTRAINT fk_da_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_da_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_da_product FOREIGN KEY (product_id) REFERENCES deposit_products(id),
    CONSTRAINT fk_da_version FOREIGN KEY (product_version_id) REFERENCES deposit_product_versions(id),
    CONSTRAINT fk_da_opened_by FOREIGN KEY (opened_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE deposit_accruals (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    deposit_account_id  BIGINT UNSIGNED NOT NULL,
    from_date           DATE NOT NULL,
    to_date             DATE NOT NULL,
    amount              DECIMAL(19,4) NOT NULL,
    -- NULL briefly while the daily job batches accruals into one entry per branch.
    journal_entry_id    BIGINT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_accruals_account (deposit_account_id),
    CONSTRAINT fk_accruals_account FOREIGN KEY (deposit_account_id) REFERENCES deposit_accounts(id),
    CONSTRAINT fk_accruals_entry FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id)
) ENGINE=InnoDB;

CREATE TABLE payout_requests (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payout_number        VARCHAR(20) NOT NULL,
    deposit_account_id   BIGINT UNSIGNED NOT NULL,
    branch_id            BIGINT UNSIGNED NOT NULL,
    payout_type          ENUM('maturity','premature') NOT NULL,
    principal_amount     DECIMAL(19,4) NOT NULL,
    interest_amount      DECIMAL(19,4) NOT NULL,
    penalty_amount       DECIMAL(19,4) NOT NULL,
    net_amount           DECIMAL(19,4) NOT NULL,
    previous_status      ENUM('active','matured') NOT NULL,
    status               ENUM('requested','approved','rejected','paid') NOT NULL DEFAULT 'requested',
    request_note         VARCHAR(500) NULL,
    requested_by         BIGINT UNSIGNED NOT NULL,
    requested_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_by           BIGINT UNSIGNED NULL,
    decided_at           DATETIME NULL,
    decision_reason      VARCHAR(500) NULL,
    paid_by              BIGINT UNSIGNED NULL,
    paid_at              DATETIME NULL,
    payment_method       ENUM('cash','bank_transfer','upi','cheque') NULL,
    payment_reference    VARCHAR(100) NULL,
    journal_entry_id     BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_payouts_number (payout_number),
    KEY ix_payouts_status (status),
    CONSTRAINT fk_payouts_account FOREIGN KEY (deposit_account_id) REFERENCES deposit_accounts(id),
    CONSTRAINT fk_payouts_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_payouts_requested_by FOREIGN KEY (requested_by) REFERENCES users(id),
    CONSTRAINT fk_payouts_decided_by FOREIGN KEY (decided_by) REFERENCES users(id),
    CONSTRAINT fk_payouts_paid_by FOREIGN KEY (paid_by) REFERENCES users(id),
    CONSTRAINT fk_payouts_entry FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cash_handovers (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    handover_number   VARCHAR(20) NOT NULL,
    agent_id          BIGINT UNSIGNED NOT NULL,
    branch_id         BIGINT UNSIGNED NOT NULL,
    business_date     DATE NOT NULL,
    expected_cash     DECIMAL(19,4) NOT NULL,
    declared_cash     DECIMAL(19,4) NOT NULL,
    verified_cash     DECIMAL(19,4) NULL,
    difference        DECIMAL(19,4) NULL,
    status            ENUM('submitted','verified','discrepancy','resolved') NOT NULL DEFAULT 'submitted',
    agent_note        VARCHAR(500) NULL,
    submitted_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verified_by       BIGINT UNSIGNED NULL,
    verified_at       DATETIME NULL,
    verifier_note     VARCHAR(500) NULL,
    resolution        ENUM('recovered','written_off','overage_income') NULL,
    resolution_note   VARCHAR(500) NULL,
    resolved_by       BIGINT UNSIGNED NULL,
    resolved_at       DATETIME NULL,
    UNIQUE KEY uq_handovers_number (handover_number),
    UNIQUE KEY uq_handovers_agent_date (agent_id, business_date),
    KEY ix_handovers_status (status),
    CONSTRAINT fk_handovers_agent FOREIGN KEY (agent_id) REFERENCES users(id),
    CONSTRAINT fk_handovers_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_handovers_verified_by FOREIGN KEY (verified_by) REFERENCES users(id),
    CONSTRAINT fk_handovers_resolved_by FOREIGN KEY (resolved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_number      VARCHAR(20)  NOT NULL,
    idempotency_key     VARCHAR(100) NOT NULL,
    request_hash        CHAR(64)     NOT NULL,
    customer_id         BIGINT UNSIGNED NOT NULL,
    branch_id           BIGINT UNSIGNED NOT NULL,
    account_type        ENUM('loan','deposit') NOT NULL,
    loan_id             BIGINT UNSIGNED NULL,
    deposit_account_id  BIGINT UNSIGNED NULL,
    purpose             ENUM('installment','settlement','contribution') NOT NULL,
    amount              DECIMAL(19,4) NOT NULL,
    method              ENUM('cash','upi','bank_transfer','cheque') NOT NULL,
    reference           VARCHAR(100) NULL,
    status              ENUM('pending','confirmed','failed','reversed') NOT NULL,
    business_date       DATE NOT NULL,
    collected_by        BIGINT UNSIGNED NOT NULL,
    collected_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_by        BIGINT UNSIGNED NULL,
    confirmed_at        DATETIME NULL,
    journal_entry_id    BIGINT UNSIGNED NULL,
    handover_id         BIGINT UNSIGNED NULL,
    status_reason       VARCHAR(500) NULL,
    reversed_by         BIGINT UNSIGNED NULL,
    reversed_at         DATETIME NULL,
    reversal_entry_id   BIGINT UNSIGNED NULL,
    notes               VARCHAR(500) NULL,
    UNIQUE KEY uq_payments_number (payment_number),
    UNIQUE KEY uq_payments_idempotency (idempotency_key),
    UNIQUE KEY uq_payments_reference (method, reference),
    KEY ix_payments_collector_date (collected_by, business_date),
    KEY ix_payments_date (business_date),
    KEY ix_payments_loan (loan_id),
    KEY ix_payments_deposit (deposit_account_id),
    KEY ix_payments_customer (customer_id),
    CONSTRAINT fk_payments_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_payments_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_payments_loan FOREIGN KEY (loan_id) REFERENCES loans(id),
    CONSTRAINT fk_payments_deposit FOREIGN KEY (deposit_account_id) REFERENCES deposit_accounts(id),
    CONSTRAINT fk_payments_collected_by FOREIGN KEY (collected_by) REFERENCES users(id),
    CONSTRAINT fk_payments_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES users(id),
    CONSTRAINT fk_payments_reversed_by FOREIGN KEY (reversed_by) REFERENCES users(id),
    CONSTRAINT fk_payments_entry FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id),
    CONSTRAINT fk_payments_reversal_entry FOREIGN KEY (reversal_entry_id) REFERENCES journal_entries(id),
    CONSTRAINT fk_payments_handover FOREIGN KEY (handover_id) REFERENCES cash_handovers(id),
    CONSTRAINT ck_payments_target CHECK (
        (account_type = 'loan' AND loan_id IS NOT NULL AND deposit_account_id IS NULL)
        OR (account_type = 'deposit' AND deposit_account_id IS NOT NULL AND loan_id IS NULL)
    ),
    CONSTRAINT ck_payments_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- interest_waived rows record future interest forgiven on settlement, so a reversal can restore it.
CREATE TABLE payment_allocations (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id      BIGINT UNSIGNED NOT NULL,
    installment_id  BIGINT UNSIGNED NULL,
    component       ENUM('late_fee','fees','interest','principal','prepayment_charge','deposit','interest_waived') NOT NULL,
    amount          DECIMAL(19,4) NOT NULL,
    KEY ix_allocations_payment (payment_id),
    CONSTRAINT fk_allocations_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
    CONSTRAINT fk_allocations_installment FOREIGN KEY (installment_id) REFERENCES loan_installments(id)
) ENGINE=InnoDB;

CREATE TABLE receipts (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receipt_number     VARCHAR(20) NOT NULL,
    payment_id         BIGINT UNSIGNED NOT NULL,
    verification_code  CHAR(10) NOT NULL,
    issued_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_receipts_number (receipt_number),
    UNIQUE KEY uq_receipts_payment (payment_id),
    UNIQUE KEY uq_receipts_code (verification_code),
    CONSTRAINT fk_receipts_payment FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB;

CREATE TABLE collection_visits (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id    BIGINT UNSIGNED NOT NULL,
    agent_id       BIGINT UNSIGNED NOT NULL,
    loan_id        BIGINT UNSIGNED NULL,
    visit_date     DATE NOT NULL,
    outcome        ENUM('paid','partial','promised','not_available','refused','other') NOT NULL,
    promise_date   DATE NULL,
    notes          VARCHAR(500) NULL,
    payment_id     BIGINT UNSIGNED NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_visits_agent_date (agent_id, visit_date),
    KEY ix_visits_customer (customer_id),
    CONSTRAINT fk_visits_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_visits_agent FOREIGN KEY (agent_id) REFERENCES users(id),
    CONSTRAINT fk_visits_loan FOREIGN KEY (loan_id) REFERENCES loans(id),
    CONSTRAINT fk_visits_payment FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
