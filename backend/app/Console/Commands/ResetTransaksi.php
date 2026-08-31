<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mengosongkan seluruh data transaksi tanpa menyentuh master data.
 *
 * Dipakai saat sistem selesai diuji coba dan siap dipakai sungguhan: data
 * percobaan dibuang, tapi petani, harga, tarif, karyawan, pengepul, eksportir,
 * dan akun pengguna tetap utuh — semuanya butuh waktu lama untuk diisi ulang.
 *
 * Berbeda dari `migrate:fresh --seed` yang menghapus SEMUANYA termasuk akun.
 */
class ResetTransaksi extends Command
{
    protected $signature = 'sigula:reset-transaksi
        {--force : Lewati konfirmasi (untuk skrip otomatis)}
        {--tanpa-backup : Jangan buat backup lebih dulu}';

    protected $description = 'Mengosongkan data transaksi, master data dipertahankan';

    /** Tabel yang dikosongkan, diurutkan dari anak ke induk. */
    private const DIKOSONGKAN = [
        'produksi_karyawan' => 'Porsi hasil produksi per karyawan',
        'sesi_tungku_bahan' => 'Rincian bahan per sesi tungku',
        'sesi_tungku' => 'Sesi tungku',
        'penjualan_item' => 'Baris invoice penjualan',
        'penjualan' => 'Penjualan ke eksportir',
        'pembelian' => 'Pembelian bahan dari petani',
        'gaji_mingguan' => 'Gaji mingguan yang sudah dibayar',
        'biaya_operasional' => 'Biaya operasional',
        'kartu_stok' => 'Kartu stok (mutasi keluar-masuk)',
        'audit_logs' => 'Audit log',
        'nomor_urut' => 'Penomoran kwitansi & invoice',
    ];

    /** Tabel yang sengaja TIDAK disentuh. */
    private const DIPERTAHANKAN = [
        'users' => 'Akun pengguna',
        'petani' => 'Data petani',
        'petani_status' => 'Status penderes petani',
        'pengepul' => 'Pengepul',
        'karyawan' => 'Karyawan',
        'eksportir' => 'Eksportir',
        'grade_harga' => 'Master harga per grade',
        'tarif_upah' => 'Master tarif upah',
    ];

    public function handle(): int
    {
        $this->tampilkanRingkasan();

        if (! $this->option('force') && ! $this->confirm('Kosongkan tabel transaksi di atas?', false)) {
            $this->info('Dibatalkan. Tidak ada yang dihapus.');

            return self::SUCCESS;
        }

        if (! $this->option('tanpa-backup')) {
            $this->info('Membuat backup lebih dulu…');
            $this->call('sigula:backup-db');
        }

        DB::transaction(function (): void {
            Schema::disableForeignKeyConstraints();

            foreach (array_keys(self::DIKOSONGKAN) as $tabel) {
                DB::table($tabel)->delete();
            }

            // Saldo stok dinolkan, bukan dihapus: barisnya jadi titik awal yang
            // rapi untuk stok opname pertama.
            DB::table('stok_saldo')->update(['saldo_kg' => 0, 'updated_at' => now()]);

            Schema::enableForeignKeyConstraints();
        });

        $this->newLine();
        $this->info('Data transaksi dikosongkan. Master data tidak tersentuh.');
        $this->line('  Langkah berikutnya: isi stok awal lewat menu Stok → Stok Opname,');
        $this->line('  atau perintah: php artisan sigula:stok-awal');

        return self::SUCCESS;
    }

    private function tampilkanRingkasan(): void
    {
        $this->newLine();
        $this->warn('AKAN DIKOSONGKAN:');
        $baris = [];

        foreach (self::DIKOSONGKAN as $tabel => $label) {
            $baris[] = [$tabel, $label, number_format(DB::table($tabel)->count())];
        }

        $baris[] = ['stok_saldo', 'Saldo stok (dinolkan, tidak dihapus)', '—'];
        $this->table(['Tabel', 'Isi', 'Baris'], $baris);

        $this->info('DIPERTAHANKAN:');
        $this->table(
            ['Tabel', 'Isi', 'Baris'],
            array_map(
                static fn (string $tabel, string $label): array => [$tabel, $label, number_format(DB::table($tabel)->count())],
                array_keys(self::DIPERTAHANKAN),
                array_values(self::DIPERTAHANKAN),
            ),
        );
    }
}
