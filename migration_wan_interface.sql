-- ================================================================
-- S.NET Manager — Migration: Tambah kolom wan_interface ke tabel routers
-- ================================================================
-- Jalankan file ini SEKALI pada database yang SUDAH berjalan (existing install).
-- Untuk install baru, kolom ini sudah ada di database.sql.
--
-- PENTING: Setelah migrasi, isi kolom wan_interface di menu Router Config
-- dengan nama interface WAN publik Mikrotik Anda:
--   - "ether1"        → jika WAN langsung di ether1 (IP statis/DHCP)
--   - "pppoe-out1"    → jika WAN via PPPoE dial-up
--   - "sfp-sfpplus1"  → jika WAN via SFP port
-- Field ini akan dipakai sebagai "in-interface" pada rule DST-NAT
-- (Port Forwarding & Remote ONT), menggantikan dst-address IP publik
-- yang sudah dipakai program utama.
-- ================================================================

ALTER TABLE routers
    ADD COLUMN IF NOT EXISTS wan_interface VARCHAR(100) DEFAULT ''
    COMMENT 'Nama interface WAN publik (ether1, pppoe-out1) untuk in-interface NAT'
    AFTER mikhmon_pass;

-- Verifikasi:
-- SELECT id, name, ip_public, wan_interface FROM routers;
