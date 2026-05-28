# S.NET Manager v2.1

S.NET Manager adalah sistem informasi manajemen terpadu (Billing & Network Management System) berbasis web yang dikembangkan khusus untuk memudahkan operasional Internet Service Provider (ISP) atau penyedia jaringan internet (RT/RW Net). 

Aplikasi ini menyatukan berbagai kebutuhan seperti pengelolaan pelanggan, penagihan, manajemen perangkat jaringan, hingga portal khusus pelanggan dalam satu platform yang komprehensif.

---

## 🚀 Fitur Unggulan Aplikasi

S.NET Manager dilengkapi dengan berbagai modul untuk mendukung seluruh aspek operasional ISP Anda:

### 1. Manajemen Pelanggan (Customer Management)
- **Data Pelanggan**: Mengelola database pelanggan secara terpusat.
- **Manajemen PPPoE**: Sinkronisasi dan manajemen akun PPPoE pelanggan secara langsung dari panel (Tambah, Edit, Hapus, Detail Statistik).
- **Manajemen Reseller**: Sistem pengelolaan pelanggan berbasis reseller/mitra.

### 2. Sistem Penagihan & Keuangan (Billing System)
- **Manajemen Tagihan (Invoicing)**: Pembuatan dan pengelolaan tagihan pelanggan secara sistematis.
- **Portal Isolir (Suspension)**: Halaman khusus yang mengarahkan pelanggan yang belum membayar tagihan agar tidak bisa mengakses internet (terisolir). Halaman ini juga dapat diintegrasikan dengan sistem pembayaran (seperti Midtrans) agar pelanggan dapat membayar mandiri.
- **Auto Isolir (Cron Job)**: Sistem otomatis yang akan melakukan isolir pada pelanggan yang melewati batas jatuh tempo tanpa intervensi manual.
- **Laporan Pendapatan**: Ekspor data tagihan dan arsip keuangan ke Excel untuk pelaporan.

### 3. Monitoring & Manajemen Jaringan
- **SNET Monitoring & Mikhmon Integration**: Fitur live monitoring status router dan trafik pelanggan yang terintegrasi secara *seamless* dengan fungsionalitas Mikhmon.
- **Manajemen Router (MikroTik)**: Pengelolaan banyak router Mikrotik dari satu panel dashboard.
- **VPN & Port Forwarding**: Fitur untuk membuat tunnel VPN serta alokasi port forwarding ke IP lokal pelanggan untuk kebutuhan remote (CCTV, Server, dll).

### 4. Manajemen Perangkat (TR-069 / GenieACS)
- **Integrasi GenieACS**: Mengelola perangkat ONT/Modem pelanggan (ZTE, Huawei, dll) secara remote melalui protokol standar TR-069.
- **Manajemen ONT Lengkap**: Mengecek redaman (optic power), melihat LAN status, merestart perangkat, dan mengonfigurasi nama/password WiFi pelanggan dari jarak jauh tanpa harus mengirim teknisi ke lokasi.

### 5. Manajemen Teknisi & Maintenance
- **Portal Teknisi**: Akses panel khusus untuk teknisi lapangan.
- **Laporan Pekerjaan**: Pencatatan riwayat tiket perbaikan, instalasi, dan maintenance jaringan harian.

### 6. Keamanan & Log Audit
- **Sistem Autentikasi Aman**: Login yang dilengkapi standar keamanan tinggi.
- **Audit Trail**: Pencatatan setiap aktivitas user (admin maupun teknisi) untuk keperluan investigasi dan keamanan sistem.

---

## 📋 Persyaratan Sistem

- **Sistem Operasi**: Linux (Ubuntu, Debian, CentOS, AlmaLinux, dll)
- **Web Server**: Nginx (Sangat Disarankan) atau Apache
- **PHP**: Versi 8.1 (Wajib menggunakan PHP-FPM)
- **Database**: MySQL atau MariaDB
- **Ekstensi PHP**: `pdo_mysql`, `mysqli`, `curl`, `json`, `mbstring`, dan ekstensi standar lainnya.

---

## 🛠️ Panduan Instalasi & Deployment

Untuk instruksi detail terkait update versi dari instalasi sebelumnya, silakan baca file [DEPLOY.md](DEPLOY.md).

Berikut adalah langkah-langkah instalasi awal (Fresh Install):

### 1. Upload File & Direktori
Upload seluruh file source code ke web root server Anda (misal: `/www/wwwroot/snet_manager/`).

### 2. Konfigurasi Environment (`.env`)
Sistem ini menggunakan file `.env` untuk menyimpan rahasia dan konfigurasi koneksi database.
Salin file template yang disediakan:
```bash
cp .env.example .env
```
Buka file `.env` dan atur konfigurasi database serta `APP_SECRET`:
```env
DB_HOST=localhost
DB_NAME=snet_db
DB_USER=root
DB_PASS=password_anda

# Generate secret key unik (jalankan di terminal: php -r "echo bin2hex(random_bytes(32));")
APP_SECRET=masukkan_hasil_generate_disini
```

### 3. Import Struktur Database
Buat database di MySQL/MariaDB Anda, kemudian import file `database.sql` yang sudah disediakan:
```bash
mysql -u root -p snet_db < database.sql
```
*(Catatan: Jika ada file migrasi seperti `migration_penagihan.sql`, jalankan juga sesuai urutan versi jika Anda melakukan upgrade).*

### 4. Konfigurasi Web Server (Nginx)
Agar aplikasi berjalan lancar dan terhindar dari Error 404 pada permalink PHP, pastikan blok konfigurasi situs Nginx Anda mencakup aturan berikut:
```nginx
location ~ \.php$ {
    try_files $uri =404;
    fastcgi_pass unix:/tmp/php-cgi-81.sock;  # Sesuaikan path socket dengan versi PHP-FPM Anda
    fastcgi_index index.php;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

### 5. Restart Layanan
```bash
systemctl restart php8.1-fpm
systemctl restart nginx
```

---

## ⏱️ Setup Sistem Auto Isolir (Cron Job)

Sistem membutuhkan *Cron Job* agar dapat secara otomatis mengecek jatuh tempo pelanggan dan melakukan aksi isolir (suspend) setiap hari.

Buka file konfigurasi cron:
```bash
crontab -e
```
Tambahkan baris perintah berikut agar script dieksekusi otomatis setiap jam 01:00 dini hari:
```bash
0 1 * * * php /www/wwwroot/snet_manager/admin/cron_auto_isolir.php >> /var/log/auto_isolir.log 2>&1
```
*(PENTING: Ganti `/www/wwwroot/snet_manager` dengan path absolut lokasi direktori instalasi Anda).*

---

## 🛡️ Diagnosa & Troubleshooting

Setelah tahapan instalasi selesai, kami menyediakan built-in tool untuk melakukan verifikasi integritas sistem.
Akses melalui web browser Anda:
```
http://domain-anda.com/admin/diag.php
```

Halaman diagnosis ini akan menampilkan:
- Status ketersediaan file-file inti (core files).
- Status koneksi ke database.
- Status ekstensi PHP yang dibutuhkan aplikasi.

---

## 📄 Hak Cipta & Lisensi
Proyek **S.NET Manager** merupakan hak milik pengembang atas nama **MUSHAWIR ODEGOA** (Proprietary Software). Dilarang keras mendistribusikan ulang, menjual, menyalin, atau memodifikasi sistem ini untuk tujuan komersial di luar ketentuan tanpa izin resmi.
