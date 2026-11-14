-- KYC documents, notifications outbox, customer service requests, holidays, two-factor authentication.

CREATE TABLE customer_documents (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id     BIGINT UNSIGNED NOT NULL,
    doc_type        ENUM('aadhaar','pan','voter_id','passport','driving_licence','photo','address_proof','income_proof','other') NOT NULL,
    doc_number_last4 VARCHAR(4) NULL,
    original_name   VARCHAR(255) NOT NULL,
    stored_name     CHAR(64) NOT NULL,
    mime_type       VARCHAR(100) NOT NULL,
    size_bytes      INT UNSIGNED NOT NULL,
    sha256          CHAR(64) NOT NULL,
    status          ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    review_note     VARCHAR(500) NULL,
    uploaded_by     BIGINT UNSIGNED NOT NULL,
    uploaded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by     BIGINT UNSIGNED NULL,
    reviewed_at     DATETIME NULL,
    UNIQUE KEY uq_documents_stored (stored_name),
    KEY ix_documents_customer (customer_id),
    CONSTRAINT fk_documents_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_documents_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id),
    CONSTRAINT fk_documents_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- In-app notifications plus an outbox for SMS/WhatsApp/email. Unsent external messages stay
-- 'queued' until a provider is configured; nothing is silently dropped.
CREATE TABLE notifications (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      BIGINT UNSIGNED NULL,
    customer_id  BIGINT UNSIGNED NULL,
    channel      ENUM('in_app','sms','whatsapp','email') NOT NULL,
    event        VARCHAR(60) NOT NULL,
    title        VARCHAR(150) NOT NULL,
    body         VARCHAR(1000) NOT NULL,
    link         VARCHAR(255) NULL,
    recipient    VARCHAR(190) NULL,
    status       ENUM('unread','read','queued','sent','failed') NOT NULL,
    dedupe_key   VARCHAR(120) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at      DATETIME NULL,
    sent_at      DATETIME NULL,
    error        VARCHAR(500) NULL,
    UNIQUE KEY uq_notifications_dedupe (dedupe_key),
    KEY ix_notifications_user (user_id, status),
    KEY ix_notifications_outbox (channel, status),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_notifications_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_requests (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_number  VARCHAR(20) NOT NULL,
    customer_id     BIGINT UNSIGNED NOT NULL,
    branch_id       BIGINT UNSIGNED NOT NULL,
    category        ENUM('payment_issue','statement','loan_query','deposit_query','profile_update','complaint','other') NOT NULL,
    subject         VARCHAR(150) NOT NULL,
    status          ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
    priority        ENUM('normal','high') NOT NULL DEFAULT 'normal',
    assigned_to     BIGINT UNSIGNED NULL,
    created_by      BIGINT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    resolved_at     DATETIME NULL,
    UNIQUE KEY uq_service_requests_number (request_number),
    KEY ix_service_requests_customer (customer_id),
    KEY ix_service_requests_status (status, branch_id),
    CONSTRAINT fk_sr_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_sr_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_sr_assigned FOREIGN KEY (assigned_to) REFERENCES users(id),
    CONSTRAINT fk_sr_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_request_messages (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id  BIGINT UNSIGNED NOT NULL,
    author_id   BIGINT UNSIGNED NOT NULL,
    is_staff    TINYINT(1) NOT NULL,
    body        VARCHAR(2000) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_srm_request (request_id),
    CONSTRAINT fk_srm_request FOREIGN KEY (request_id) REFERENCES service_requests(id),
    CONSTRAINT fk_srm_author FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE holidays (
    holiday_date  DATE PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    created_by    BIGINT UNSIGNED NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_holidays_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TOTP (authenticator app). The secret is encrypted with APP_KEY; never stored in plain text.
ALTER TABLE users
    ADD COLUMN mfa_secret_enc VARCHAR(255) NULL AFTER password_hash,
    ADD COLUMN mfa_enabled_at DATETIME NULL AFTER mfa_secret_enc,
    ADD COLUMN mfa_last_step BIGINT UNSIGNED NULL AFTER mfa_enabled_at;

-- Short-lived second-step challenge issued after a correct password when MFA is on.
CREATE TABLE mfa_challenges (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       BIGINT UNSIGNED NOT NULL,
    token_hash    CHAR(64) NOT NULL,
    attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at    DATETIME NOT NULL,
    used_at       DATETIME NULL,
    UNIQUE KEY uq_mfa_challenges_hash (token_hash),
    CONSTRAINT fk_mfa_challenges_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
