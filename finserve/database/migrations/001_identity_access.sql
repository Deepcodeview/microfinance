-- Identity, access control, sessions and audit trail.

CREATE TABLE branches (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(20)  NOT NULL,
    name        VARCHAR(150) NOT NULL,
    status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_branches_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(150) NOT NULL,
    email          VARCHAR(190) NOT NULL,
    phone          VARCHAR(20)  NULL,
    password_hash  VARCHAR(255) NOT NULL,
    status         ENUM('active','disabled') NOT NULL DEFAULT 'active',
    branch_id      BIGINT UNSIGNED NULL,
    last_login_at  DATETIME NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roles (
    id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_key  VARCHAR(50)  NOT NULL,
    name      VARCHAR(100) NOT NULL,
    UNIQUE KEY uq_roles_key (role_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    permission_key  VARCHAR(100) NOT NULL,
    description     VARCHAR(255) NOT NULL,
    UNIQUE KEY uq_permissions_key (permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id        BIGINT UNSIGNED NOT NULL,
    permission_id  BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id),
    CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions(id)
) ENGINE=InnoDB;

CREATE TABLE user_roles (
    user_id  BIGINT UNSIGNED NOT NULL,
    role_id  BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

-- Only the SHA-256 hash of a token is stored; the raw token is shown to the client once.
CREATE TABLE api_tokens (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       BIGINT UNSIGNED NOT NULL,
    token_hash    CHAR(64) NOT NULL,
    expires_at    DATETIME NOT NULL,
    revoked_at    DATETIME NULL,
    last_used_at  DATETIME NULL,
    ip            VARCHAR(45)  NULL,
    user_agent    VARCHAR(255) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_api_tokens_hash (token_hash),
    KEY ix_api_tokens_user (user_id),
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(190) NOT NULL,
    ip            VARCHAR(45)  NOT NULL,
    success       TINYINT(1)   NOT NULL,
    attempted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_login_attempts_lookup (email, ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only. The application never updates or deletes rows here.
CREATE TABLE audit_logs (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id     BIGINT UNSIGNED NULL,
    action       VARCHAR(100) NOT NULL,
    entity_type  VARCHAR(60)  NULL,
    entity_id    BIGINT UNSIGNED NULL,
    before_json  JSON NULL,
    after_json   JSON NULL,
    reason       VARCHAR(500) NULL,
    ip           VARCHAR(45)  NULL,
    request_id   CHAR(32)     NOT NULL,
    created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY ix_audit_entity (entity_type, entity_id),
    KEY ix_audit_actor (actor_id),
    KEY ix_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Human-readable number generation (CUS000001, LPR0001, ...). Rows are locked FOR UPDATE.
CREATE TABLE number_sequences (
    name        VARCHAR(50) PRIMARY KEY,
    prefix      VARCHAR(10) NOT NULL,
    pad_length  TINYINT UNSIGNED NOT NULL,
    next_value  BIGINT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB;
