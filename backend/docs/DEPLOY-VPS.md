# SIGULA Deployment

> **Sudah pernah deploy?** Panduan ini untuk setup server dari nol. Untuk memasang
> rilis baru di server yang sudah jalan, pakai [`RILIS-1.1.0.md`](RILIS-1.1.0.md).

Panduan deploy backend SIGULA ke VPS, mengikuti alur **BWAStore Deployment** tapi
sudah disesuaikan dengan Laravel 13. Jalankan perintahnya satu per satu dari atas
ke bawah.

> **Yang berbeda dari panduan BWAStore** — jangan pakai versi lamanya, akan gagal:
>
> |                 | BWAStore                       | SIGULA                                            |
> | --------------- | ------------------------------ | ------------------------------------------------- |
> | PHP             | 7.2                            | **8.3** (Laravel 13 menolak PHP < 8.3)            |
> | Ubuntu          | 18.04                          | **22.04 / 24.04**                                 |
> | Socket PHP      | `/var/run/php/php7.2-fpm.sock` | `/run/php/php8.3-fpm.sock`                        |
> | Buat user MySQL | `GRANT ... IDENTIFIED BY`      | `CREATE USER` lalu `GRANT` **terpisah** (MySQL 8) |
> | Document root   | `/var/www/DOMAIN/public`       | `/var/www/DOMAIN/**backend**/public`              |
> | Migrasi         | `php artisan migrate`          | `php artisan migrate **--force**`                 |

---

## 1. Persiapan di laptop

Push dulu foldernya ke GitHub — kalau belum, `git clone` di server tidak akan menemukan backend.

```bash
cd /Users/mymac/projects/sigula-sugar-sync
```

```bash
git add backend .gitignore
```

```bash
git commit -m "feat: backend API SIGULA (Laravel 13)"
```

```bash
git push origin main
```

**Spesifikasi VPS**: Ubuntu 22.04/24.04, RAM **minimal 2 GB** (1 GB berisiko gagal
saat `composer install`), disk 20 GB.

---

## 2. Masuk ke Server

1. Ambil **Public IP** dari dashboard VPS (IDCloudHost → Compute)
2. Masuk lewat PuTTY (Windows) atau terminal:

```bash
ssh ubuntu@IP_SERVER_KAMU
```

3. Naik ke root:

```bash
sudo su
```

---

## 3. Siapkan variabel (biar tidak salah ketik)

Ganti tiga nilai di bawah sesuai punya Anda, lalu jalankan **satu per satu**.

```bash
DOMAIN=api.nirasarimurni.com
```

```bash
REPO=https://github.com/gilangriyanto/sigula-sugar-sync.git
```

```bash
APP=/var/www/$DOMAIN/backend
```

Cek sudah benar:

```bash
echo "domain=$DOMAIN"; echo "repo=$REPO"; echo "app=$APP"
```

> ⚠️ **Kalau koneksi SSH terputus di tengah jalan, ulangi langkah 3 ini** sebelum
> melanjutkan — variabel hilang saat sesi baru.
>
> Belum punya domain? Isi `DOMAIN` dengan IP server dulu, ganti nanti setelah DNS siap.

---

## 4. Mengatur Firewall

```bash
ufw app list
```

```bash
ufw allow OpenSSH
```

```bash
ufw --force enable
```

```bash
ufw status
```

---

## 5. Install Nginx

```bash
apt update
```

```bash
apt upgrade -y
```

```bash
apt install -y nginx
```

```bash
ufw allow 'Nginx Full'
```

```bash
ufw status
```

Buka `http://IP_SERVER_KAMU` di browser — harus muncul halaman **Welcome to nginx**.

---

## 6. Install MySQL

```bash
apt install -y mysql-server
```

```bash
systemctl enable --now mysql
```

```bash
mysql_secure_installation
```

Ikuti wizard-nya (set password root, hapus anonymous user, dst — sesuaikan keinginan).

