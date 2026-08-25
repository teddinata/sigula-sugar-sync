<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Grade;
use App\Enums\KategoriStok;
use App\Models\GajiMingguan;
use App\Models\Karyawan;
use App\Models\Pembelian;
use App\Services\ProduksiService;
use Tests\TestCase;

/**
 * Poin 10 revisi client: nominal yang dibayarkan dibulatkan ke kelipatan 500,
 * tapi angka hasil hitungan asli tetap disimpan untuk rekonsiliasi.
 */
class PembulatanTransaksiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMaster();
        // Kamis 13 Agustus 2026 — periode gaji Senin 10 s.d. Jumat 14.
        $this->travelTo('2026-08-13 09:00:00');
    }

    public function test_total_pembelian_dibulatkan_naik_ke_lima_ratus(): void
    {
        $this->masukSebagai();

        // 10,3 kg x 14.500 = 149.350 -> sisa 350 (<= 500) naik ke 149.500
        $data = $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-13',
            'petaniId' => $this->petani()->id,
            'grade' => 'NS 1',
            'kg' => 10.3,
        ])->assertCreated()->json('data');

        $this->assertEqualsWithDelta(149_500.0, $data['total'], 0.01);
        $this->assertEqualsWithDelta(149_350.0, $data['totalSebelumBulat'], 0.01);

        $tersimpan = Pembelian::findOrFail($data['id']);
        $this->assertEqualsWithDelta(149_500.0, (float) $tersimpan->total, 0.01);
        $this->assertEqualsWithDelta(149_350.0, (float) $tersimpan->total_sebelum_bulat, 0.01);
    }

    public function test_sisa_di_atas_lima_ratus_naik_ke_ribuan_berikutnya(): void
    {
        $this->masukSebagai();

        // 2,05 kg x 14.500 = 29.725 -> sisa 725 (> 500) naik ke 30.000
        $data = $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-13',
            'petaniId' => $this->petani()->id,
            'grade' => 'NS 1',
            'kg' => 2.05,
        ])->assertCreated()->json('data');

        $this->assertEqualsWithDelta(30_000.0, $data['total'], 0.01);
        $this->assertEqualsWithDelta(29_725.0, $data['totalSebelumBulat'], 0.01);
    }

    public function test_nominal_yang_sudah_bulat_tidak_berubah(): void
    {
        $this->masukSebagai();

        // 100 kg x 14.500 = 1.450.000 (sudah kelipatan 1.000)
        $data = $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-13',
            'petaniId' => $this->petani()->id,
            'grade' => 'NS 1',
            'kg' => 100,
        ])->assertCreated()->json('data');

        $this->assertEqualsWithDelta(1_450_000.0, $data['total'], 0.01);
        $this->assertEqualsWithDelta(1_450_000.0, $data['totalSebelumBulat'], 0.01);
    }

    public function test_gaji_mingguan_dibulatkan_dan_menyimpan_nilai_aslinya(): void
    {
        $this->masukSebagai();
        $asep = $this->karyawan('Asep');

        // Sendirian: 10,3 kg kristal x 1.150 = 11.845 + uang makan 5.000 = 16.845
        // -> sisa 845 (> 500) naik ke 17.000
        $this->sesiSendirian($asep, '2026-08-11', 20, 10.3, 0);

        $baris = collect($this->getJson('/api/v1/penggajian?tanggal=2026-08-13')->assertOk()->json('data.baris'))
            ->firstWhere('karyawanId', (string) $asep->id);

        $this->assertEqualsWithDelta(16_845.0, $baris['totalSebelumBulat'], 0.01);
        $this->assertEqualsWithDelta(17_000.0, $baris['total'], 0.01);

        $this->postJson("/api/v1/penggajian/{$asep->id}/bayar", ['tanggal' => '2026-08-13'])->assertOk();

        $tersimpan = GajiMingguan::query()->where('karyawan_id', $asep->id)->firstOrFail();
        $this->assertEqualsWithDelta(17_000.0, (float) $tersimpan->total, 0.01);
        $this->assertEqualsWithDelta(16_845.0, (float) $tersimpan->total_sebelum_bulat, 0.01);
    }

    /** Setelah dibayar, angka yang ditampilkan harus sama persis dengan snapshot. */
    public function test_snapshot_gaji_terbayar_tetap_membawa_nilai_sebelum_bulat(): void
    {
        $this->masukSebagai();
        $asep = $this->karyawan('Asep');
        $this->sesiSendirian($asep, '2026-08-11', 20, 10.3, 0);

        $this->postJson("/api/v1/penggajian/{$asep->id}/bayar", ['tanggal' => '2026-08-13'])->assertOk();

        $baris = collect($this->getJson('/api/v1/penggajian?tanggal=2026-08-13')->assertOk()->json('data.baris'))
            ->firstWhere('karyawanId', (string) $asep->id);

        $this->assertTrue($baris['dibayar']);
        $this->assertFalse($baris['adaPerubahanSetelahDibayar']);
        $this->assertEqualsWithDelta(17_000.0, $baris['total'], 0.01);
        $this->assertEqualsWithDelta(16_845.0, $baris['totalSebelumBulat'], 0.01);
    }

    private function sesiSendirian(Karyawan $karyawan, string $tanggal, float $kgBahan, float $kgKristal, float $kgBrondol): void
    {
        $this->tambahStok(KategoriStok::NS1, $kgBahan, $tanggal);

        $produksi = app(ProduksiService::class);

        $sesi = $produksi->mulai([
            'tanggal' => $tanggal,
            'bahan' => [['grade' => Grade::NS1, 'kg' => $kgBahan]],
            'karyawan_1_id' => $karyawan->id,
        ]);

        $produksi->selesaikan($sesi, $kgKristal, $kgBrondol);
    }
}
