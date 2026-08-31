<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ResolvesFromInput;

/** Role pengguna sistem sesuai matriks hak akses SIGULA. */
enum Role: string
{
    use ResolvesFromInput;

    case OWNER = 'owner';
    case ADMIN = 'admin';
    case STAFF_GUDANG = 'staff_gudang';
    case STAFF_PRODUKSI = 'staff_produksi';

    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'Owner',
            self::ADMIN => 'Admin',
            self::STAFF_GUDANG => 'Staff Gudang',
            self::STAFF_PRODUKSI => 'Staff Produksi',
        };
    }

    /** Penjelasan singkat untuk dropdown pemilihan role di menu Pengguna. */
    public function keterangan(): string
    {
        return match ($this) {
            self::OWNER => 'Akses penuh, termasuk keuangan dan pengelolaan pengguna',
            self::ADMIN => 'Seluruh operasional harian, tanpa akses keuangan',
            self::STAFF_GUDANG => 'Petani, pembelian, dan stok',
            self::STAFF_PRODUKSI => 'Sesi tungku dan hasil produksi',
        };
    }

    /**
     * Kemampuan (gate ability) yang dimiliki role ini.
     *
     * @return array<int, string>
     */
    public function abilities(): array
    {
        return match ($this) {
            self::OWNER => [
                'lihat-dashboard', 'lihat-keuangan', 'kelola-keuangan',
                'lihat-master', 'kelola-master',
                'lihat-petani', 'kelola-petani',
                'lihat-pembelian', 'kelola-pembelian',
                'lihat-stok', 'kelola-stok',
                'lihat-produksi', 'kelola-produksi',
                'lihat-penggajian', 'kelola-penggajian',
                'lihat-penjualan', 'kelola-penjualan',
                'lihat-audit',
                // Owner sekaligus superadmin: hanya dia yang boleh membuat akun
                // dan mengubah role orang lain.
                'lihat-user', 'kelola-user',
            ],

            // Admin menjalankan operasional penuh, tapi angka keuntungan
            // perusahaan (laba rugi, biaya operasional) tertutup untuknya.
            self::ADMIN => [
                'lihat-dashboard',
                'lihat-master', 'kelola-master',
                'lihat-petani', 'kelola-petani',
                'lihat-pembelian', 'kelola-pembelian',
                'lihat-stok', 'kelola-stok',
                'lihat-produksi', 'kelola-produksi',
                'lihat-penggajian', 'kelola-penggajian',
                'lihat-penjualan', 'kelola-penjualan',
                'lihat-audit',
            ],
            self::STAFF_GUDANG => [
                'lihat-dashboard',
                'lihat-master',
                'lihat-petani', 'kelola-petani',
                'lihat-pembelian', 'kelola-pembelian',
                'lihat-stok', 'kelola-stok',
                'lihat-produksi',
            ],
            self::STAFF_PRODUKSI => [
                'lihat-dashboard',
                'lihat-master',
                'lihat-stok',
                'lihat-produksi', 'kelola-produksi',
            ],
        };
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->abilities(), true);
    }

    /** Menu sidebar yang boleh dibuka role ini (dipakai frontend). */
    public function menu(): array
    {
        $menu = [
            'dashboard' => 'lihat-dashboard',
            'petani' => 'lihat-petani',
            'master' => 'lihat-master',
            'pembelian' => 'lihat-pembelian',
            'stok' => 'lihat-stok',
            'produksi' => 'lihat-produksi',
            'penggajian' => 'lihat-penggajian',
            'penjualan' => 'lihat-penjualan',
            'keuangan' => 'lihat-keuangan',
            'audit' => 'lihat-audit',
            'pengguna' => 'lihat-user',
        ];

        return array_values(array_keys(array_filter($menu, fn (string $ability): bool => $this->can($ability))));
    }
}
