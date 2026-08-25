<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StatusPenderes;
use App\Models\Karyawan;
use App\Models\Pengepul;
use App\Models\Petani;
use Database\Seeders\DataClientSeeder;
use Tests\TestCase;

/** Poin 7 revisi client: data asli petani, pengepul, dan karyawan pemasak. */
class DataClientSeederTest extends TestCase
{
    public function test_mengisi_petani_pengepul_dan_karyawan_dari_data_client(): void
    {
        $this->seed(DataClientSeeder::class);

        $this->assertSame(195, Petani::query()->count());
        $this->assertSame(5, Pengepul::query()->count());
        $this->assertSame(33, Karyawan::query()->count());
    }

    public function test_status_kombinasi_terurai_jadi_beberapa_baris(): void
    {
        $this->seed(DataClientSeeder::class);

        // Baris CSV: 13,SAMHUDI SLAMET,04/01,BA-028,PMS + PLMR
        $petani = Petani::query()->where('kode_lahan', 'BA-028')->firstOrFail();

        $this->assertSame('SAMHUDI SLAMET', $petani->nama);
        $this->assertSame('04/01', $petani->rt_rw);
        $this->assertEqualsCanonicalizing(
            [StatusPenderes::PMS, StatusPenderes::PLMR],
            $petani->daftarStatusPenderes(),
        );
    }

    public function test_baris_tanpa_nomor_urut_tetap_ikut_terimpor(): void
    {
        $this->seed(DataClientSeeder::class);

        // Baris CSV tanpa kolom no_urut: ,KUSNI,02/01,BA-009,PM
        $petani = Petani::query()->where('kode_lahan', 'BA-009')->firstOrFail();

        $this->assertSame('KUSNI', $petani->nama);
        $this->assertSame([StatusPenderes::PM], $petani->daftarStatusPenderes());
    }

    /** Nama petani berulang (mis. SARNO 4x) dibedakan oleh kode lahannya. */
    public function test_nama_kembar_tidak_saling_menimpa(): void
    {
        $this->seed(DataClientSeeder::class);

        $sarno = Petani::query()->where('nama', 'SARNO')->pluck('kode_lahan')->all();

        $this->assertCount(4, $sarno);
        $this->assertEqualsCanonicalizing(['BA-055', 'BA-209', 'BA-392', 'BA-471'], $sarno);
    }

    public function test_menjalankan_ulang_tidak_menggandakan_data(): void
    {
        $this->seed(DataClientSeeder::class);
        $this->seed(DataClientSeeder::class);

        $this->assertSame(195, Petani::query()->count());
        $this->assertSame(5, Pengepul::query()->count());
        $this->assertSame(33, Karyawan::query()->count());

        // Status juga tidak menumpuk.
        $petani = Petani::query()->where('kode_lahan', 'BA-028')->firstOrFail();
        $this->assertCount(2, $petani->statusPenderes()->get());
    }

    public function test_seluruh_petani_punya_kode_lahan_dan_minimal_satu_status(): void
    {
        $this->seed(DataClientSeeder::class);

        $this->assertSame(0, Petani::query()->whereNull('kode_lahan')->count());
        $this->assertSame(0, Petani::query()->doesntHave('statusPenderes')->count());
    }

    /** Kode lahan = nomor member, jadi seluruh petani CSV berstatus Member. */
    public function test_seluruh_petani_csv_berstatus_member(): void
    {
        $this->seed(DataClientSeeder::class);

        $petani = Petani::query()->where('kode_lahan', 'BA-002')->firstOrFail();

        $this->assertTrue($petani->isMember());
        $this->assertSame('BA-002', $petani->kode_lahan);
    }

    /** DASIRIN ditandai merah di dokumen client: dinonaktifkan, bukan dihapus. */
    public function test_dasirin_masuk_sebagai_petani_nonaktif(): void
    {
        $this->seed(DataClientSeeder::class);

        $dasirin = Petani::query()->where('kode_lahan', 'BA-015')->firstOrFail();

        $this->assertSame('DASIRIN', $dasirin->nama);
        $this->assertFalse($dasirin->aktif);

        // Tetap 195 baris; yang nonaktif hanya disembunyikan dari daftar default.
        $this->assertSame(195, Petani::query()->count());
        $this->assertSame(194, Petani::query()->aktif()->count());
    }
}
