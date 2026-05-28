# S.NET Manager v2.1 — Panduan Deploy & Update

## Cara Upload File Baru

1. Upload seluruh isi folder `snet-v2/` ke web root (contoh: `/www/wwwroot/snet/`)
2. Pastikan SEMUA file ter-upload termasuk:
   - `admin/mikhmon_ajax.php` ← penting untuk S.NET Monitoring
   - `admin/pppoe.php` ← menu baru PPPoE Manager
   - `admin/pppoe_detail.php` ← detail pelanggan PPPoE
   - `portal/isolir.php` ← portal isolir + Midtrans
   - `admin/cron_auto_isolir.php` ← cron auto isolir

## Setelah Upload

```bash
# Restart PHP-FPM (wajib setelah upload file baru)
systemctl restart php8.1-fpm
# atau
systemctl restart php-fpm

# Jika pakai aaPanel: Software Store → PHP-FPM → Restart
```

## Jika Muncul Error 404

Tambahkan di nginx config untuk site ini:
```nginx
location ~ \.php$ {
    try_files $uri =404;
    fastcgi_pass unix:/tmp/php-cgi-81.sock;  # sesuaikan versi PHP
    fastcgi_index index.php;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

## Update Database (jalankan sekali)

```sql
-- Jalankan di MySQL/MariaDB:
SOURCE /path/to/database.sql;
```

## Setup Cron Auto-Isolir

```bash
# Edit crontab
crontab -e

# Tambahkan baris ini (jalankan jam 01:00 setiap hari):
0 1 * * * php /www/wwwroot/snet/admin/cron_auto_isolir.php >> /var/log/auto_isolir.log 2>&1
```

## Cek Diagnosa

Akses `http://IP:PORT/admin/diag.php` untuk cek apakah semua file ada.

