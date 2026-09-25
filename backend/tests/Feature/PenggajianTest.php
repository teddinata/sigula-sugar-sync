<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Grade;
use App\Enums\JenisTarif;
use App\Enums\KategoriStok;
use App\Enums\Role;
use App\Models\GajiMingguan;
use App\Models\Karyawan;
use App\Models\TarifUpah;
use App\Services\ProduksiService;
use Tests\TestCase;

class PenggajianTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMaster();
        // Kamis 13 Agustus 2026 — periode gaji berjalan: Senin 10 s.d. Minggu 16.
        $this->travelTo('2026-08-13 09:00:00');
    }

    /**
     * Contoh dari client: Asep ikut 3 sesi tungku pada HARI YANG SAMA.
     * Kg-nya terakumulasi, tapi hari kerjanya tetap 1 (bukan 3).
     */
    public function test_tiga_sesi_dalam_satu_hari_tetap_dihitung_satu_hari_kerja(): void
    {
        $this->masukSebagai(Role::OWNER);
        $asep = $this->karyawan('Asep Saepudin');
        $pardi = $this->karyawan('Pardi');

        for ($i = 0; $i < 3; $i++) {
            $this->sesiSelesai($asep, $pardi, '2026-08-11', 100, 80, 20);
        }

        $baris = $this->barisGaji($asep);

        $this->assertEquals(1, $baris['hariKerja']);
        // 3 sesi × (80 ÷ 2) kristal dan (20 ÷ 2) brondol
        $this->assertEqualsWithDelta(120.0, $baris['kgKristal'], 0.001);
        $this->assertEqualsWithDelta(30.0, $baris['kgBrondol'], 0.001);
        // upah = 120 × 1.150 + 30 × 800 + 1 hari × 5.000
        $this->assertEqualsWithDelta(138_000.0, $baris['upahKristal'], 0.01);
        $this->assertEqualsWithDelta(24_000.0, $baris['upahBrondol'], 0.01);
        $this->assertEqualsWithDelta(5_000.0, $baris['uangMakan'], 0.01);
        $this->assertEqualsWithDelta(167_000.0, $baris['total'], 0.01);
    }

    public function test_hari_kerja_dihitung_per_tanggal_berbeda(): void
    {
        $this->masukSebagai();
        $asep = $this->karyawan('Asep');
        $pardi = $this->karyawan('Pardi');

        $this->sesiSelesai($asep, $pardi, '2026-08-10', 100, 80, 20);
        $this->sesiSelesai($asep, $pardi, '2026-08-11', 100, 80, 20);
        $this->sesiSelesai($asep, $pardi, '2026-08-12', 100, 80, 20);

        $baris = $this->barisGaji($asep);

        $this->assertEquals(3, $baris['hariKerja']);
        $this->assertEqualsWithDelta(15_000.0, $baris['uangMakan'], 0.01);
    }

    /** Periode gaji Senin-Minggu: kerja Sabtu & Minggu ikut terhitung. */
    public function test_periode_gaji_senin_sampai_minggu(): void
    {
        $this->masukSebagai();
        $asep = $this->karyawan('Asep');
        $pardi = $this->karyawan('Pardi');

        $this->sesiSelesai($asep, $pardi, '2026-08-14', 100, 80, 20); // Jumat
        $this->sesiSelesai($asep, $pardi, '2026-08-15', 100, 80, 20); // Sabtu
        $this->sesiSelesai($asep, $pardi, '2026-08-16', 100, 80, 20); // Minggu
        $this->sesiSelesai($asep, $pardi, '2026-08-17', 100, 80, 20); // Senin depan — periode lain

        $response = $this->getJson('/api/v1/penggajian?tanggal=2026-08-13')->assertOk();

        $this->assertSame('2026-08-10', $response->json('data.periode.senin'));
        $this->assertSame('2026-08-16', $response->json('data.periode.minggu'));

        $baris = collect($response->json('data.baris'))->firstWhere('karyawanId', (string) $asep->id);
        // Jumat + Sabtu + Minggu = 3 sesi x (80 / 2)
        $this->assertEqualsWithDelta(120.0, $baris['kgKristal'], 0.001);
        $this->assertEquals(3, $baris['hariKerja']);
    }

    /** Hari Minggu masuk periode yang dimulai Senin sebelumnya, bukan sesudahnya. */
    public function test_membuka_periode_dari_hari_minggu_tetap_periode_yang_sama(): void
    {
        $this->masukSebagai();

        $this->getJson('/api/v1/penggajian?tanggal=2026-08-16')
            ->assertOk()
            ->assertJsonPath('data.periode.senin', '2026-08-10')
            ->assertJsonPath('data.periode.minggu', '2026-08-16');
    }

    /**
     * Hari bayar tidak tetap: dibayar Jumat, karyawan masih memasak Sabtu.
     * Kekurangannya harus bisa dibayar susulan, bukan hilang.
     */
    public function test_kerja_setelah_gaji_dibayar_muncul_sebagai_kekurangan_dan_bisa_dibayar(): void
    {
        $this->masukSebagai();
        $asep = $this->karyawan('Asep');
        $pardi = $this->karyawan('Pardi');

        // Jumat: 40 kg kristal + 10 kg brondol per orang
        // = 40 x 1.150 + 10 x 800 + 5.000 = 59.000
        $this->sesiSelesai($asep, $pardi, '2026-08-14', 100, 80, 20);
        $this->postJson("/api/v1/penggajian/{$asep->id}/bayar", ['tanggal' => '2026-08-14'])->assertOk();

        // Sabtu, setelah gaji dibayar: tambah 59.000 lagi.
        $this->sesiSelesai($asep, $pardi, '2026-08-15', 100, 80, 20);

        $baris = $this->barisGaji($asep);
        $this->assertFalse($baris['dibayar'], 'Ada kekurangan, jadi belum lunas.');
        $this->assertTrue($baris['adaPerubahanSetelahDibayar']);
        $this->assertEqualsWithDelta(118_000.0, $baris['total'], 0.01);
        $this->assertEqualsWithDelta(59_000.0, $baris['sudahDibayarkan'], 0.01);
        $this->assertEqualsWithDelta(59_000.0, $baris['kurangBayar'], 0.01);
        $this->assertEquals(2, $baris['hariKerja']);

        $ringkasan = $this->getJson('/api/v1/penggajian?tanggal=2026-08-13')->json('data.ringkasan');
        $this->assertEqualsWithDelta(59_000.0, $ringkasan['belumDibayar'] - $this->belumDibayarSelain($asep), 0.01);

        // Bayar kekurangan.
        $this->postJson("/api/v1/penggajian/{$asep->id}/bayar", ['tanggal' => '2026-08-15'])->assertOk();

        $baris = $this->barisGaji($asep);
        $this->assertTrue($baris['dibayar']);
        $this->assertEqualsWithDelta(0.0, $baris['kurangBayar'], 0.01);
        $this->assertEqualsWithDelta(118_000.0, $baris['sudahDibayarkan'], 0.01);

        $gaji = GajiMingguan::query()->where('karyawan_id', $asep->id)->sole();
        $this->assertEqualsWithDelta(118_000.0, (float) $gaji->total, 0.01);
        $this->assertSame(2, (int) $gaji->hari_kerja);
        $this->assertSame('2026-08-16', $gaji->periode_minggu->toDateString());

        // Jejak audit mencatat nominal susulannya saja.
        $log = \App\Models\AuditLog::query()->where('aksi', 'gaji.bayar_susulan')->sole();
        $this->assertEqualsWithDelta(59_000.0, $log->data['dibayarkan_kali_ini'], 0.01);
    }

    public function test_bayar_ulang_tanpa_kekurangan_tidak_mengubah_apa_pun(): void
    {
        $this->masukSebagai();
        $asep = $this->karyawan('Asep');
        $pardi = $this->karyawan('Pardi');
        $this->sesiSelesai($asep, $pardi, '2026-08-14', 100, 80, 20);

        $this->postJson("/api/v1/penggajian/{$asep->id}/bayar", ['tanggal' => '2026-08-14'])->assertOk();
        $this->postJson("/api/v1/penggajian/{$asep->id}/bayar", ['tanggal' => '2026-08-14'])->assertOk();

        $this->assertSame(1, GajiMingguan::query()->count());
        $this->assertSame(0, \App\Models\AuditLog::query()->where('aksi', 'gaji.bayar_susulan')->count());
    }

    public function test_bayar_semua_ikut_melunasi_kekurangan(): void
    {
        $this->masukSebagai();
        $asep = $this->karyawan('Asep');
        $pardi = $this->karyawan('Pardi');

        $this->sesiSelesai($asep, $pardi, '2026-08-14', 100, 80, 20);
        $this->postJson('/api/v1/penggajian/bayar-semua', ['tanggal' => '2026-08-14'])->assertOk();

        $this->sesiSelesai($asep, $pardi, '2026-08-16', 100, 80, 20); // Minggu
        $this->postJson('/api/v1/penggajian/bayar-semua', ['tanggal' => '2026-08-16'])->assertOk();

        $ringkasan = $this->getJson('/api/v1/penggajian?tanggal=2026-08-16')->json('data.ringkasan');
        $this->assertEqualsWithDelta(0.0, $ringkasan['belumDibayar'], 0.01);
        $this->assertEqualsWithDelta($ringkasan['totalGaji'], $ringkasan['sudahDibayar'], 0.01);
    }

    /** Tarif yang dipakai adalah tarif pada tanggal produksi, bukan tarif terbaru. */
    public function test_memakai_tarif_yang_berlaku_pada_tanggal_produksi(): void
    {
        $this->masukSebagai();
        $asep = $this->karyawan('Asep');
        $pardi = $this->karyawan('Pardi');

        $this->sesiSelesai($asep, $pardi, '2026-08-10', 100, 80, 20);

        // Tarif naik SETELAH produksi terjadi.
        TarifUpah::create([
            'jenis' => JenisTarif::KRISTAL->value,
            'nilai' => 2000,
            'berlaku_dari' => '2026-08-12 00:00:00',
        ]);

        $baris = $this->barisGaji($asep);

        // Tetap memakai tarif lama 1.150 untuk produksi tanggal 10.
        $this->assertEqualsWithDelta(46_000.0, $baris['upahKristal'], 0.01);
    }

    public function test_kenaikan_tarif_di_tengah_minggu_hanya_berlaku_untuk_hari_setelahnya(): void
    {
        $this->masukSebagai();
        $asep = $this->karyawan('Asep');
        $pardi = $this->karyawan('Pardi');

        $this->sesiSelesai($asep, $pardi, '2026-08-10', 100, 80, 20);

        TarifUpah::create([
            'jenis' => JenisTarif::KRISTAL->value,
            'nilai' => 1500,
            'berlaku_dari' => '2026-08-12 00:00:00',
        ]);

        $this->sesiSelesai($asep, $pardi, '2026-08-12', 100, 80, 20);

        $baris = $this->barisGaji($asep);

        // 40 kg × 1.150 (tgl 10) + 40 kg × 1.500 (tgl 12)
        $this->assertEqualsWithDelta(46_000.0 + 60_000.0, $baris['upahKristal'], 0.01);
    }

    public function test_bayar_gaji_membekukan_angka_dan_mengubah_status(): void
    {
        $this->masukSebagai(Role::OWNER);
        $asep = $this->karyawan('Asep');
        $pardi = $this->karyawan('Pardi');
        $this->sesiSelesai($asep, $pardi, '2026-08-11', 100, 80, 20);

        $this->postJson("/api/v1/penggajian/{$asep->id}/bayar", ['tanggal' => '2026-08-13'])
            ->assertOk()
            ->assertJsonPath('data.status', 'Sudah Dibayar')
            ->assertJsonPath('data.periodeSenin', '2026-08-10')
            ->assertJsonPath('data.periodeMinggu', '2026-08-16');

        // 40 kg × 1.150 + 10 kg × 800 + 1 hari × 5.000
        $snapshot = GajiMingguan::query()->where('karyawan_id', $asep->id)->firstOrFail();
        $this->assertEqualsWithDelta(59_000.0, (float) $snapshot->total, 0.01);
        $this->assertNotNull($snapshot->dibayar_pada);

        $baris = $this->barisGaji($asep);
        $this->assertTrue($baris['dibayar']);
    }

    public function test_bayar_ulang_bersifat_idempoten(): void
    {
        $this->masukSebagai();
        $asep = $this->karyawan('Asep');
        $pardi = $this->karyawan('Pardi');
        $this->sesiSelesai($asep, $pardi, '2026-08-11', 100, 80, 20);

        $this->postJson("/api/v1/penggajian/{$asep->id}/bayar", ['tanggal' => '2026-08-13'])->assertOk();
        $this->postJson("/api/v1/penggajian/{$asep->id}/bayar", ['tanggal' => '2026-08-13'])->assertOk();

        $this->assertSame(1, GajiMingguan::query()->where('karyawan_id', $asep->id)->count());
    }

    public function test_bayar_semua_menandai_seluruh_karyawan_periode_itu(): void
    {
        $this->masukSebagai();
        $asep = $this->karyawan('Asep');
        $pardi = $this->karyawan('Pardi');
        $this->sesiSelesai($asep, $pardi, '2026-08-11', 100, 80, 20);

        $this->postJson('/api/v1/penggajian/bayar-semua', ['tanggal' => '2026-08-13'])
            ->assertOk()
            ->assertJsonPath('data.jumlahKaryawan', 2);

        $this->assertSame(2, GajiMingguan::query()->count());
    }

    public function test_slip_gaji_berisi_rincian_lengkap(): void
    {
        $this->masukSebagai();
        $asep = $this->karyawan('Asep');
        $pardi = $this->karyawan('Pardi');
        $this->sesiSelesai($asep, $pardi, '2026-08-11', 100, 80, 20);

        $this->getJson("/api/v1/penggajian/slip/{$asep->id}?tanggal=2026-08-13")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'periode' => ['senin', 'minggu', 'label'],
                    'tarif' => ['kristal', 'brondol', 'uangMakan'],
                    'baris' => [
                        'karyawanId', 'nama', 'kgKristal', 'kgBrondol', 'hariKerja',
                        'upahKristal', 'upahBrondol', 'uangMakan', 'total', 'dibayar',
                    ],
                ],
            ]);
    }

    public function test_sesi_tidak_bisa_dibatalkan_setelah_gajinya_dibayar(): void
    {
        $this->masukSebagai();
        $asep = $this->karyawan('Asep');
        $pardi = $this->karyawan('Pardi');
        $sesi = $this->sesiSelesai($asep, $pardi, '2026-08-11', 100, 80, 20);

        $this->postJson('/api/v1/penggajian/bayar-semua', ['tanggal' => '2026-08-13'])->assertOk();

        $this->deleteJson("/api/v1/produksi/sesi/{$sesi}")->assertStatus(422);
    }

    /** @return int id sesi tungku */
    private function sesiSelesai(
        Karyawan $k1,
        Karyawan $k2,
        string $tanggal,
        float $kgBahan,
        float $kgKristal,
        float $kgBrondol,
    ): int {
        $this->tambahStok(KategoriStok::NS1, $kgBahan, $tanggal);

        $produksi = app(ProduksiService::class);

        $sesi = $produksi->mulai([
            'tanggal' => $tanggal,
            'grade' => Grade::NS1,
            'kg_bahan_mentah' => $kgBahan,
            'karyawan_1_id' => $k1->id,
            'karyawan_2_id' => $k2->id,
        ]);

        $produksi->selesaikan($sesi, $kgKristal, $kgBrondol);

        return (int) $sesi->id;
    }

    /** @return array<string, mixed> */
    private function barisGaji(Karyawan $karyawan, string $tanggal = '2026-08-13'): array
    {
        $baris = collect($this->getJson('/api/v1/penggajian?tanggal='.$tanggal)->assertOk()->json('data.baris'))
            ->firstWhere('karyawanId', (string) $karyawan->id);

        $this->assertNotNull($baris, 'Baris gaji karyawan tidak ditemukan.');

        return $baris;
    }

    private function belumDibayarSelain(Karyawan $karyawan): float
    {
        return (float) collect($this->getJson('/api/v1/penggajian?tanggal=2026-08-13')->json('data.baris'))
            ->reject(fn (array $b): bool => $b['karyawanId'] === (string) $karyawan->id)
            ->sum(fn (array $b): float => (float) $b['kurangBayar']);
    }
}
