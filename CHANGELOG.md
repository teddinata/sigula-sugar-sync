# Changelog SIGULA

Semua perubahan penting SIGULA (Sistem Informasi Gula Terintegrasi — PT Nira Sari Murni)
dicatat di sini. Format mengikuti [Keep a Changelog](https://keepachangelog.com/id/1.1.0/)
dan penomoran mengikuti [Semantic Versioning](https://semver.org/lang/id/).

## Di mana nomor versi disimpan

| Tempat                     | Isi                                              |
| -------------------------- | ------------------------------------------------ |
| `package.json` → `version` | Versi bundel web; ditanam saat `npm run build:spa` |
| `backend/config/sigula.php` → `versi.aplikasi` | Versi yang dilaporkan `GET /api/v1/versi` |
| `dist-spa/version.json`    | Ditulis otomatis tiap build (versi + buildId)     |

Menaikkan versi rilis berarti mengubah **dua** tempat pertama, lalu menambahkan
catatannya di file ini dan di `versi.catatan` (dipakai isi popup pembaruan).

`versi.minimal_web` hanya dinaikkan bila versi web lama benar-benar tidak kompatibel
lagi dengan API — nilainya memaksa semua klien lama menampilkan pembaruan wajib.

## Cara pengguna diberi tahu ada versi baru

Setiap build menulis `version.json` berisi `buildId` unik, dan `buildId` yang sama
ditanam ke dalam bundel. Aplikasi yang sedang terbuka membandingkan keduanya setiap
5 menit, setiap tab kembali aktif, dan saat koneksi pulih. Bila berbeda, muncul popup
"Versi baru SIGULA tersedia" dengan pilihan **Nanti saja** (ditunda 6 jam) atau
**Perbarui sekarang** (muat ulang). Jadi deploy ulang tetap terdeteksi walau nomor
versinya tidak naik.

---

## [1.1.0] — 2026-08-25

Revisi fitur dari dokumen `dokumentasi-fitur-tambahan-SIGULA.pdf`.

### Ditambahkan

- **Role Admin.** Menjalankan seluruh operasional harian (petani, master, pembelian,
  stok, produksi, penggajian, penjualan) tapi tidak bisa membuka menu Keuangan —
  laba rugi, biaya operasional, dan ringkasan AI tertutup untuknya, termasuk kartu
  pendapatan/laba di Dashboard.
- **Kelola Pengguna** (`/api/v1/pengguna`), khusus Owner yang sekaligus berperan
  superadmin: membuat akun, mengatur role, menonaktifkan, dan mengganti password.
  Owner tidak bisa menurunkan role atau menonaktifkan akunnya sendiri, dan sistem
  menolak perubahan yang menyisakan nol Owner aktif.
- **Audit Log jadi menu tersendiri** dengan ability `lihat-audit` (Owner + Admin),
  lengkap dengan filter per modul, rentang tanggal, dan pencarian teks. Sebelumnya
  terkubur sebagai tab di halaman Keuangan sehingga Admin tidak bisa membukanya.
- **Login dan logout ikut tercatat** di audit log (`auth.login`, `auth.logout`).
- **Guard rute di frontend**: membuka URL menu terlarang langsung dialihkan ke
  Dashboard dengan pesan, bukan menampilkan halaman yang gagal memuat.
- Akun seeder keempat: `admin@nirasarimurni.com` dengan role Admin.

- **Status penderes petani (bisa lebih dari satu).** Satu petani boleh menyandang
  beberapa status sekaligus (mis. `PMS + PLMD`), disimpan sebagai relasi di tabel
  `petani_status`. Tujuh kode: PMS, PMMS, PLMR, PLMD, PLS, PL, PM. Daftar petani bisa
  disaring per status (`?statusPenderes=pms,plmd`).
- **Kode lahan & RT/RW petani.** `kodeLahan` unik antar petani.
- **Modul Pengepul.** CRUD pengepul (`/api/v1/pengepul`) dan kolom `pengepulId` pada
  transaksi pembelian, plus filter `?pengepulId=` dan `?punyaPengepul=`. Pengepul yang
  sudah punya transaksi hanya dinonaktifkan, tidak dihapus, agar riwayat tetap utuh.
- **Bahan tungku multi-grade.** Satu sesi tungku bisa memakai beberapa grade sekaligus
  lewat array `bahan[]`; stok dicek dan dipotong per grade.
- **Tombol "Tambah Karyawan" di form produksi.** Karyawan baru langsung terpilih ke slot
  yang masih kosong, tanpa harus pindah ke halaman Master.
- **Cetak thermal 58mm** untuk kwitansi pembelian dan slip gaji.
- **Tombol Export (CSV/Excel/PDF)** di halaman Pembelian, Produksi, Penggajian,
  Penjualan, Stok, dan Keuangan — filter yang sedang aktif ikut terbawa ke isi file.
- **Kartu Ringkasan Keuangan AI** di halaman Keuangan; dibuat saat diminta (bukan
  otomatis) karena tiap panggilan model berbiaya, dengan tombol buat ulang untuk
  melewati cache 30 menit.
- **Endpoint versi publik** `GET /api/v1/versi` + popup pengingat pembaruan di frontend.
- **Versi aplikasi tampil di sidebar** supaya mudah memastikan pengguna sudah update.
- **Data operasional client** lewat `DataClientSeeder`: 195 petani Desa Batuanten
  (beserta kode lahan, RT/RW, dan status penderesnya), 5 pengepul, dan 33 karyawan
  pemasak. CSV sumbernya disimpan di `backend/database/data/petani-batuanten.csv`.
  Idempoten — menjalankan ulang memperbarui, tidak menggandakan.
- **Perintah `sigula:reset-transaksi`** — mengosongkan seluruh data transaksi
  (pembelian, produksi, penjualan, gaji, biaya, kartu stok, audit log, penomoran)
  sambil mempertahankan master data dan akun pengguna. Saldo stok dinolkan, bukan
  dihapus, supaya jadi titik awal stok opname pertama. Backup dibuat otomatis.
- **Perintah `sigula:stok-awal`** — mengisi saldo stok awal lewat mekanisme stok
  opname, jadi ikut tercatat di kartu stok dan audit log:
  `php artisan sigula:stok-awal --kg="ns1=22193"`.
- **Perintah ganti password** `php artisan sigula:ganti-password <email>` (atau
  `--semua`) — password diketik interaktif sehingga tidak masuk shell history, dan
  seluruh token Sanctum akun itu ikut dicabut.
- **Perintah impor petani** `php artisan sigula:impor-petani <file.csv>` — header dan
  pemisah dideteksi otomatis, status kombinasi (`PMS + PLMR`) dipecah sendiri, dan
  menjalankan ulang memperbarui data alih-alih menggandakannya (`--uji-coba` untuk
  melihat hasilnya tanpa menyimpan).

### Diubah

- **Identitas petani disatukan.** Kode lahan (mis. `BA-002`) sekaligus berfungsi
  sebagai nomor member, jadi kolom `nomor_member` dilebur ke `kode_lahan` dan kolom
  `status` dihapus — Member/Non-Member kini disimpulkan dari terisi atau tidaknya kode
  lahan. Generator nomor member 3 digit ikut dihapus.
- **Petani bisa dinonaktifkan** (kolom `aktif`) tanpa menghapus riwayat transaksinya;
  daftar hanya menampilkan yang aktif kecuali `?sertakanNonaktif=1`.
- **Tabel petani bisa diatur kolomnya.** RT/RW jadi kolom tersendiri; Status Member,
  Kontak, dan Alamat disembunyikan secara default dan bisa dimunculkan lewat tombol
  **Kolom**. Pilihannya diingat per browser.

- **Tungku boleh dikerjakan satu karyawan.** `karyawan2Id` jadi opsional; tanpa rekan
  kerja, seluruh hasil menjadi porsi satu orang (tidak dibagi dua).
- **Pembulatan nominal ke kelipatan 500** pada total pembelian dan gaji mingguan: sisa
  di atas kelipatan 1.000 naik ke 500 bila ≤ 500, selebihnya ke 1.000 berikutnya.
  Nilai hasil hitungan asli tetap disimpan dan dikirim sebagai `totalSebelumBulat`.
- **Kg bahan dan hasil produksi menerima desimal** (maksimal 2 angka di belakang koma).
- Kontak karyawan jadi opsional di form Master, mengikuti aturan backend.

### Dihapus

- **Batas atas rendemen.** Hasil produksi tidak lagi ditolak ketika melebihi bahan
  mentah: di lapangan kadang ada penambahan gula di luar sistem, sehingga rendemen
  di atas 100% wajar dan cukup dicatat apa adanya.

### Diperbaiki

- `down()` migrasi status penderes gagal di SQLite karena kolom `kode_lahan` masih
  dipakai index unik; index kini dilepas lebih dulu sehingga rollback bersih.
- `docs/SIGULA.postman_collection.json` sempat rusak (dua versi koleksi tersambung saat
  merge) dan kini dipulihkan serta dilengkapi endpoint baru — 63 request.

---

## [1.0.0] — 2026-08-13

Rilis awal: autentikasi Sanctum + otorisasi per-role, data petani, master harga & tarif
berversi, pembelian bahan, kartu stok saldo berjalan, produksi sesi tungku, penggajian
mingguan Senin–Jumat, penjualan ke eksportir, laba rugi & biaya operasional, audit log,
ekspor laporan (CSV/XLSX/PDF), dan ringkasan keuangan berbasis AI.
