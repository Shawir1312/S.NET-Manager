-- ================================================================
-- S.NET Manager v2.1 — Complete Database Schema
-- ================================================================
CREATE DATABASE IF NOT EXISTS snet_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE snet_v2;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(60) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    role ENUM('superadmin','admin','operator','teknisi') DEFAULT 'operator',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS routers (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(120) NOT NULL,
    host          VARCHAR(100) NOT NULL,
    port          INT DEFAULT 8728,
    username      VARCHAR(60)  NOT NULL,
    password      VARCHAR(255) NOT NULL,
    ip_public     VARCHAR(50)  NOT NULL,
    domain_public VARCHAR(255) DEFAULT '',
    ros_version   TINYINT(1)   DEFAULT 7,
    is_main       TINYINT(1)   DEFAULT 0 COMMENT 'Router utama untuk VPN dan Port Forwarding',
    use_mikhmon   TINYINT(1)   DEFAULT 0 COMMENT 'Aktifkan monitoring Mikhmon',
    mikhmon_url   VARCHAR(255) DEFAULT '' COMMENT 'URL Mikhmon instance',
    mikhmon_user  VARCHAR(100) DEFAULT 'admin',
    mikhmon_pass  VARCHAR(255) DEFAULT 'admin',
    wan_interface VARCHAR(100) DEFAULT '' COMMENT 'Nama interface WAN publik (ether1, pppoe-out1) untuk in-interface NAT',
    is_active     TINYINT(1)   DEFAULT 1,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS genie_config (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) DEFAULT 'GenieACS',
    url        VARCHAR(255) DEFAULT 'http://localhost:7557',
    username   VARCHAR(100) DEFAULT '',
    password   VARCHAR(255) DEFAULT '',
    is_active  TINYINT(1)  DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS customers (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    customer_id      VARCHAR(30)  NOT NULL UNIQUE,
    password         VARCHAR(255) NOT NULL,
    full_name        VARCHAR(150) NOT NULL,
    phone            VARCHAR(20)  DEFAULT '',
    address          TEXT,
    genie_device_id  VARCHAR(255) DEFAULT '',
    device_serial    VARCHAR(100) DEFAULT '',
    device_brand     ENUM('FiberHome','CData','Huawei','ZTE','Unknown') DEFAULT 'Unknown',
    device_model     VARCHAR(100) DEFAULT '',
    ont_tag          VARCHAR(100) DEFAULT '',
    router_id        INT DEFAULT NULL,
    is_active        TINYINT(1) DEFAULT 1,
    notes            TEXT,
    created_by       INT DEFAULT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (router_id)  REFERENCES routers(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)   ON DELETE SET NULL
);

-- Konfigurasi ONT tersimpan — untuk push ulang setelah ONT reset
CREATE TABLE IF NOT EXISTS ont_configs (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    customer_id     INT NOT NULL,
    genie_device_id VARCHAR(255) NOT NULL,
    config_type     ENUM('wifi','wan','binding') NOT NULL,
    config_name     VARCHAR(150) DEFAULT '',
    config_data     TEXT NOT NULL COMMENT 'JSON semua parameter konfigurasi',
    push_status     ENUM('success','failed','pending') DEFAULT 'success',
    push_count      INT DEFAULT 1,
    last_pushed     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by      INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS port_forwardings (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    router_id     INT NOT NULL,
    rule_name     VARCHAR(120) DEFAULT '',
    ip_public     VARCHAR(50)  NOT NULL,
    domain_public VARCHAR(255) DEFAULT '',
    public_port   INT NOT NULL,
    ip_lokal      VARCHAR(50)  NOT NULL,
    port_lokal    INT NOT NULL,
    protocol      ENUM('tcp','udp','both') DEFAULT 'tcp',
    comment       TEXT,
    mikrotik_id   VARCHAR(60) DEFAULT NULL,
    status        ENUM('active','inactive','error') DEFAULT 'active',
    created_by    INT DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (router_id)  REFERENCES routers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)   ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS vpn_accounts (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    router_id      INT NOT NULL,
    username       VARCHAR(100) NOT NULL,
    password       VARCHAR(255) NOT NULL,
    service        ENUM('l2tp','pptp','any') DEFAULT 'l2tp',
    profile        VARCHAR(100) DEFAULT 'default-encryption',
    local_address  VARCHAR(50) DEFAULT '',
    remote_address VARCHAR(50) DEFAULT '',
    ipsec_secret   VARCHAR(255) DEFAULT '',
    comment        TEXT,
    mikrotik_id    VARCHAR(60) DEFAULT NULL,
    is_disabled    TINYINT(1) DEFAULT 0,
    status         ENUM('active','disabled','error') DEFAULT 'active',
    created_by     INT DEFAULT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (router_id)  REFERENCES routers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)   ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS audit_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    actor_type ENUM('admin','customer') DEFAULT 'admin',
    actor_id   INT DEFAULT NULL,
    actor_name VARCHAR(150) DEFAULT '',
    action     VARCHAR(100) NOT NULL,
    target     VARCHAR(255) DEFAULT '',
    detail     TEXT,
    ip_address VARCHAR(50) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Default data
INSERT IGNORE INTO users (id,username,password,full_name,role) VALUES
(1,'admin','$2y$10$TKh8H1.PfbuNNLCTECo9gOq7j5l3k6CvJ4xZyMJe/.OGjFBiHNgbu','Administrator','superadmin');
-- default password: admin123

INSERT IGNORE INTO genie_config (id,name,url) VALUES (1,'GenieACS Server','http://localhost:7557');

INSERT IGNORE INTO routers (id,name,host,port,username,password,ip_public,ros_version,is_main) VALUES
(1,'Router Utama','192.168.1.1',8728,'admin','password','203.0.113.1',7,1);

-- ── MIGRATION untuk existing install ─────────────────────────
-- Jalankan ini jika upgrade dari versi sebelumnya:
-- ALTER TABLE routers ADD COLUMN IF NOT EXISTS is_main TINYINT(1) DEFAULT 0;
-- ALTER TABLE routers ADD COLUMN IF NOT EXISTS use_mikhmon TINYINT(1) DEFAULT 0;
-- ALTER TABLE routers ADD COLUMN IF NOT EXISTS mikhmon_url VARCHAR(255) DEFAULT '';
-- ALTER TABLE routers ADD COLUMN IF NOT EXISTS mikhmon_user VARCHAR(100) DEFAULT 'admin';
-- ALTER TABLE routers ADD COLUMN IF NOT EXISTS mikhmon_pass VARCHAR(255) DEFAULT 'admin';
-- ALTER TABLE routers ADD COLUMN IF NOT EXISTS wan_interface VARCHAR(100) DEFAULT '' COMMENT 'Nama interface WAN publik (ether1, pppoe-out1) untuk in-interface NAT';
-- UPDATE routers SET is_main=1 ORDER BY id LIMIT 1;
-- CREATE TABLE IF NOT EXISTS ont_configs (...);

-- ═══════════════════════════════════════════════════════
-- PPPoE MANAGER + AUTO ISOLIR SYSTEM
-- ═══════════════════════════════════════════════════════

-- Tabel PPPoE pelanggan (link ke MikroTik + billing info)
CREATE TABLE IF NOT EXISTS pppoe_customers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    router_id       INT NOT NULL,
    pppoe_username  VARCHAR(100) NOT NULL,
    full_name       VARCHAR(150) NOT NULL DEFAULT '',
    phone           VARCHAR(25)  DEFAULT '',
    address         TEXT,
    profile         VARCHAR(100) DEFAULT '',
    -- Billing
    monthly_price   INT DEFAULT 0,
    due_day         TINYINT DEFAULT 1,          -- tanggal jatuh tempo per bulan (1-28)
    -- Status
    status          ENUM('active','isolated','suspended') DEFAULT 'active',
    isolated_at     DATETIME DEFAULT NULL,
    isolated_reason VARCHAR(255) DEFAULT '',
    -- Payment
    last_paid_at    DATE DEFAULT NULL,
    last_paid_amount INT DEFAULT 0,
    -- Meta
    notes           TEXT,
    created_by      INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_router_user (router_id, pppoe_username),
    FOREIGN KEY (router_id)  REFERENCES routers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Tabel riwayat pembayaran PPPoE
CREATE TABLE IF NOT EXISTS pppoe_payments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    customer_id     INT NOT NULL,
    amount          INT NOT NULL,
    payment_method  VARCHAR(50) DEFAULT 'cash',  -- cash/midtrans/transfer
    midtrans_order_id VARCHAR(100) DEFAULT NULL,
    midtrans_tx_id    VARCHAR(100) DEFAULT NULL,
    midtrans_status   VARCHAR(50)  DEFAULT NULL,
    period_month    TINYINT NOT NULL,            -- bulan pembayaran (1-12)
    period_year     SMALLINT NOT NULL,           -- tahun pembayaran
    paid_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    notes           VARCHAR(255) DEFAULT '',
    created_by      INT DEFAULT NULL,
    FOREIGN KEY (customer_id) REFERENCES pppoe_customers(id) ON DELETE CASCADE
);

-- Konfigurasi Midtrans & isolir
CREATE TABLE IF NOT EXISTS pppoe_settings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    setting_key     VARCHAR(100) NOT NULL UNIQUE,
    setting_value   TEXT,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO pppoe_settings (setting_key, setting_value) VALUES
('midtrans_server_key', ''),
('midtrans_client_key', ''),
('midtrans_mode', 'sandbox'),              -- sandbox / production
('isolir_profile', 'isolir'),              -- nama profile MikroTik untuk isolir
('isolir_redirect_url', '/portal/isolir'), -- URL redirect saat isolir
('isolir_grace_days', '3'),               -- toleransi hari setelah jatuh tempo
('company_name', 'S.NET Internet'),
('company_phone', ''),
('company_address', '');
