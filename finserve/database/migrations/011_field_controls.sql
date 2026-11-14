-- Field controls: where each collection / visit was recorded, and cash floats agents carry for field disbursement.

ALTER TABLE payments
    ADD COLUMN latitude DECIMAL(9,6) NULL AFTER notes,
    ADD COLUMN longitude DECIMAL(9,6) NULL AFTER latitude,
    ADD COLUMN location_accuracy_m INT UNSIGNED NULL AFTER longitude,
    ADD COLUMN located_at DATETIME NULL AFTER location_accuracy_m;

ALTER TABLE collection_visits
    ADD COLUMN latitude DECIMAL(9,6) NULL,
    ADD COLUMN longitude DECIMAL(9,6) NULL,
    ADD COLUMN location_accuracy_m INT UNSIGNED NULL;

-- Cash an agent carries besides collections: a float issued by the branch, and loans paid out from it in the
-- field. Everything not yet handed over is part of the agent's next day-end handover.
CREATE TABLE agent_cash_movements (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    movement_number   VARCHAR(20)  NOT NULL,
    agent_id          BIGINT UNSIGNED NOT NULL,
    branch_id         BIGINT UNSIGNED NOT NULL,
    type              ENUM('float_issued','field_disbursement') NOT NULL,
    amount            DECIMAL(19,4) NOT NULL,
    loan_id           BIGINT UNSIGNED NULL,
    business_date     DATE NOT NULL,
    note              VARCHAR(255) NULL,
    handover_id       BIGINT UNSIGNED NULL,
    journal_entry_id  BIGINT UNSIGNED NULL,
    created_by        BIGINT UNSIGNED NOT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cash_movements_number (movement_number),
    KEY ix_cash_movements_agent (agent_id, handover_id),
    CONSTRAINT fk_cash_movements_agent FOREIGN KEY (agent_id) REFERENCES users(id),
    CONSTRAINT fk_cash_movements_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_cash_movements_loan FOREIGN KEY (loan_id) REFERENCES loans(id),
    CONSTRAINT fk_cash_movements_handover FOREIGN KEY (handover_id) REFERENCES cash_handovers(id),
    CONSTRAINT fk_cash_movements_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A loan an agent paid out in the field from their cash in hand.
ALTER TABLE loans
    ADD COLUMN disbursed_in_field TINYINT(1) NOT NULL DEFAULT 0 AFTER disbursement_method;
