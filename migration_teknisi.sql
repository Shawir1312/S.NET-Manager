-- ================================================================
-- S.NET Manager — Migration: Tambah role 'teknisi'
-- ================================================================
ALTER TABLE users MODIFY COLUMN role ENUM('superadmin','admin','operator','teknisi') DEFAULT 'operator';

-- Contoh: INSERT user teknisi
-- INSERT INTO users (username,password,full_name,role) VALUES ('teknisi1', '$2y$10$...', 'Teknisi Lapangan', 'teknisi');
-- (generate hash: php -r "echo password_hash('password123', PASSWORD_DEFAULT);")