> Di Ubuntu 22.04+, root MySQL memakai `auth_socket` sehingga `sudo mysql` langsung
> masuk tanpa password. Tidak perlu `ALTER USER ... mysql_native_password` seperti
> panduan lama, kecuali memang butuh login root lewat TCP.

---

## 7. Install PHP 8.3

```bash
apt install -y software-properties-common
```

```bash
add-apt-repository -y ppa:ondrej/php
```

```bash
apt update
```

```bash
apt install -y php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-bcmath php8.3-curl php8.3-zip php8.3-intl php8.3-gd php8.3-opcache
```

Pastikan versinya benar:

```bash
php -v
```

Pastikan socket-nya ada (dipakai di konfigurasi Nginx nanti):

```bash
ls /run/php/
```

Harus terlihat `php8.3-fpm.sock`.

---

## 8. Tes PHP (opsional)

```bash
nano /etc/nginx/sites-available/default
```

Cari baris `index` lalu ubah jadi:

```
index index.php index.html index.htm index.nginx-debian.html;
```

Tambahkan di dalam blok `server { }`:

```
location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
}

location ~ /\.ht {
        deny all;
}
```

Simpan (Ctrl+O, Enter, Ctrl+X), lalu:

```bash
echo "<?php phpinfo();" > /var/www/html/info.php
```

```bash
nginx -t
```

```bash
systemctl reload nginx
```

Buka `http://IP_SERVER_KAMU/info.php` — harus muncul halaman info PHP 8.3.

**Hapus lagi setelah selesai** (file ini membocorkan konfigurasi server):

```bash
rm /var/www/html/info.php
```

---

## 9. Install Composer

```bash
apt install -y git unzip curl
```

```bash
cd ~
```

```bash
curl -sS https://getcomposer.org/installer -o composer-setup.php
```

```bash
curl -sS https://composer.github.io/installer.sig -o composer-setup.sig
```

Verifikasi installer-nya asli:

```bash
php -r "echo (hash_file('sha384','composer-setup.php') === trim(file_get_contents('composer-setup.sig')) ? 'Installer verified' : 'Installer corrupt'), PHP_EOL;"
```

Harus muncul **Installer verified**. Kalau `Installer corrupt`, ulangi dua perintah `curl` di atas.

```bash
php composer-setup.php --install-dir=/usr/local/bin --filename=composer
```

```bash
rm composer-setup.php composer-setup.sig
```

```bash
composer --version
```

---

## 10. Buat Database

```bash
mysql
```

Setelah masuk prompt `mysql>`, jalankan satu per satu. **Ganti `PASSWORD_DB_KAMU`**
dengan password yang kuat, dan catat — nanti dipakai di `.env`.

```sql
CREATE DATABASE sigula CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```sql
CREATE USER 'sigula'@'localhost' IDENTIFIED BY 'PASSWORD_DB_KAMU';
```

```sql
GRANT ALL PRIVILEGES ON sigula.* TO 'sigula'@'localhost';
```

```sql
FLUSH PRIVILEGES;
```

```sql
EXIT;
```

> Perhatikan: di MySQL 8, `CREATE USER` dan `GRANT` **harus terpisah**. Sintaks
> gabungan `GRANT ... IDENTIFIED BY '...'` pada panduan lama sudah dihapus dan
> akan menghasilkan error 1064.

---

## 11. Setup Aplikasi

```bash
cd /var/www
```

```bash
git clone $REPO $DOMAIN
```

```bash
cd $APP
```

```bash
pwd
```

Harus menampilkan `/var/www/DOMAIN_KAMU/backend` — perhatikan akhiran **backend**,
karena Laravel-nya ada di subfolder repo.

```bash
composer install --no-dev --optimize-autoloader
```

---

## 12. Konfigurasi .env

Ganti dulu **PASSWORD_DB_KAMU** dan **PASSWORD_AKUN_KAMU** di teks bawah ini
(edit di Notepad/editor Anda), baru tempel seluruhnya sekaligus ke terminal:

```bash
cat > $APP/.env <<'ENVFILE'
APP_NAME=SIGULA
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://api.nirasarimurni.com

