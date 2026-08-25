<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\KategoriStok;
use App\Enums\Role;
use App\Models\KartuStok;
use App\Models\ProduksiKaryawan;
use App\Models\SesiTungku;
use Tests\TestCase;

class ProduksiSesiTungkuTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMaster();
    }

    /**
     * Test case resmi dari client:
     * Pardi & Asep memasak 1 tungku, 100 kg bahan mentah, hasil 80 kg kristal +
     * 20 kg brondol. Sistem harus membagi rata: masing-masing 50 kg bahan,
     * 40 kg kristal, 10 kg brondol.
     */
    public function test_hasil_satu_tungku_dibagi_rata_ke_dua_karyawan(): void
    {
        $this->masukSebagai(Role::STAFF_PRODUKSI);
        $pardi = $this->karyawan('Pardi');
        $asep = $this->karyawan('Asep Saepudin');
        $this->tambahStok(KategoriStok::NS1, 100);

        $sesi = $this->postJson('/api/v1/produksi/sesi', [
            'tanggal' => '2026-08-13',
            'kodeTungku' => 'TGK-01',
            'grade' => 'NS 1',
            'kgBahan' => 100,
            'karyawan1Id' => $pardi->id,
            'karyawan2Id' => $asep->id,
        ])->assertCreated()->json('data');

        $this->assertSame('Sedang Diproses', $sesi['status']);

        $selesai = $this->postJson("/api/v1/produksi/sesi/{$sesi['id']}/selesai", [
            'kgKristal' => 80,
            'kgBrondol' => 20,
        ])->assertOk()->json('data');

        $this->assertSame('Selesai', $selesai['status']);
        // (80 + 20) / 100 × 100%
        $this->assertEqualsWithDelta(100.0, $selesai['rendemen'], 0.001);

        // Pembagian otomatis ÷2 untuk keperluan penggajian.
        foreach ([$pardi, $asep] as $karyawan) {
            $porsi = ProduksiKaryawan::query()
                ->where('sesi_tungku_id', $sesi['id'])
                ->where('karyawan_id', $karyawan->id)
                ->firstOrFail();

            $this->assertSame(50.0, (float) $porsi->kg_bahan_mentah_porsi, $karyawan->nama);
            $this->assertSame(40.0, (float) $porsi->kg_kristal_porsi, $karyawan->nama);
            $this->assertSame(10.0, (float) $porsi->kg_brondol_porsi, $karyawan->nama);
        }

        // Efek stok: bahan mentah berkurang, produk jadi bertambah.
        $this->assertSame(0.0, $this->saldo(KategoriStok::NS1));
        $this->assertSame(80.0, $this->saldo(KategoriStok::KRISTAL));
        $this->assertSame(20.0, $this->saldo(KategoriStok::BRONDOL));

        // 1 mutasi keluar bahan + 2 mutasi masuk produk jadi.
        $this->assertSame(3, KartuStok::query()->where('referensi_type', 'sesi_tungku')->count());
    }

    public function test_pembagian_ganjil_tetap_berjumlah_sama_dengan_total_sesi(): void
    {
        $this->masukSebagai();
        $k1 = $this->karyawan('Joko');
        $k2 = $this->karyawan('Bambang');
        $this->tambahStok(KategoriStok::NS2, 100);

        $sesi = $this->postJson('/api/v1/produksi/sesi', [
            'tanggal' => '2026-08-13',
            'grade' => 'NS 2',
            'kgBahan' => 95,
            'karyawan1Id' => $k1->id,
            'karyawan2Id' => $k2->id,
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/produksi/sesi/{$sesi['id']}/selesai", [
            'kgKristal' => 71.05,
            'kgBrondol' => 15.31,
        ])->assertOk();

        $porsi = ProduksiKaryawan::query()->where('sesi_tungku_id', $sesi['id'])->get();

        $this->assertSame(71.05, round((float) $porsi->sum('kg_kristal_porsi'), 2));
        $this->assertSame(15.31, round((float) $porsi->sum('kg_brondol_porsi'), 2));
        $this->assertSame(95.0, round((float) $porsi->sum('kg_bahan_mentah_porsi'), 2));
    }

    public function test_dua_slot_karyawan_tidak_boleh_orang_yang_sama(): void
    {
        $this->masukSebagai();
        $pardi = $this->karyawan('Pardi');
        $this->tambahStok(KategoriStok::NS1, 100);

        $this->postJson('/api/v1/produksi/sesi', [
            'tanggal' => '2026-08-13',
            'grade' => 'NS 1',
            'kgBahan' => 100,
            'karyawan1Id' => $pardi->id,
            'karyawan2Id' => $pardi->id,
        ])->assertStatus(422)->assertJsonValidationErrors('karyawan2Id');
    }

    public function test_sesi_tidak_bisa_dimulai_kalau_bahan_mentah_kurang(): void
    {
        $this->masukSebagai();
        $k1 = $this->karyawan('Pardi');
        $k2 = $this->karyawan('Asep');
        $this->tambahStok(KategoriStok::NS1, 50);

        $this->postJson('/api/v1/produksi/sesi', [
            'tanggal' => '2026-08-13',
            'grade' => 'NS 1',
            'kgBahan' => 100,
            'karyawan1Id' => $k1->id,
            'karyawan2Id' => $k2->id,
        ])->assertStatus(422)->assertJsonValidationErrors('kgBahan');
    }

    /** Bahan yang dipakai tungku yang masih berjalan tidak boleh dijanjikan ke tungku lain. */
    public function test_bahan_yang_sedang_dipakai_tungku_lain_tidak_dihitung_tersedia(): void
    {
        $this->masukSebagai();
        $k1 = $this->karyawan('Pardi');
        $k2 = $this->karyawan('Asep');
        $k3 = $this->karyawan('Joko');
        $k4 = $this->karyawan('Eko');
        $this->tambahStok(KategoriStok::NS1, 150);

        $this->postJson('/api/v1/produksi/sesi', [
            'tanggal' => '2026-08-13', 'grade' => 'NS 1', 'kgBahan' => 100,
            'karyawan1Id' => $k1->id, 'karyawan2Id' => $k2->id,
        ])->assertCreated();

        $this->postJson('/api/v1/produksi/sesi', [
            'tanggal' => '2026-08-13', 'grade' => 'NS 1', 'kgBahan' => 100,
            'karyawan1Id' => $k3->id, 'karyawan2Id' => $k4->id,
        ])->assertStatus(422)->assertJsonValidationErrors('kgBahan');
    }

    public function test_sesi_yang_sudah_selesai_tidak_bisa_diselesaikan_dua_kali(): void
    {
        $this->masukSebagai();
        $sesi = $this->buatSesiSelesai();

        $this->postJson("/api/v1/produksi/sesi/{$sesi->id}/selesai", [
            'kgKristal' => 10,
            'kgBrondol' => 5,
        ])->assertStatus(422);

        // Stok tidak boleh terpotong dua kali.
        $this->assertSame(80.0, $this->saldo(KategoriStok::KRISTAL));
    }

    public function test_hasil_boleh_melebihi_bahan_mentah_karena_ada_penambahan_di_lapangan(): void
    {
        // Client memakai gula tambahan di luar sistem, jadi rendemen >100% wajar
        // dan tidak boleh diblokir — cukup dicatat apa adanya.
        $this->masukSebagai();
        $k1 = $this->karyawan('Pardi');
        $k2 = $this->karyawan('Asep');
        $this->tambahStok(KategoriStok::NS1, 100);

        $sesi = $this->postJson('/api/v1/produksi/sesi', [
            'tanggal' => '2026-08-13', 'grade' => 'NS 1', 'kgBahan' => 100,
            'karyawan1Id' => $k1->id, 'karyawan2Id' => $k2->id,
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/produksi/sesi/{$sesi['id']}/selesai", [
            'kgKristal' => 95,
            'kgBrondol' => 20,
        ])->assertOk();

        // assertJsonPath dihindari: JSON menyerialkan 115.0 menjadi 115 (int).
        $this->assertEqualsWithDelta(115.0, SesiTungku::findOrFail($sesi['id'])->rendemen, 0.001);

        $this->assertSame(0.0, $this->saldo(KategoriStok::NS1));
        $this->assertSame(95.0, $this->saldo(KategoriStok::KRISTAL));
        $this->assertSame(20.0, $this->saldo(KategoriStok::BRONDOL));
    }

    public function test_hasil_kosong_ditolak(): void
    {
        $this->masukSebagai();
        $k1 = $this->karyawan('Pardi');
        $k2 = $this->karyawan('Asep');
        $this->tambahStok(KategoriStok::NS1, 100);

        $sesi = $this->postJson('/api/v1/produksi/sesi', [
            'tanggal' => '2026-08-13', 'grade' => 'NS 1', 'kgBahan' => 100,
            'karyawan1Id' => $k1->id, 'karyawan2Id' => $k2->id,
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/produksi/sesi/{$sesi['id']}/selesai", [
            'kgKristal' => 0,
            'kgBrondol' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors('kgKristal');
    }

    public function test_membatalkan_sesi_selesai_mengembalikan_stok_dan_porsi_karyawan(): void
    {
        $this->masukSebagai();
        $sesi = $this->buatSesiSelesai();

        $this->deleteJson("/api/v1/produksi/sesi/{$sesi->id}")->assertOk();

        $this->assertSame(100.0, $this->saldo(KategoriStok::NS1));
        $this->assertSame(0.0, $this->saldo(KategoriStok::KRISTAL));
        $this->assertSame(0.0, $this->saldo(KategoriStok::BRONDOL));
        $this->assertSame(0, ProduksiKaryawan::query()->where('sesi_tungku_id', $sesi->id)->count());
        $this->assertSoftDeleted('sesi_tungku', ['id' => $sesi->id]);
    }

    public function test_kode_tungku_dibuat_otomatis_kalau_dikosongkan(): void
    {
        $this->masukSebagai();
        $k1 = $this->karyawan('Pardi');
        $k2 = $this->karyawan('Asep');
        $this->tambahStok(KategoriStok::NS1, 300);

        $pertama = $this->postJson('/api/v1/produksi/sesi', [
            'tanggal' => '2026-08-13', 'grade' => 'NS 1', 'kgBahan' => 100,
            'karyawan1Id' => $k1->id, 'karyawan2Id' => $k2->id,
        ])->assertCreated()->json('data.kodeTungku');

        $kedua = $this->postJson('/api/v1/produksi/sesi', [
            'tanggal' => '2026-08-13', 'grade' => 'NS 1', 'kgBahan' => 100,
            'karyawan1Id' => $k1->id, 'karyawan2Id' => $k2->id,
        ])->assertCreated()->json('data.kodeTungku');

        $this->assertSame('TGK-01', $pertama);
        $this->assertSame('TGK-02', $kedua);
    }

    public function test_filter_tanggal_menampilkan_semua_tungku_di_hari_tersebut(): void
    {
        $this->masukSebagai();
        $this->buatSesiSelesai('2026-08-12');
        $this->buatSesiSelesai('2026-08-13');

        $response = $this->getJson('/api/v1/produksi/sesi?tanggal=2026-08-13')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('2026-08-13', $response->json('data.0.tanggal'));
    }

    private function buatSesiSelesai(string $tanggal = '2026-08-13'): SesiTungku
    {
        $k1 = $this->karyawan('Pardi '.uniqid());
        $k2 = $this->karyawan('Asep '.uniqid());
        $this->tambahStok(KategoriStok::NS1, 100);

        $sesi = $this->postJson('/api/v1/produksi/sesi', [
            'tanggal' => $tanggal,
            'grade' => 'NS 1',
            'kgBahan' => 100,
            'karyawan1Id' => $k1->id,
            'karyawan2Id' => $k2->id,
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/produksi/sesi/{$sesi['id']}/selesai", [
            'kgKristal' => 80,
            'kgBrondol' => 20,
        ])->assertOk();

        return SesiTungku::query()->findOrFail($sesi['id']);
    }
}
