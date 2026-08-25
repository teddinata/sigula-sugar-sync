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
- **Endpoint versi publik** `GET /api/v1/versi` + popup pengingat pembaruan di frontend.
- **Versi aplikasi tampil di sidebar** supaya mudah memastikan pengguna sudah update.
- **Perintah impor petani** `php artisan sigula:impor-petani <file.csv>` — header dan
  pemisah dideteksi otomatis, status kombinasi (`PMS + PLMR`) dipecah sendiri, dan
  menjalankan ulang memperbarui data alih-alih menggandakannya (`--uji-coba` untuk
  melihat hasilnya tanpa menyimpan).

### Diubah

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
