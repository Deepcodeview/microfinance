-- Settings, double-entry ledger, loan accounts and repayment schedules.

CREATE TABLE settings (
    setting_key    VARCHAR(100) PRIMARY KEY,
    setting_value  VARCHAR(1000) NOT NULL,
    updated_by     BIGINT UNSIGNED NULL,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE accounting_accounts (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(10)  NOT NULL,
    name            VARCHAR(150) NOT NULL,
    type            ENUM('asset','liability','equity','income','expense') NOT NULL,
    normal_balance  ENUM('debit','credit') NOT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_accounting_accounts_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Posted entries are immutable. Corrections are new entries that reference the original.
CREATE TABLE journal_entries (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_number       VARCHAR(20)  NOT NULL,
    entry_date         DATE NOT NULL,
    branch_id          BIGINT UNSIGNED NOT NULL,
    source_type        VARCHAR(40)  NOT NULL,
    source_id          BIGINT UNSIGNED NULL,
    description        VARCHAR(255) NOT NULL,
    reverses_entry_id  BIGINT UNSIGNED NULL,
    posted_by          BIGINT UNSIGNED NULL,
    posted_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_journal_entries_number (entry_number),
    KEY ix_journal_entries_source (source_type, source_id),
    KEY ix_journal_entries_date (entry_date),
    CONSTRAINT fk_je_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_je_reverses FOREIGN KEY (reverses_entry_id) REFERENCES journal_entries(id),
    CONSTRAINT fk_je_posted_by FOREIGN KEY (posted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE journal_lines (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    journal_entry_id  BIGINT UNSIGNED NOT NULL,
    account_id        BIGINT UNSIGNED NOT NULL,
    debit             DECIMAL(19,4) NOT NULL DEFAULT 0,
    credit            DECIMAL(19,4) NOT NULL DEFAULT 0,
    KEY ix_journal_lines_account (account_id),
    CONSTRAINT fk_jl_entry FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id),
    CONSTRAINT fk_jl_account FOREIGN KEY (account_id) REFERENCES accounting_accounts(id),
    CONSTRAINT ck_jl_one_side CHECK (debit >= 0 AND credit >= 0 AND (debit = 0 OR credit = 0) AND (debit > 0 OR credit > 0))
) ENGINE=InnoDB;

-- A loan row is the application until approved, then the account.
CREATE TABLE loans (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    loan_number             VARCHAR(20) NOT NULL,
    customer_id             BIGINT UNSIGNED NOT NULL,
    branch_id               BIGINT UNSIGNED NOT NULL,
    product_id              BIGINT UNSIGNED NOT NULL,
    product_version_id      BIGINT UNSIGNED NOT NULL,
    principal               DECIMAL(19,4) NOT NULL,
    tenure                  INT UNSIGNED NOT NULL,
    installment_amount      DECIMAL(19,4) NOT NULL,
    total_interest          DECIMAL(19,4) NOT NULL,
    processing_fee          DECIMAL(19,4) NOT NULL,
    tax_on_fee              DECIMAL(19,4) NOT NULL,
    net_disbursement        DECIMAL(19,4) NOT NULL,
    purpose                 VARCHAR(255) NULL,
    status                  ENUM('pending_approval','approved','rejected','active','closed','cancelled') NOT NULL DEFAULT 'pending_approval',
    applied_by              BIGINT UNSIGNED NOT NULL,
    applied_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_by              BIGINT UNSIGNED NULL,
    decided_at              DATETIME NULL,
    decision_reason         VARCHAR(500) NULL,
    disbursed_by            BIGINT UNSIGNED NULL,
    disbursed_at            DATETIME NULL,
    disbursement_date       DATE NULL,
    disbursement_method     ENUM('cash','bank_transfer','upi','cheque') NULL,
    disbursement_reference  VARCHAR(100) NULL,
    disbursement_entry_id   BIGINT UNSIGNED NULL,
    first_due_date          DATE NULL,
    closed_at               DATETIME NULL,
    closure_type            ENUM('repaid','settled') NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_loans_number (loan_number),
    KEY ix_loans_customer (customer_id),
    KEY ix_loans_branch_status (branch_id, status),
    CONSTRAINT fk_loans_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_loans_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_loans_product FOREIGN KEY (product_id) REFERENCES loan_products(id),
    CONSTRAINT fk_loans_version FOREIGN KEY (product_version_id) REFERENCES loan_product_versions(id),
    CONSTRAINT fk_loans_applied_by FOREIGN KEY (applied_by) REFERENCES users(id),
    CONSTRAINT fk_loans_decided_by FOREIGN KEY (decided_by) REFERENCES users(id),
    CONSTRAINT fk_loans_disbursed_by FOREIGN KEY (disbursed_by) REFERENCES users(id),
    CONSTRAINT fk_loans_disbursement_entry FOREIGN KEY (disbursement_entry_id) REFERENCES journal_entries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE loan_installments (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    loan_id              BIGINT UNSIGNED NOT NULL,
    number               INT UNSIGNED NOT NULL,
    due_date             DATE NOT NULL,
    principal_due        DECIMAL(19,4) NOT NULL,
    interest_due         DECIMAL(19,4) NOT NULL,
    late_fee_due         DECIMAL(19,4) NOT NULL DEFAULT 0,
    principal_paid       DECIMAL(19,4) NOT NULL DEFAULT 0,
    interest_paid        DECIMAL(19,4) NOT NULL DEFAULT 0,
    late_fee_paid        DECIMAL(19,4) NOT NULL DEFAULT 0,
    interest_waived      DECIMAL(19,4) NOT NULL DEFAULT 0,
    status               ENUM('pending','partial','paid') NOT NULL DEFAULT 'pending',
    late_fee_applied_at  DATETIME NULL,
    paid_at              DATETIME NULL,
    UNIQUE KEY uq_installments_loan_number (loan_id, number),
    KEY ix_installments_due (due_date, status),
    CONSTRAINT fk_installments_loan FOREIGN KEY (loan_id) REFERENCES loans(id)
) ENGINE=InnoDB;
