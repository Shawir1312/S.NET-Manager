-- ================================================================
-- S.NET Manager — Migration: Laporan Penagihan & Reseller
-- Jalankan sekali untuk upgrade existing install
-- ================================================================

-- Tabel Reseller
CREATE TABLE IF NOT EXISTS resellers (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    nama                VARCHAR(150) NOT NULL,
    persen_keuntungan   DECIMAL(5,2) DEFAULT 0 COMMENT 'Persentase keuntungan reseller (%)',
    harga_voucher       INT DEFAULT 0 COMMENT 'Harga per voucher (untuk hitung jumlah terjual)',
    catatan             TEXT,
    is_active           TINYINT(1) DEFAULT 1,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Tabel Laporan Penagihan
CREATE TABLE IF NOT EXISTS laporan_penagihan (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    teknisi_id          INT DEFAULT NULL,
    teknisi_nama        VARCHAR(150) DEFAULT '',
    reseller_id         INT DEFAULT NULL,
    reseller_nama       VARCHAR(150) DEFAULT '',
    persen_reseller     DECIMAL(5,2) DEFAULT 0,
    total_pendapatan    BIGINT DEFAULT 0 COMMENT 'Total pendapatan dari penagihan (Rp)',
    bagian_reseller     BIGINT DEFAULT 0 COMMENT 'Bagian keuntungan reseller (Rp)',
    pendapatan_bersih   BIGINT DEFAULT 0 COMMENT 'Pendapatan bersih setelah potong reseller (Rp)',
    harga_voucher       INT DEFAULT 0,
    voucher_terjual     INT DEFAULT 0 COMMENT 'Estimasi voucher terjual',
    catatan             TEXT,
    tanggal_penagihan   DATETIME DEFAULT CURRENT_TIMESTAMP,
    gdrive_exported     TINYINT(1) DEFAULT 0,
    gdrive_tab_name     VARCHAR(100) DEFAULT '',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teknisi_id)  REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE SET NULL
);

-- Tabel Setting (Google Drive, dll)
CREATE TABLE IF NOT EXISTS penagihan_settings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    setting_key     VARCHAR(100) NOT NULL UNIQUE,
    setting_value   MEDIUMTEXT,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO penagihan_settings (setting_key, setting_value) VALUES
('gdrive_spreadsheet_id', ''),
('gdrive_service_account', ''),
('gdrive_enabled', '0'),
('laporan_title', 'Laporan Penjualan Voucher WiFi');