APP_LOCALE=id
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=id_ID
APP_TIMEZONE=Asia/Jakarta

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sigula
DB_USERNAME=sigula
DB_PASSWORD=PASSWORD_DB_KAMU

FRONTEND_URL=https://nirasarimurni.com

SIGULA_DEFAULT_PASSWORD=PASSWORD_AKUN_KAMU
SIGULA_SEED_DEMO=false

SESSION_DRIVER=database
SESSION_LIFETIME=120
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=log
MAIL_FROM_ADDRESS="no-reply@nirasarimurni.com"
MAIL_FROM_NAME="${APP_NAME}"
ENVFILE
```

Cek isinya sudah benar:

```bash
cat $APP/.env
```

Keterangan isian penting:

| Baris                     | Isi dengan                                                           |
| ------------------------- | -------------------------------------------------------------------- |
| `APP_URL`                 | URL API, pakai `https://` kalau nanti dipasang SSL                   |
| `DB_PASSWORD`             | Password dari langkah 10                                             |
| `FRONTEND_URL`            | Domain frontend (untuk CORS). Beberapa domain pisahkan dengan koma   |
| `SIGULA_DEFAULT_PASSWORD` | Password awal 3 akun login                                           |
| `APP_DEBUG`               | **Wajib `false`** — kalau `true`, pesan error membocorkan isi `.env` |
| `SIGULA_SEED_DEMO`        | **`false`** supaya data transaksi demo tidak ikut masuk              |

Kalau mau mengubah lagi nanti:

```bash
nano $APP/.env
```

---

## 13. Generate Key, Migrasi, dan Data Awal

```bash
cd $APP
```

```bash
php artisan key:generate --force
```

```bash
php artisan migrate --force
```

```bash
php artisan db:seed --class="Database\Seeders\UserSeeder" --force
```

```bash
php artisan db:seed --class="Database\Seeders\MasterSeeder" --force
```

Ini mengisi 3 akun login + master data (harga per grade, tarif gaji, karyawan,
eksportir). Data transaksi demo tidak ikut karena `SIGULA_SEED_DEMO=false`.

> **Urutan penting:** seeder dijalankan **sebelum** `php artisan optimize` di
> langkah 16. Setelah config di-cache, `.env` tidak dibaca ulang.

Cek tabelnya sudah jadi:

```bash
php artisan migrate:status | tail -5
```

---

## 14. Atur Izin Folder

```bash
chown -R www-data:www-data $APP/storage
```

```bash
chown -R www-data:www-data $APP/bootstrap/cache
```

```bash
chmod -R 775 $APP/storage
```

```bash
chmod -R 775 $APP/bootstrap/cache
```

---

## 15. Setting Nginx

Buat virtual host — tempel seluruh blok ini sekaligus (isian `$DOMAIN` dan `$APP`
terisi otomatis dari langkah 3):

```bash
cat > /etc/nginx/sites-available/$DOMAIN <<NGINXCONF
server {
    listen 80;
    listen [::]:80;

    server_name $DOMAIN;
    root $APP/public;

    index index.php;
    charset utf-8;
    client_max_body_size 20M;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    gzip on;
    gzip_types application/json application/javascript text/css text/plain;
    gzip_min_length 1024;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php\$ {
        try_files \$uri =404;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        include fastcgi_params;
        fastcgi_read_timeout 120;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    access_log /var/log/nginx/$DOMAIN-access.log;
    error_log  /var/log/nginx/$DOMAIN-error.log;
}
NGINXCONF
```

Cek hasilnya (pastikan `server_name` dan `root` sudah terisi, bukan masih `$DOMAIN`):

```bash
cat /etc/nginx/sites-available/$DOMAIN
```

Aktifkan:

```bash
ln -s /etc/nginx/sites-available/$DOMAIN /etc/nginx/sites-enabled/
```

