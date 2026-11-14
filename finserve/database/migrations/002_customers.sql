-- One shared customer profile; loan and deposit accounts will reference it.

CREATE TABLE customers (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_number  VARCHAR(20)  NOT NULL,
    user_id          BIGINT UNSIGNED NULL,
    branch_id        BIGINT UNSIGNED NOT NULL,
    full_name        VARCHAR(150) NOT NULL,
    phone            VARCHAR(20)  NOT NULL,
    alt_phone        VARCHAR(20)  NULL,
    email            VARCHAR(190) NULL,
    date_of_birth    DATE NULL,
    gender           ENUM('male','female','other') NULL,
    address_line     VARCHAR(255) NOT NULL,
    city             VARCHAR(100) NOT NULL,
    state            VARCHAR(100) NOT NULL,
    pincode          VARCHAR(10)  NOT NULL,
    occupation       VARCHAR(100) NULL,
    monthly_income   DECIMAL(19,4) NULL,
    status           ENUM('active','inactive','blocked') NOT NULL DEFAULT 'active',
    created_by       BIGINT UNSIGNED NOT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customers_number (customer_number),
    UNIQUE KEY uq_customers_phone (phone),
    UNIQUE KEY uq_customers_user (user_id),
    KEY ix_customers_branch (branch_id),
    KEY ix_customers_name (full_name),
    CONSTRAINT fk_customers_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_customers_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_customers_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Time-bounded agent ownership. An open assignment has valid_to = NULL.
CREATE TABLE agent_assignments (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id    BIGINT UNSIGNED NOT NULL,
    agent_user_id  BIGINT UNSIGNED NOT NULL,
    valid_from     DATE NOT NULL,
    valid_to       DATE NULL,
    assigned_by    BIGINT UNSIGNED NOT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_assign_agent (agent_user_id, valid_to),
    KEY ix_assign_customer (customer_id, valid_to),
    CONSTRAINT fk_assign_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_assign_agent FOREIGN KEY (agent_user_id) REFERENCES users(id),
    CONSTRAINT fk_assign_by FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB;
