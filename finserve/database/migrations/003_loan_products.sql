-- Admin-configurable loan products. Terms are never edited in place:
-- every change creates a new immutable version, and loans keep the version they were opened on.

CREATE TABLE loan_products (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code                VARCHAR(20)  NOT NULL,
    name                VARCHAR(150) NOT NULL,
    description         VARCHAR(500) NULL,
    status              ENUM('active','inactive') NOT NULL DEFAULT 'active',
    current_version_id  BIGINT UNSIGNED NULL,
    created_by          BIGINT UNSIGNED NOT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_loan_products_code (code),
    CONSTRAINT fk_loan_products_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE loan_product_versions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id      BIGINT UNSIGNED NOT NULL,
    version         INT UNSIGNED NOT NULL,
    terms_json      JSON NOT NULL,
    effective_from  DATETIME NOT NULL,
    change_reason   VARCHAR(500) NOT NULL,
    created_by      BIGINT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lpv_product_version (product_id, version),
    CONSTRAINT fk_lpv_product FOREIGN KEY (product_id) REFERENCES loan_products(id),
    CONSTRAINT fk_lpv_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE loan_products
    ADD CONSTRAINT fk_loan_products_current_version
    FOREIGN KEY (current_version_id) REFERENCES loan_product_versions(id);
