-- Customer self-service: registration from the customer app (reviewed by staff) and loan / savings
-- applications that staff accept into a real loan application or deposit account.

-- Self-registered customers may sign in with their mobile number only.
ALTER TABLE users
    MODIFY email VARCHAR(190) NULL;

ALTER TABLE customers
    MODIFY status ENUM('pending','active','inactive','blocked') NOT NULL DEFAULT 'active',
    ADD COLUMN source ENUM('staff','self') NOT NULL DEFAULT 'staff' AFTER status,
    ADD COLUMN registration_note VARCHAR(255) NULL AFTER source,
    ADD COLUMN approved_by BIGINT UNSIGNED NULL AFTER registration_note,
    ADD COLUMN approved_at DATETIME NULL AFTER approved_by,
    ADD KEY ix_customers_status (status),
    ADD CONSTRAINT fk_customers_approved_by FOREIGN KEY (approved_by) REFERENCES users(id);

-- product_id points at loan_products (type = loan) or deposit_products (type = savings).
CREATE TABLE customer_applications (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_number  VARCHAR(20)  NOT NULL,
    customer_id         BIGINT UNSIGNED NOT NULL,
    type                ENUM('loan','savings') NOT NULL,
    product_id          BIGINT UNSIGNED NOT NULL,
    amount              DECIMAL(19,4) NOT NULL,
    tenure              INT UNSIGNED NULL,
    purpose             VARCHAR(255) NULL,
    status              ENUM('submitted','accepted','rejected','cancelled') NOT NULL DEFAULT 'submitted',
    decided_by          BIGINT UNSIGNED NULL,
    decided_at          DATETIME NULL,
    decision_note       VARCHAR(255) NULL,
    loan_id             BIGINT UNSIGNED NULL,
    deposit_account_id  BIGINT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_applications_number (application_number),
    KEY ix_applications_customer (customer_id),
    KEY ix_applications_status (status),
    CONSTRAINT fk_applications_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_applications_decided_by FOREIGN KEY (decided_by) REFERENCES users(id),
    CONSTRAINT fk_applications_loan FOREIGN KEY (loan_id) REFERENCES loans(id),
    CONSTRAINT fk_applications_deposit FOREIGN KEY (deposit_account_id) REFERENCES deposit_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
