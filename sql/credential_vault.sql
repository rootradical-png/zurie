-- Personal NOC Credential Vault
-- Import with phpMyAdmin, then edit config/vault_config.php.

CREATE DATABASE IF NOT EXISTS zurie_noc
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE zurie_noc;

CREATE TABLE IF NOT EXISTS vault_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    master_hash VARCHAR(255) NOT NULL,
    kdf_salt VARCHAR(128) NOT NULL,
    unlock_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_credentials (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(80) NOT NULL,
    ciphertext LONGTEXT NOT NULL,
    nonce VARCHAR(128) NOT NULL,
    updated_by VARCHAR(100) NOT NULL DEFAULT 'ZURIE',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_device_credentials_device (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vault_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(40) NOT NULL,
    device_id VARCHAR(80) DEFAULT NULL,
    device_name VARCHAR(180) DEFAULT NULL,
    actor VARCHAR(100) NOT NULL DEFAULT 'ZURIE',
    ip_address VARCHAR(64) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_vault_audit_created (created_at),
    KEY idx_vault_audit_device (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
