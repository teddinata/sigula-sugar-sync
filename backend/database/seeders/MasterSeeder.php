<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Grade;
use App\Enums\JenisTarif;
use App\Models\Eksportir;
use App\Models\GradeHarga;
use App\Models\Karyawan;
use App\Models\Petani;
use App\Models\TarifUpah;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/** Master data awal: harga per grade (beserta histori), tarif, petani, karyawan, eksportir. */
class MasterSeeder extends Seeder
{
    private const NAMA_PETANI = [
        'Sukirman', 'Haji Wardi', 'Rohmat Hidayat', 'Kastam', 'Bu Yatmi',
        'Darsono', 'Slamet Riyadi', 'Tarjo', 'Nur Kholis', 'Mbah Sanip',
        'Warsito', 'Umi Kulsum', 'Basuki', 'Rasman', 'Karyadi',
    ];

    private const NAMA_KARYAWAN = [
        'Asep Saepudin', 'Pardi', 'Joko Susilo', 'Bambang Setiawan', 'Eko Prasetyo',
        'Rudi Hartono', 'Agus Salim', 'Wahyu Nugroho', 'Sugeng Riyadi', 'Iwan Setiawan',
        'Dedi Kurniawan', 'Sarif Hidayat', 'Tono Martono', 'Yanto', 'Maman Suryana',
        'Rahmat Fauzi', 'Hendra Gunawan', 'Ujang Solihin', 'Cecep Firmansyah', 'Andri Wibowo',
        'Nanang Sutrisno', 'Gunawan', 'Trisno Widodo', 'Fajar Ramadhan', 'Imam Syafi\'i',
        'Kurnia Sandi', 'Bayu Anggara', 'Rizal Efendi',
    ];

    private const ALAMAT = [
        'Desa Sukamaju, Kec. Cikoneng',
        'Desa Cibungur, Kec. Pagaden',
        'Desa Karanganyar, Kec. Wanareja',
        'Desa Mekarsari, Kec. Cimalaka',
        'Desa Tegalsari, Kec. Bumiayu',
    ];

    /** Harga berjalan per grade. */
    public const HARGA_AWAL = [
        'ns1' => 14500,
        'ns2' => 12750,
        'kecap' => 9500,
    ];

    public function run(): void
    {
        mt_srand(20260813);

        $this->seedHarga();
        $this->seedTarif();
        $this->seedPetani();
        $this->seedKaryawan();
        $this->seedEksportir();
    }

    /**
     * Harga dibuat sebagai rangkaian histori (2 kali kenaikan) supaya alur
     * "harga lama vs harga baru" di Master Harga langsung terlihat.
     */
    private function seedHarga(): void
    {
        if (GradeHarga::query()->exists()) {
            return;
        }

        foreach (Grade::cases() as $index => $grade) {
            $harga = self::HARGA_AWAL[$grade->value] - 1200;

            GradeHarga::create([
                'grade' => $grade->value,
                'harga_per_kg' => $harga,
                'berlaku_dari' => Carbon::today()->subDays(210)->startOfDay(),
                'catatan' => 'Harga awal sistem',
            ]);

            for ($langkah = 0; $langkah < 2; $langkah++) {
                $harga += 600;

                GradeHarga::create([
                    'grade' => $grade->value,
                    'harga_per_kg' => $harga,
                    'berlaku_dari' => Carbon::today()->subDays(150 - $langkah * 60 - $index * 4)->startOfDay(),
                    'catatan' => 'Penyesuaian harga pasar',
                ]);
            }
        }
    }

    private function seedTarif(): void
    {
        if (TarifUpah::query()->exists()) {
            return;
        }

        foreach (JenisTarif::cases() as $jenis) {
            TarifUpah::create([
                'jenis' => $jenis->value,
                'nilai' => $jenis->nilaiDefault(),
                'berlaku_dari' => Carbon::today()->subDays(210)->startOfDay(),
                'catatan' => 'Tarif awal sistem',
            ]);
        }
    }

    private function seedPetani(): void
    {
        if (Petani::query()->exists()) {
            return;
        }

        foreach (self::NAMA_PETANI as $i => $nama) {
            // Petani contoh: sebagian tanpa kode lahan supaya kasus Non-Member ikut terwakili.
            $member = $i % 3 !== 2;

            Petani::create([
                'nama' => $nama,
                'kode_lahan' => $member ? sprintf('DEV-%03d', $i + 1) : null,
                'rt_rw' => sprintf('%02d/%02d', ($i % 7) + 1, ($i % 3) + 1),
                'kontak' => sprintf('08%d-%d-%d', mt_rand(11, 89), mt_rand(1000, 9999), mt_rand(1000, 9999)),
                'alamat' => self::ALAMAT[$i % count(self::ALAMAT)],
                'aktif' => true,
            ]);
        }
    }

    private function seedKaryawan(): void
    {
        if (Karyawan::query()->exists()) {
            return;
        }

        foreach (self::NAMA_KARYAWAN as $nama) {
            Karyawan::create([
                'nama' => $nama,
                'kontak' => sprintf('08%d-%d-%d', mt_rand(11, 89), mt_rand(1000, 9999), mt_rand(1000, 9999)),
                'aktif' => true,
            ]);
        }
    }

    private function seedEksportir(): void
    {
        if (Eksportir::query()->exists()) {
            return;
        }

        $daftar = [
            ['nama' => 'PT Global Sweet Export', 'kontak' => '021-5567890'],
            ['nama' => 'CV Nusantara Sugar Trade', 'kontak' => '0812-3344-5566'],
            ['nama' => 'PT Anugerah Manis Internasional', 'kontak' => '031-8891234'],
            ['nama' => 'PT Java Palm Commodity', 'kontak' => '0857-1122-3344'],
        ];

        foreach ($daftar as $data) {
            Eksportir::create([...$data, 'aktif' => true]);
        }
    }
}
