-- Core banking controls: accrual accounting, IRACP asset classification, provisioning, write-off,
-- maker-checker approvals, settlement reconciliation and day-end close.

-- Interest recognised as income per installment (accrued on due date for performing loans,
-- on receipt for NPAs). Together with interest_paid this drives Interest receivable / Advance interest.
ALTER TABLE loan_installments
    ADD COLUMN interest_accrued DECIMAL(19,4) NOT NULL DEFAULT 0 AFTER interest_waived,
    ADD COLUMN accrued_at DATETIME NULL AFTER interest_accrued;

ALTER TABLE loans
    MODIFY status ENUM('pending_approval','approved','rejected','active','closed','cancelled','written_off') NOT NULL DEFAULT 'pending_approval',
    ADD COLUMN asset_class ENUM('standard','sma_0','sma_1','sma_2','substandard','doubtful','loss') NOT NULL DEFAULT 'standard' AFTER status,
    ADD COLUMN dpd INT UNSIGNED NOT NULL DEFAULT 0 AFTER asset_class,
    ADD COLUMN npa_since DATE NULL AFTER dpd,
    ADD COLUMN classified_at DATETIME NULL AFTER npa_since,
    ADD COLUMN written_off_at DATETIME NULL AFTER closure_type,
    ADD COLUMN written_off_amount DECIMAL(19,4) NULL AFTER written_off_at,
    ADD KEY ix_loans_asset_class (asset_class);

-- Which ledger account each allocation was credited to, so a reversal can unwind it exactly.
ALTER TABLE payment_allocations
    MODIFY component ENUM('late_fee','fees','interest','principal','prepayment_charge','deposit','interest_waived','interest_recognized','recovery') NOT NULL,
    ADD COLUMN gl_account VARCHAR(10) NULL AFTER amount;

CREATE TABLE settlement_batches (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_number     VARCHAR(20) NOT NULL,
    method           ENUM('upi') NOT NULL,
    bank_reference   VARCHAR(100) NOT NULL,
    settlement_date  DATE NOT NULL,
    gross_amount     DECIMAL(19,4) NOT NULL,
    fees             DECIMAL(19,4) NOT NULL,
    net_amount       DECIMAL(19,4) NOT NULL,
    payment_count    INT UNSIGNED NOT NULL,
    journal_entry_id BIGINT UNSIGNED NOT NULL,
    created_by       BIGINT UNSIGNED NOT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_settlements_number (batch_number),
    UNIQUE KEY uq_settlements_reference (bank_reference),
    CONSTRAINT fk_settlements_entry FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id),
    CONSTRAINT fk_settlements_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payments
    ADD COLUMN settlement_id BIGINT UNSIGNED NULL AFTER handover_id,
    ADD CONSTRAINT fk_payments_settlement FOREIGN KEY (settlement_id) REFERENCES settlement_batches(id);

-- Generic maker-checker queue. The action runs only when a different, authorised user approves.
CREATE TABLE approval_requests (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_number  VARCHAR(20) NOT NULL,
    type            ENUM('payment_reversal','loan_write_off','manual_journal') NOT NULL,
    entity_type     VARCHAR(40) NULL,
    entity_id       BIGINT UNSIGNED NULL,
    branch_id       BIGINT UNSIGNED NULL,
    amount          DECIMAL(19,4) NULL,
    summary         VARCHAR(255) NOT NULL,
    payload_json    JSON NOT NULL,
    reason          VARCHAR(500) NOT NULL,
    status          ENUM('pending','approved','rejected','cancelled','failed') NOT NULL DEFAULT 'pending',
    requested_by    BIGINT UNSIGNED NOT NULL,
    requested_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_by      BIGINT UNSIGNED NULL,
    decided_at      DATETIME NULL,
    decision_note   VARCHAR(500) NULL,
    result_json     JSON NULL,
    UNIQUE KEY uq_approvals_number (request_number),
    KEY ix_approvals_status (status, type),
    KEY ix_approvals_entity (entity_type, entity_id, status),
    CONSTRAINT fk_approvals_requested_by FOREIGN KEY (requested_by) REFERENCES users(id),
    CONSTRAINT fk_approvals_decided_by FOREIGN KEY (decided_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE provision_runs (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_date          DATE NOT NULL,
    required_amount   DECIMAL(19,4) NOT NULL,
    held_before       DECIMAL(19,4) NOT NULL,
    adjustment        DECIMAL(19,4) NOT NULL,
    detail_json       JSON NOT NULL,
    journal_entry_id  BIGINT UNSIGNED NULL,
    created_by        BIGINT UNSIGNED NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_provision_runs_date (run_date),
    CONSTRAINT fk_provision_entry FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id)
) ENGINE=InnoDB;

CREATE TABLE day_closes (
    business_date  DATE PRIMARY KEY,
    closed_by      BIGINT UNSIGNED NOT NULL,
    closed_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    checks_json    JSON NOT NULL,
    CONSTRAINT fk_day_closes_user FOREIGN KEY (closed_by) REFERENCES users(id)
) ENGINE=InnoDB;
