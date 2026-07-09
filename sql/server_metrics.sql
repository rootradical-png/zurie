-- Personal NOC Dashboard - Server Metrics Phase 1
-- Pilih database zurie_noc sebelum import.

CREATE TABLE IF NOT EXISTS server_metric_agents (
    device_id VARCHAR(80) NOT NULL PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    note VARCHAR(255) DEFAULT NULL,
    last_seen_at DATETIME DEFAULT NULL,
    last_ip VARCHAR(64) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS server_metrics_current (
    device_id VARCHAR(80) NOT NULL PRIMARY KEY,
    hostname VARCHAR(190) DEFAULT NULL,
    os_name VARCHAR(255) DEFAULT NULL,
    agent_version VARCHAR(40) DEFAULT NULL,
    cpu_percent DECIMAL(6,2) DEFAULT NULL,
    memory_total_mb BIGINT UNSIGNED DEFAULT NULL,
    memory_used_mb BIGINT UNSIGNED DEFAULT NULL,
    memory_free_mb BIGINT UNSIGNED DEFAULT NULL,
    memory_percent DECIMAL(6,2) DEFAULT NULL,
    disk_max_percent DECIMAL(6,2) DEFAULT NULL,
    disks_json MEDIUMTEXT DEFAULT NULL,
    load_json TEXT DEFAULT NULL,
    services_json MEDIUMTEXT DEFAULT NULL,
    uptime_seconds BIGINT UNSIGNED DEFAULT NULL,
    collected_at DATETIME DEFAULT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_server_metrics_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS server_metrics_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(80) NOT NULL,
    cpu_percent DECIMAL(6,2) DEFAULT NULL,
    memory_percent DECIMAL(6,2) DEFAULT NULL,
    disk_max_percent DECIMAL(6,2) DEFAULT NULL,
    collected_at DATETIME DEFAULT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_metric_history_device_time (device_id, received_at),
    KEY idx_metric_history_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
