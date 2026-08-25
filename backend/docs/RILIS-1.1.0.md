# Deploy Rilis 1.1.0 — Runbook (Backend + Frontend)

Rilis ini mengubah **keduanya**: 50 file backend (termasuk 4 migrasi database, dua di
antaranya memindahkan data lama ke struktur baru) dan 24 file frontend. Jadi urutannya
berbeda dari deploy frontend biasa yang kemarin — migrasi dulu, baru build SPA.

Jalankan perintah satu per satu dan baca hasilnya sebelum lanjut.

| | |
|---|---|
| Server | `root@31.97.187.17` |
| Folder aplikasi | `/var/www/api.nirasarimurni.com` (repo root; backend ada di `backend/`) |
| Web SPA disajikan dari | `/var/www/api.nirasarimurni.com/.output/public` |
| Perkiraan downtime | ± 1–2 menit (maintenance mode saat migrasi) |

---

## Bagian A — Di laptop: kirim kode

> Untuk rilis 1.1.0 langkah ini **sudah selesai**: commit `507167f` sudah ada di
> `fork/backend`. Lompat ke Bagian B. Blok di bawah adalah acuan untuk rilis berikutnya.

### A1. Commit perubahan

```bash
cd ~/projects/sigula-sugar-sync
git add -A
git commit -m "feat: revisi fitur SIGULA 1.1.0 (status penderes, pengepul, tungku multi-grade, pembulatan 500, popup update)"
```

### A2. Push ke branch `backend`

Server menarik dari branch itu, jadi push ke sana:

```bash
git push fork HEAD:backend
```

Kalau remote `fork` belum ada di laptop:

```bash
git remote add fork https://github.com/teddinata/sigula-sugar-sync.git
git push fork HEAD:backend
```

### A3. Pastikan sudah sampai

```bash
git fetch fork backend && git log --oneline fork/backend -1
```

Commit teratas harus sama dengan commit lokal kamu.

---

## Bagian B — Di server: deploy

### B1. Masuk ke server

```bash
ssh root@31.97.187.17
cd /var/www/api.nirasarimurni.com
```

### B2. Backup database (JANGAN dilewati)

Migrasi `090003` menyalin isi `sesi_tungku` ke tabel baru `sesi_tungku_bahan`.
Backup ini satu-satunya titik pulih kalau hasilnya tidak sesuai.

```bash
cd backend
php artisan sigula:backup-db
ls -lh storage/app/backups | tail -3
```

Pastikan ada file baru bertanggal hari ini sebelum lanjut.

### B3. Tarik kode

> ⚠️ **Tarik dari `fork`, bukan `origin`.** `origin` menunjuk ke repo gilangriyanto
> yang belum punya commit rilis ini; `git pull origin main` akan menjawab "Already up
> to date" dan kode barunya tidak pernah masuk.

```bash
cd /var/www/api.nirasarimurni.com
php backend/artisan down --retry=15
git fetch fork backend
git merge --ff-only fork/backend
git log --oneline -1
```

Commit teratas harus sama dengan yang dipush di langkah A3. Kalau `--ff-only` ditolak
(riwayat server menyimpang), pakai `git merge fork/backend`.

> **Kenapa `deploy.sh` tidak dipakai dari langkah pertama?** Opsi `--remote` baru ada
> pada versi script yang ikut di commit ini — sebelum kode ditarik, script lama di
> server belum mengenalnya. Untuk rilis **berikutnya**, setelah 1.1.0 terpasang, satu
> perintah ini sudah cukup menggantikan B3–B9:
>
> ```bash
> sudo bash backend/deploy/deploy.sh --remote fork --branch backend
> ```

### B5. Dependency PHP

```bash
cd /var/www/api.nirasarimurni.com/backend
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction
```

### B6. Migrasi database

```bash
php artisan migrate --force
```

Harus muncul 4 baris `DONE`:

```
2026_08_25_090001_create_petani_status_table ......... DONE
2026_08_25_090002_create_pengepul_table .............. DONE
2026_08_25_090003_create_sesi_tungku_bahan_table ..... DONE
2026_08_25_090004_add_pembulatan_columns ............. DONE
```

Verifikasi pemindahan data — kedua angka **harus sama**:

```bash
php artisan tinker --execute="
  echo 'sesi_tungku       : '.DB::table('sesi_tungku')->count().' baris, '.DB::table('sesi_tungku')->sum('kg_bahan_mentah').\" kg\n\";
  echo 'sesi_tungku_bahan : '.DB::table('sesi_tungku_bahan')->count().' baris, '.DB::table('sesi_tungku_bahan')->sum('kg').\" kg\n\";
"
```

### B7. Cache ulang

**Wajib** — `config/sigula.php` bertambah blok versi, dan config lama masih ter-cache.

```bash
php artisan optimize:clear
php artisan optimize
chown -R www-data:www-data storage bootstrap/cache
```

### B8. Build & pasang frontend

> Jangan jalankan `npm ci`: tidak ada dependency yang berubah di rilis ini, dan
> `package-lock.json` repo ini tidak sinkron dengan `package.json` (soal `ajv` dari
> eslint), sehingga `npm ci` bisa gagal. `npm run build:spa` sudah cukup.

```bash
cd /var/www/api.nirasarimurni.com
npm run build:spa
rm -rf .output/public && mkdir -p .output/public
cp -r dist-spa/. .output/public/
chown -R www-data:www-data .output/public
cat .output/public/version.json
```

`version.json` harus ada dan `buildId`-nya baru — file inilah yang memicu popup
"versi baru tersedia" di browser pengguna.

