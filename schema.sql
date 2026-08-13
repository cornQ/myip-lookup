SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS diagnostics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    private_token CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reference_code CHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_diagnostics_public_token (public_token),
    UNIQUE KEY uq_diagnostics_private_token (private_token),
    UNIQUE KEY uq_diagnostics_reference_code (reference_code),
    KEY idx_diagnostics_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS captures (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    diagnostic_id BIGINT UNSIGNED NOT NULL,
    captured_at DATETIME NOT NULL,
    source VARCHAR(50) NOT NULL,
    ipv4 VARCHAR(15) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ipv6 VARCHAR(45) CHARACTER SET ascii COLLATE ascii_bin NULL,
    request_ip VARCHAR(45) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ptr4 VARCHAR(255) NULL,
    ptr6 VARCHAR(255) NULL,
    ipv4_info_json LONGTEXT NULL,
    ipv6_info_json LONGTEXT NULL,
    ix_json LONGTEXT NOT NULL,
    user_agent VARCHAR(1000) NULL,
    PRIMARY KEY (id),
    KEY idx_captures_diagnostic_id (diagnostic_id),
    CONSTRAINT fk_captures_diagnostic
        FOREIGN KEY (diagnostic_id) REFERENCES diagnostics(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
    client_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    rate_action VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_started_at BIGINT UNSIGNED NOT NULL,
    request_count INT UNSIGNED NOT NULL,
    PRIMARY KEY (client_key, rate_action),
    KEY idx_rate_limits_window_started_at (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

