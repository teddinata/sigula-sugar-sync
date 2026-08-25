<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Petani;
use Tests\TestCase;

/** Poin 7 revisi client: memasukkan data petani dari file CSV yang mereka kirim. */
class ImporPetaniTest extends TestCase
{
    private function csv(string $isi): string
    {
        $file = tempnam(sys_get_temp_dir(), 'petani').'.csv';
        file_put_contents($file, $isi);

        return $file;
    }

    public function test_mengimpor_petani_beserta_status_kombinasi(): void
    {
        $file = $this->csv(<<<'CSV'
        Nama Petani;Kode Lahan;RT/RW;Status;Kontak
        Sukirman;BTN-001;02/05;PMS + PLMR;081234567890
        Warsono;BTN-002;03/01;PLMD;
        CSV);

        $this->artisan('sigula:impor-petani', ['file' => $file])->assertSuccessful();

        $sukirman = Petani::query()->where('kode_lahan', 'BTN-001')->firstOrFail();
        $this->assertSame('Sukirman', $sukirman->nama);
        $this->assertSame('02/05', $sukirman->rt_rw);
        $this->assertSame('081234567890', $sukirman->kontak);
        $this->assertEqualsCanonicalizing(
            ['pms', 'plmr'],
            $sukirman->statusPenderes()->pluck('kode')->map(fn ($s) => $s->value)->all(),
        );

        $warsono = Petani::query()->where('kode_lahan', 'BTN-002')->firstOrFail();
        $this->assertSame(['plmd'], $warsono->statusPenderes()->pluck('kode')->map(fn ($s) => $s->value)->all());

        unlink($file);
    }

    public function test_menjalankan_ulang_memperbarui_bukan_menggandakan(): void
    {
        $awal = $this->csv("Nama;Kode Lahan;Status\nSukirman;BTN-001;PMS\n");
        $this->artisan('sigula:impor-petani', ['file' => $awal])->assertSuccessful();

        $revisi = $this->csv("Nama;Kode Lahan;Status\nSukirman Hadi;BTN-001;PLS\n");
        $this->artisan('sigula:impor-petani', ['file' => $revisi])->assertSuccessful();

        $this->assertSame(1, Petani::query()->count());

        $petani = Petani::query()->firstOrFail();
        $this->assertSame('Sukirman Hadi', $petani->nama);
        // Status lama diganti, bukan ditumpuk.
        $this->assertSame(['pls'], $petani->statusPenderes()->pluck('kode')->map(fn ($s) => $s->value)->all());

        unlink($awal);
        unlink($revisi);
    }

    public function test_pemisah_koma_dan_urutan_kolom_bebas_ikut_terbaca(): void
    {
        $file = $this->csv("Status,RT/RW,Nama Petani,Kode Lahan\nPM,01/02,Darto,BTN-009\n");

        $this->artisan('sigula:impor-petani', ['file' => $file])->assertSuccessful();

        $petani = Petani::query()->where('nama', 'Darto')->firstOrFail();
        $this->assertSame('BTN-009', $petani->kode_lahan);
        $this->assertSame('01/02', $petani->rt_rw);

        unlink($file);
    }

    public function test_uji_coba_tidak_menyimpan_apa_pun(): void
    {
        $file = $this->csv("Nama;Kode Lahan;Status\nSukirman;BTN-001;PMS\n");

        $this->artisan('sigula:impor-petani', ['file' => $file, '--uji-coba' => true])
            ->assertSuccessful();

        $this->assertSame(0, Petani::query()->count());

        unlink($file);
    }

    public function test_baris_tanpa_nama_dilewati_tanpa_menggagalkan_impor(): void
    {
        $file = $this->csv("Nama;Kode Lahan;Status\n;BTN-777;PMS\nDarto;BTN-778;PM\n");

        $this->artisan('sigula:impor-petani', ['file' => $file])->assertSuccessful();

        $this->assertSame(1, Petani::query()->count());
        $this->assertSame('Darto', Petani::query()->firstOrFail()->nama);

        unlink($file);
    }

    public function test_file_tidak_ada_ditolak(): void
    {
        $this->artisan('sigula:impor-petani', ['file' => '/tidak/ada.csv'])->assertFailed();
    }

    public function test_header_tanpa_kolom_nama_ditolak(): void
    {
        $file = $this->csv("Kode Lahan;Status\nBTN-001;PMS\n");

        $this->artisan('sigula:impor-petani', ['file' => $file])->assertFailed();
        $this->assertSame(0, Petani::query()->count());

        unlink($file);
    }
}