Nama file bundle (`assets/index-XXXX.js`) juga harus **berbeda** dari build sebelumnya.
Kalau namanya masih sama persis, berarti kode belum berganti — ulangi B3.

### B9. Hidupkan lagi

```bash
systemctl reload php8.3-fpm
php /var/www/api.nirasarimurni.com/backend/artisan up
```

---

## Bagian C — Nginx: jangan cache `version.json`

Kalau file ini di-cache, popup pembaruan tidak akan pernah muncul.

### C1. Cek konfigurasi frontend

```bash
grep -n "expires\|Cache-Control\|version.json" /etc/nginx/sites-available/sigula.nirasarimurni.com
```

### C2. Tambahkan blok berikut kalau `version.json` belum diatur

Sisipkan di dalam `server { ... }` milik `sigula.nirasarimurni.com`, **sebelum**
`location / `:

```nginx
    # Penanda versi build — harus selalu diambil segar, kalau tidak popup
    # "versi baru tersedia" tidak akan pernah terpicu di browser pengguna.
    location = /version.json {
        add_header Cache-Control "no-store, no-cache, must-revalidate" always;
        expires -1;
        default_type application/json;
    }
```

```bash
nano /etc/nginx/sites-available/sigula.nirasarimurni.com
nginx -t && systemctl reload nginx
```

---

## Bagian D — Verifikasi

### D1. Endpoint versi (publik, tanpa token)

```bash
curl -s https://api.nirasarimurni.com/api/v1/versi | head -c 400; echo
```

Harus mengembalikan `"versi":"1.1.0"`.

### D2. Penanda versi web

```bash
curl -sI https://sigula.nirasarimurni.com/version.json | grep -i "cache-control\|http/"
curl -s  https://sigula.nirasarimurni.com/version.json
```

Harapan: `HTTP/2 200`, `cache-control: no-store...`, dan `buildId` yang sama dengan
hasil B8.

### D3. Endpoint baru terdaftar

```bash
cd /var/www/api.nirasarimurni.com/backend
php artisan route:list --path=pengepul
php artisan route:list --path=versi
```

### D4. Uji cepat lewat API

```bash
TOKEN=$(curl -s -X POST https://api.nirasarimurni.com/api/v1/auth/login \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email":"owner@nirasarimurni.com","password":"PASSWORD_KAMU"}' \
  | grep -o '"token":"[^"]*"' | cut -d'"' -f4)

curl -s https://api.nirasarimurni.com/api/v1/pengepul \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' | head -c 200; echo
```

### D5. Cek di browser

Buka `https://sigula.nirasarimurni.com`, login, lalu periksa:

- Versi **v1.1.0** tampil di bawah tulisan "PT Nira Sari Murni" pada sidebar
- **Petani** → tombol Tambah: ada 7 checkbox status penderes, kolom Kode Lahan & RT/RW
- **Master** → ada tab **Pengepul**
- **Pembelian** → form punya dropdown "Pengepul (opsional)"; kwitansi punya tombol
  **Cetak Thermal 58mm**
- **Produksi** → form Mulai Sesi bisa "Tambah grade" dan Karyawan 2 boleh
  "Dikerjakan sendirian"

### D6. Log kalau ada yang aneh

```bash
tail -50 /var/www/api.nirasarimurni.com/backend/storage/logs/laravel-*.log
tail -30 /var/log/nginx/sigula.nirasarimurni.com-error.log
```

---

## Bagian E — Env opsional

Semua punya nilai default, jadi tidak wajib diisi. Tambahkan ke
`/var/www/api.nirasarimurni.com/backend/.env` hanya kalau ingin menimpanya:

```dotenv
SIGULA_VERSION=1.1.0
SIGULA_RELEASED_AT=2026-08-25
# Naikkan HANYA bila versi web lama benar-benar tidak kompatibel lagi dengan API:
# semua pengguna dengan bundel lebih lama akan dipaksa memuat ulang.
SIGULA_MIN_WEB_VERSION=1.1.0
```

Setelah mengubah `.env`, wajib:

```bash
cd /var/www/api.nirasarimurni.com/backend && php artisan optimize
```

---

## Bagian F — Kalau harus mundur

Migrasi rilis ini sudah diuji maju–mundur–maju di MySQL dengan data terisi, dan data
lama tetap utuh.

```bash
cd /var/www/api.nirasarimurni.com/backend
php artisan down
php artisan migrate:rollback --step=4 --force
cd /var/www/api.nirasarimurni.com
git reset --hard <commit-lama>
cd backend && composer install --no-dev -o && php artisan optimize
cd .. && npm run build:spa && rm -rf .output/public && mkdir -p .output/public && cp -r dist-spa/. .output/public/
chown -R www-data:www-data .output/public
systemctl reload php8.3-fpm && php backend/artisan up
```

Catatan: rollback **tidak** mengembalikan `sesi_tungku.karyawan_2_id` menjadi
wajib diisi (kolomnya tetap boleh kosong). Itu disengaja — kalau sudah terlanjur ada
sesi yang dikerjakan satu orang, memaksa NOT NULL akan menggagalkan rollback.

Kalau database perlu dipulihkan sepenuhnya, pakai backup dari langkah B2:

```bash
# MySQL
mysql -u root -p sigula < /var/www/api.nirasarimurni.com/backend/storage/app/backups/sigula_<stempel>.sql
```

---

## Setelah deploy

Pengguna yang tabnya masih terbuka akan otomatis melihat popup **"Versi baru SIGULA
tersedia"** dalam waktu paling lama 5 menit (atau langsung, begitu mereka kembali ke
tab tersebut). Mereka bisa memilih "Nanti saja" — ditunda 6 jam — atau "Perbarui
sekarang" yang langsung memuat ulang halaman.
