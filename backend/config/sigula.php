<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Konfigurasi khusus SIGULA
    |--------------------------------------------------------------------------
    |
    | Nilai-nilai ini sengaja dibaca lewat config (bukan env() langsung di
    | seeder), karena setelah `php artisan config:cache` dijalankan di server
    | production, env() di luar file config akan mengembalikan null.
    |
    */

    // Password awal 3 akun bawaan UserSeeder. WAJIB diganti di production.
    'default_password' => env('SIGULA_DEFAULT_PASSWORD', 'password'),

    // Mengisi data transaksi demo ±6 bulan saat `db:seed`. Set false di production.
    'seed_demo' => env('SIGULA_SEED_DEMO', true),

    /*
    |--------------------------------------------------------------------------
    | Versi aplikasi
    |--------------------------------------------------------------------------
    |
    | Dipakai endpoint GET /api/v1/versi. Frontend membandingkan `minimal_web`
    | dengan versi bundel yang sedang jalan untuk memutuskan apakah pengguna
    | harus memuat ulang halaman (lihat src/lib/versi.ts).
    |
    | Naikkan `aplikasi` setiap rilis, dan `minimal_web` HANYA bila versi web
    | lama benar-benar tidak kompatibel lagi dengan API ini.
    |
    */

    'versi' => [
        'aplikasi' => env('SIGULA_VERSION', '1.1.0'),
        'dirilis' => env('SIGULA_RELEASED_AT', '2026-08-25'),
        'api' => 'v1',
        'minimal_web' => env('SIGULA_MIN_WEB_VERSION', '1.1.0'),

        // Ditampilkan di popup "versi baru tersedia".
        'catatan' => [
            'Status penderes petani bisa lebih dari satu (mis. PMS + PLMD).',
            'Pembelian bahan bisa dicatat lewat pengepul.',
            'Satu tungku bisa memakai beberapa grade bahan sekaligus.',
            'Tungku boleh dikerjakan satu karyawan saja.',
            'Nominal pembelian dan gaji dibulatkan ke kelipatan 500.',
            'Cetak kwitansi dan slip gaji untuk printer thermal 58mm.',
        ],
    ],

];
