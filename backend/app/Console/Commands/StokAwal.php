<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\KategoriStok;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Services\StokService;
use Illuminate\Console\Command;

/**
 * Mengisi saldo stok awal saat sistem mulai dipakai.
 *
 * Memakai mekanisme stok opname yang sama dengan menu Stok, jadi hasilnya ikut
 * tercatat di kartu stok sebagai mutasi masuk dan di audit log — bukan angka
 * yang tiba-tiba muncul tanpa jejak.
 *
 *   php artisan sigula:stok-awal                       (tanya satu per satu)
 *   php artisan sigula:stok-awal --kg="ns1=22193"      (langsung)
 *   php artisan sigula:stok-awal --kg="ns1=22193,kristal=840"
 */
class StokAwal extends Command
{
    protected $signature = 'sigula:stok-awal
        {--kg= : Daftar "kategori=kg" dipisah koma, mis. ns1=22193,ns2=1500}
        {--tanggal= : Tanggal pencatatan (default hari ini)}
        {--alasan=Stok awal sistem : Keterangan yang tersimpan di kartu stok}';

    protected $description = 'Mengisi saldo stok awal lewat mekanisme stok opname';

    public function handle(StokService $stok): int
    {
        $target = $this->option('kg') ? $this->uraiOpsi((string) $this->option('kg')) : $this->tanyaSatuPerSatu();

        if ($target === []) {
            $this->info('Tidak ada stok yang diisi.');

            return self::SUCCESS;
        }

        // Pelaku dicatat sebagai Owner pertama supaya audit log tidak kosong.
        $pelaku = User::query()->where('role', 'owner')->orderBy('id')->first();
        $tanggal = $this->option('tanggal') ?: null;
        $alasan = (string) $this->option('alasan');

        $hasil = [];

        foreach ($target as $kode => $kg) {
            $kategori = KategoriStok::from($kode);

            try {
                $stok->opname($kategori, $kg, $alasan, $tanggal, $pelaku);
                $hasil[] = [$kategori->label(), number_format($kg, 2, ',', '.').' kg', 'tercatat'];
            } catch (BusinessRuleException $e) {
                // Paling sering: saldo sistem sudah sama dengan angka yang diisi.
                $hasil[] = [$kategori->label(), number_format($kg, 2, ',', '.').' kg', $e->getMessage()];
            }
        }

        $this->newLine();
        $this->table(['Kategori', 'Stok fisik', 'Hasil'], $hasil);

        $this->newLine();
        $this->info('Saldo stok sekarang:');
        $this->table(
            ['Kategori', 'Saldo (kg)'],
            array_map(
                static fn (KategoriStok $k): array => [$k->label(), number_format(app(StokService::class)->saldo($k), 2, ',', '.')],
                KategoriStok::cases(),
            ),
        );

        return self::SUCCESS;
    }

    /** @return array<string, float> */
    private function uraiOpsi(string $mentah): array
    {
        $hasil = [];

        foreach (explode(',', $mentah) as $bagian) {
            if (! str_contains($bagian, '=')) {
                $this->error("Format salah: \"{$bagian}\". Contoh yang benar: ns1=22193");

                continue;
            }

            [$kode, $kg] = array_map('trim', explode('=', $bagian, 2));
            $kategori = KategoriStok::tryFromAny($kode);

            if ($kategori === null) {
                $this->error("Kategori tidak dikenal: \"{$kode}\". Pilihan: ".implode(', ', KategoriStok::values()));

                continue;
            }

            if (! is_numeric($kg) || (float) $kg < 0) {
                $this->error("Kg tidak valid untuk {$kode}: \"{$kg}\"");

                continue;
            }

            $hasil[$kategori->value] = (float) $kg;
        }

        return $hasil;
    }

    /** @return array<string, float> */
    private function tanyaSatuPerSatu(): array
    {
        $this->info('Isi stok fisik per kategori. Kosongkan (Enter) untuk melewati.');
        $hasil = [];

        foreach (KategoriStok::cases() as $kategori) {
            $jawab = trim((string) $this->ask($kategori->label().' (kg)', ''));

            if ($jawab === '') {
                continue;
            }

            if (! is_numeric($jawab) || (float) $jawab < 0) {
                $this->error('Diabaikan: bukan angka yang valid.');

                continue;
            }

            $hasil[$kategori->value] = (float) $jawab;
        }

        return $hasil;
    }
}
