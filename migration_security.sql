-- ================================================================
-- S.NET Manager v2.1 — Security Migration
-- Jalankan sekali: SOURCE /path/to/migration_security.sql;
-- ================================================================

USE snet_v2;

-- Tabel tracking percobaan login (rate limiting & brute force protection)
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    identifier   VARCHAR(100) NOT NULL COMMENT 'username atau customer_id',
    ip_address   VARCHAR(50)  NOT NULL,
    attempt_type ENUM('admin','portal') DEFAULT 'admin',
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_identifier_time (identifier, attempted_at),
    INDEX idx_ip_time         (ip_address, attempted_at)
);

-- Hapus attempts lama otomatis (opsional: jalankan via cron atau event scheduler)
-- DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR);
