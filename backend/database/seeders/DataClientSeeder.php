<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Karyawan;
use App\Models\Pengepul;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Data operasional asli PT Nira Sari Murni (bukan data demo).
 *
 * Sengaja dipisah dari MasterSeeder — MasterSeeder mengisi contoh untuk
 * pengembangan, seeder ini mengisi daftar sungguhan yang dikirim client:
 * 195 petani Desa Batuanten, 5 pengepul, dan 33 karyawan pemasak.
 *
 * Idempoten: menjalankan ulang memperbarui data yang cocok, tidak menggandakan.
 * Aman dijalankan di production:
 *
 *   php artisan db:seed --class=DataClientSeeder --force
 */
class DataClientSeeder extends Seeder
{
    /** Daftar per 25 Agustus 2026. */
    private const PENGEPUL = ['Setyono', 'Abrori', 'H. Satim', 'Sikun', 'Tasdik'];

    private const KARYAWAN = [
        'Ozy', 'Yani', 'Roping', 'Narko', 'Mirah', 'Situr', 'Kartum', 'Tus',
        'Gores', 'Saroh', 'Tarwi Tua', 'Laela', 'Sirin', 'Tri', 'Soimun',
        'Suryani', 'Saliah', 'Sartinah', 'Kamil', 'Depok', 'Tariah',
        'Tarwi Muda', 'Rikoh', 'Kamilah', 'Rosid', 'Kawen', 'Rikun',
        'Sudirah', 'Darsini', 'Das', 'Dakem', 'As', 'Daryati',
    ];

    public function run(): void
    {
        $this->seedPengepul();
        $this->seedKaryawan();
        $this->seedPetani();
    }

    private function seedPengepul(): void
    {
        foreach (self::PENGEPUL as $nama) {
            Pengepul::query()->firstOrCreate(['nama' => $nama], ['aktif' => true]);
        }

        $this->command?->info(sprintf('Pengepul: %d terdaftar.', Pengepul::query()->count()));
    }

    private function seedKaryawan(): void
    {
        foreach (self::KARYAWAN as $nama) {
            Karyawan::query()->firstOrCreate(['nama' => $nama], ['aktif' => true]);
        }

        $this->command?->info(sprintf('Karyawan: %d terdaftar.', Karyawan::query()->count()));
    }

    /**
     * Petani diimpor lewat command supaya aturan penguraiannya (header bebas
     * urutan, status kombinasi "PMS + PLMR", pencocokan lewat kode lahan)
     * hanya ada di satu tempat.
     */
    private function seedPetani(): void
    {
        $file = database_path('data/petani-batuanten.csv');

        if (! is_readable($file)) {
            $this->command?->warn("CSV petani tidak ditemukan: {$file} — dilewati.");

            return;
        }

        Artisan::call('sigula:impor-petani', ['file' => $file], $this->command?->getOutput());
    }
}