> ⚠️ Perhatikan **spasi** sebelum `/etc/nginx/sites-enabled/`. Di panduan BWAStore
> baris ini salah ketik (menempel tanpa spasi) sehingga symlink-nya gagal dibuat.

Matikan site bawaan supaya tidak menangkap request domain ini:

```bash
rm -f /etc/nginx/sites-enabled/default
```

```bash
nginx -t
```

Harus muncul:

```
nginx: the configuration file /etc/nginx/nginx.conf syntax is ok
nginx: configuration file /etc/nginx/nginx.conf test is successful
```

```bash
systemctl reload nginx
```

---

## 16. Optimasi

```bash
cd $APP
```

```bash
php artisan optimize
```

Perintah ini men-cache config, route, dan event sekaligus.

---

## 17. Pasang Cron (backup database harian)

```bash
crontab -u www-data -e
```

Pilih editor `nano` bila ditanya, lalu tambahkan **satu baris** di paling bawah
(ganti `api.nirasarimurni.com` dengan domain Anda):

```
* * * * * cd /var/www/api.nirasarimurni.com/backend && php artisan schedule:run >> /dev/null 2>&1
```

Simpan (Ctrl+O, Enter, Ctrl+X), lalu cek:

```bash
crontab -u www-data -l
```

Ini menjalankan backup database tiap hari jam 02:00 (disimpan 14 hari terakhir)
dan membersihkan token kedaluwarsa.

Tes backup manual sekarang:

```bash
php artisan sigula:backup-db
```

```bash
ls -lh $APP/storage/app/backups/
```

---

## 18. Tes API

```bash
curl -s -o /dev/null -w "%{http_code}\n" -H "Accept: application/json" http://127.0.0.1/api/v1/auth/me
```

Harus keluar **401** — artinya API hidup dan menolak akses tanpa token (benar).

Tes login sungguhan (ganti password sesuai `SIGULA_DEFAULT_PASSWORD` Anda):

```bash
curl -X POST http://127.0.0.1/api/v1/auth/login -H "Content-Type: application/json" -H "Accept: application/json" -d '{"email":"owner@nirasarimurni.com","password":"PASSWORD_AKUN_KAMU"}'
```

Harus mengembalikan `token` dan data `user`.

---

## 19. Setting Domain

1. Masuk panel DNS (IDCloudHost → **Manage DNS**)
2. **Add Record**
3. **Name**: `api` (untuk `api.nirasarimurni.com`)
4. **Type**: `A`
5. **RDATA / IP Address**: IP server Anda
6. Save

Cek propagasi dari laptop:

```bash
dig +short api.nirasarimurni.com
```

Kalau sudah keluar IP server, buka `http://api.nirasarimurni.com/api/v1/auth/me` —
harus keluar JSON `{"message":"Unauthenticated."}`.

Belum muncul? Tunggu propagasi dulu, sambil ngopi.

---

## 20. Pasang SSL (HTTPS)

Wajib kalau frontend sudah HTTPS — browser memblokir request HTTPS → HTTP.

```bash
apt install -y certbot python3-certbot-nginx
```

```bash
certbot --nginx -d $DOMAIN
```

Ikuti wizard-nya (isi email, setuju TOS, pilih redirect HTTP → HTTPS).

Setelah SSL aktif, pastikan `APP_URL` di `.env` sudah `https://`:

```bash
nano $APP/.env
```

Lalu segarkan cache:

```bash
cd $APP && php artisan optimize:clear && php artisan optimize
```

Tes dari laptop:

```bash
curl https://api.nirasarimurni.com/api/v1/auth/me -H "Accept: application/json"
```

---

## 21. Ganti Password Akun

Tiga akun bawaan memakai password yang sama dari `SIGULA_DEFAULT_PASSWORD`.
Ganti masing-masing:

```bash
cd $APP && php artisan tinker
```

Di dalam tinker:

```php
$u = App\Models\User::where('email','owner@nirasarimurni.com')->first();
```

```php
$u->password = 'password-baru-yang-kuat';
```

```php
$u->save();
```

```php
exit
```

Ulangi untuk `gudang@nirasarimurni.com` dan `produksi@nirasarimurni.com`.

---

## 22. Update Kode Berikutnya

Setiap ada perubahan kode, jalankan berurutan. Bagian ini dijalankan di sesi SSH
baru, jadi **pakai path lengkap** (variabel `$APP` dari langkah 3 sudah hilang) —
ganti `api.nirasarimurni.com` dengan domain Anda:

```bash
cd /var/www/api.nirasarimurni.com/backend
```

```bash
php artisan down
```

```bash
git pull origin main
```

```bash
composer install --no-dev --optimize-autoloader
```

```bash
php artisan migrate --force
```

```bash
php artisan optimize:clear
```

```bash
php artisan optimize
```

```bash
chown -R www-data:www-data storage bootstrap/cache
```

```bash
systemctl reload php8.3-fpm
```

```bash
php artisan up
```

> Ada juga versi otomatisnya — satu perintah menjalankan seluruh urutan di atas,
> dan otomatis mengeluarkan aplikasi dari maintenance mode kalau ada langkah yang gagal:
>
> ```bash
> sudo bash /var/www/api.nirasarimurni.com/backend/deploy/deploy.sh
> ```

---

## 23. Troubleshooting

| Gejala                       | Penyebab                      | Perbaikan                                            |
| ---------------------------- | ----------------------------- | ---------------------------------------------------- |
| **502 Bad Gateway**          | Socket PHP salah              | `ls /run/php/` lalu samakan `fastcgi_pass` di vhost  |
| **500 / layar putih**        | Izin folder                   | Ulangi langkah 14                                    |
| **500 setelah `git pull`**   | Cache config lama             | `php artisan optimize:clear && php artisan optimize` |
| **404 di semua endpoint**    | `root` salah folder           | Harus berakhir `/backend/public`                     |
| **"could not be opened"**    | `storage/logs` tidak writable | Ulangi langkah 14                                    |
| **CORS error di browser**    | `FRONTEND_URL` belum sesuai   | Perbaiki `.env` lalu `php artisan optimize`          |
| **`SQLSTATE[HY000] [1045]`** | Password DB salah             | Cocokkan `.env` dengan langkah 10                    |
| **`composer install` mati**  | RAM kurang                    | Tambah swap (lihat bawah)                            |
| **Migrasi minta konfirmasi** | Lupa `--force`                | `php artisan migrate --force`                        |

Lihat log kalau ada error (ganti `api.nirasarimurni.com` dengan domain Anda):

```bash
tail -50 /var/www/api.nirasarimurni.com/backend/storage/logs/laravel-*.log
```

```bash
tail -50 /var/log/nginx/api.nirasarimurni.com-error.log
```

Tambah swap 2 GB kalau RAM pas-pasan:

```bash
fallocate -l 2G /swapfile
```

```bash
chmod 600 /swapfile
```

```bash
mkswap /swapfile
```

```bash
swapon /swapfile
```

```bash
echo '/swapfile none swap sw 0 0' >> /etc/fstab
```

```bash
free -h
```

---

## 24. Deploy Frontend (opsional)

> **Penting:** frontend ini pakai **TanStack Start** (React, server-rendered
> lewat Nitro) — hasil build-nya adalah **proses Node yang harus tetap hidup**,
> bukan kumpulan file statis (`dist/` + `index.html`) seperti SPA biasa. Build
> target sudah dikunci ke preset `node-server` di `vite.config.ts` (bukan
> default Cloudflare Workers bawaan Lovable), jadi hasil build ini betul-betul
> jalan sebagai server Node biasa — bukan Worker yang butuh runtime Cloudflare.
>
> Kalau sebelumnya deploy terasa lag/berat tanpa error di console browser,
> kemungkinan besar penyebabnya di sini: build lama menyasar Cloudflare Workers
> (`wrangler.json`, tidak ada folder `dist/` sama sekali), lalu dipaksa
> disajikan seolah SPA statis atau dijalankan dengan runtime yang salah.

### 24.1 Install Node.js di VPS (sekali saja)

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
```

```bash
apt install -y nodejs
```

```bash
node -v
```

Pastikan versi **22.x** ke atas.

### 24.2 Install PM2 — process manager (sekali saja)

```bash
npm install -g pm2
```

### 24.3 Build di laptop

```bash
cd /Users/mymac/projects/sigula-sugar-sync
```

```bash
echo 'VITE_API_BASE_URL=https://api.nirasarimurni.com/api/v1' > .env.production
```

```bash
npm ci && npm run build
```

Hasil build ada di folder **`.output/`** (bukan `dist/`): `.output/server/`
berisi proses Node yang dijalankan (`index.mjs`), `.output/public/` berisi
aset statis yang dilayani otomatis oleh proses itu sendiri.

### 24.4 Unggah ke server

```bash
scp -r .output ubuntu@IP_SERVER_KAMU:/tmp/frontend-output
```

Di server:

```bash
mkdir -p /var/www/frontend
```

```bash
rm -rf /var/www/frontend/*
```

```bash
mv /tmp/frontend-output/.output/* /var/www/frontend/
```

### 24.5 Jalankan sebagai proses Node lewat PM2

```bash
cd /var/www/frontend
```

```bash
pm2 start server/index.mjs --name sigula-frontend
```

```bash
pm2 save
```

```bash
pm2 startup
```

Perintah terakhir mencetak satu baris `sudo env PATH=...` — salin dan jalankan
baris itu supaya PM2 otomatis start lagi setelah server reboot.

Cek prosesnya benar-benar merespons (Nitro `node-server` default listen di
port **3000**):

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:3000
```

Harus **200**. Kalau mau port lain: `PORT=4000 pm2 start server/index.mjs --name sigula-frontend`.

### 24.6 Nginx sebagai reverse proxy (BUKAN static file server)

```bash
cat > /etc/nginx/sites-available/frontend <<'NGINXWEB'
server {
    listen 80;
    server_name nirasarimurni.com www.nirasarimurni.com;

    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_cache_bypass $http_upgrade;
    }
}
NGINXWEB
```

```bash
ln -s /etc/nginx/sites-available/frontend /etc/nginx/sites-enabled/
```

```bash
nginx -t && systemctl reload nginx
```

```bash
certbot --nginx -d nirasarimurni.com -d www.nirasarimurni.com
```

### 24.7 Update kode berikutnya (frontend)

Ulangi langkah 24.3–24.4 di laptop, lalu di server:

```bash
pm2 reload sigula-frontend
```

---

Pastikan domain frontend terdaftar di `FRONTEND_URL` pada `.env` backend, lalu
`php artisan optimize`. Cara menyambungkan kodenya ada di
[INTEGRASI-FRONTEND.md](INTEGRASI-FRONTEND.md).

---

## Ringkasan hasil akhir

|          |                                                                          |
| -------- | ------------------------------------------------------------------------ |
| API      | `https://api.nirasarimurni.com/api/v1`                                   |
| Aplikasi | `/var/www/api.nirasarimurni.com/backend`                                 |
| Database | `sigula`                                                                 |
| Akun     | `owner@` (Owner), `gudang@` (Staff Gudang), `produksi@` (Staff Produksi) |
| Backup   | otomatis 02:00 → `storage/app/backups/`, disimpan 14 hari                |
| Log      | `storage/logs/laravel-*.log` · `/var/log/nginx/DOMAIN-error.log`         |

Uji seluruh endpoint lewat Postman: import
[`SIGULA.postman_collection.json`](SIGULA.postman_collection.json), ganti variable
`baseUrl` jadi `https://api.nirasarimurni.com/api/v1`, lalu jalankan request Login.
