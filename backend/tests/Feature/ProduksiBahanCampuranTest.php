<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\KategoriStok;
use App\Models\ProduksiKaryawan;
use App\Models\SesiTungku;
use Tests\TestCase;

/**
 * Poin 3-6 revisi client: satu tungku boleh memakai beberapa grade sekaligus,
 * boleh dikerjakan satu orang, dan kg-nya boleh desimal.
 */
class ProduksiBahanCampuranTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMaster();
        $this->travelTo('2026-08-13 09:00:00');
    }

    public function test_satu_tungku_bisa_memakai_beberapa_grade_sekaligus(): void
    {
        $this->masukSebagai();
        $k1 = $this->karyawan('Pardi');
        $k2 = $this->karyawan('Asep');
        $this->tambahStok(KategoriStok::NS1, 100);
        $this->tambahStok(KategoriStok::NS2, 100);

        $data = $this->postJson('/api/v1/produksi/sesi', [
            'tanggal' => '2026-08-13',
            'bahan' => [
                ['grade' => 'NS 1', 'kg' => 60],
                ['grade' => 'NS 2', 'kg' => 40.5],
            ],
            'karyawan1Id' => $k1->id,
            'karyawan2Id' => $k2->id,
        ])->assertCreated()->json('data');

        // kgBahan adalah TOTAL seluruh grade; rinciannya di `bahan`.
        $this->assertEqualsWithDelta(100.5, $data['kgBahan'], 0.001);
        $this->assertSame(['NS 1', 'NS 2'], array_column($data['bahan'], 'grade'));
        $this->assertEqualsWithDelta(40.5, $data['bahan'][1]['kg'], 0.001);

        $this->postJson("/api/v1/produksi/sesi/{$data['id']}/selesai", [
            'kgKristal' => 70,
            'kgBrondol' => 5,
        ])->assertOk();

        // Stok dipotong per grade sesuai porsinya masing-masing.
        $this->assertEqualsWithDelta(40.0, $this->saldo(KategoriStok::NS1), 0.001);
        $this->assertEqualsWithDelta(59.5, $this->saldo(KategoriStok::NS2), 0.001);
        $this->assertEqualsWithDelta(75.0, $this->saldo(KategoriStok::KRISTAL) + $this->saldo(KategoriStok::BRONDOL), 0.001);
    }

    public function test_stok_dicek_per_grade_bukan_totalnya(): void
    {
        $this->masukSebagai();
        $k1 = $this->karyawan('Pardi');
        $this->tambahStok(KategoriStok::NS1, 100);
        $this->tambahStok(KategoriStok::NS2, 10);

        // Total stok 110 kg cukup, tapi NS 2 sendiri kurang.
        $this->postJson('/api/v1/produksi/sesi', [
            'tanggal' => '2026-08-13',
            'bahan' => [
                ['grade' => 'NS 1', 'kg' => 50],
                ['grade' => 'NS 2', 'kg' => 50],
            ],
            'karyawan1Id' => $k1->id,
        ])->assertStatus(422);

        $this->assertSame(0, SesiTungku::query()->count());
        $this->assertEqualsWithDelta(100.0, $this->saldo(KategoriStok::NS1), 0.001);
    }

    public function test_grade_yang_sama_tidak_boleh_ditulis_dua_kali(): void
    {
        $this->masukSebagai();
        $k1 = $this->karyawan('Pardi');
        $this->tambahStok(KategoriStok::NS1, 100);

        $this->postJson('/api/v1/produksi/sesi', [
            'tanggal' => '2026-08-13',
            'bahan' => [
                ['grade' => 'NS 1', 'kg' => 10],
                ['grade' => 'ns1', 'kg' => 20],
            ],
            'karyawan1Id' => $k1->id,
        ])->assertStatus(422)->assertJsonValidationErrors('bahan.1.grade');
    }

    public function test_tungku_boleh_dikerjakan_satu_karyawan_saja(): void
    {
        $this->masukSebagai();
        $k1 = $this->karyawan('Pardi');
        $this->tambahStok(KategoriStok::NS1, 100);

        $data = $this->postJson('/api/v1/produksi/sesi', [
            'tanggal' => '2026-08-13',
            'bahan' => [['grade' => 'NS 1', 'kg' => 100]],
            'karyawan1Id' => $k1->id,
        ])->assertCreated()->json('data');

        $this->assertSame([(string) $k1->id], $data['karyawanIds']);

        $this->postJson("/api/v1/produksi/sesi/{$data['id']}/selesai", [
            'kgKristal' => 60,
            'kgBrondol' => 9,
        ])->assertOk();

        // Tanpa rekan kerja, seluruh hasil jadi porsi satu orang (tidak dibagi 2).
        $porsi = ProduksiKaryawan::query()->get();
        $this->assertCount(1, $porsi);
        $this->assertEqualsWithDelta(100.0, (float) $porsi[0]->kg_bahan_mentah_porsi, 0.001);
        $this->assertEqualsWithDelta(60.0, (float) $porsi[0]->kg_kristal_porsi, 0.001);
        $this->assertEqualsWithDelta(9.0, (float) $porsi[0]->kg_brondol_porsi, 0.001);
    }

    public function test_dua_karyawan_tetap_dibagi_rata_tanpa_kehilangan_sisa(): void
    {
        $this->masukSebagai();
        $k1 = $this->karyawan('Pardi');
        $k2 = $this->karyawan('Asep');
        $this->tambahStok(KategoriStok::NS1, 100);

        $data = $this->postJson('/api/v1/produksi/sesi', [
            'tanggal' => '2026-08-13',
            'bahan' => [['grade' => 'NS 1', 'kg' => 99.99]],
            'karyawan1Id' => $k1->id,
            'karyawan2Id' => $k2->id,
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/produksi/sesi/{$data['id']}/selesai", [
            'kgKristal' => 55.55,
            'kgBrondol' => 3.33,
        ])->assertOk();

        $porsi = ProduksiKaryawan::query()->get();
        $this->assertCount(2, $porsi);
        $this->assertEqualsWithDelta(99.99, $porsi->sum(fn ($p): float => (float) $p->kg_bahan_mentah_porsi), 0.001);
        $this->assertEqualsWithDelta(55.55, $porsi->sum(fn ($p): float => (float) $p->kg_kristal_porsi), 0.001);
        $this->assertEqualsWithDelta(3.33, $porsi->sum(fn ($p): float => (float) $p->kg_brondol_porsi), 0.001);
    }

    public function test_karyawan_yang_sama_tidak_boleh_diisi_dua_kali(): void
    {
        $this->masukSebagai();
        $k1 = $this->karyawan('Pardi');
        $this->tambahStok(KategoriStok::NS1, 100);

        $this->postJson('/api/v1/produksi/sesi', [
            'tanggal' => '2026-08-13',
            'bahan' => [['grade' => 'NS 1', 'kg' => 10]],
            'karyawan1Id' => $k1->id,
            'karyawan2Id' => $k1->id,
        ])->assertStatus(422)->assertJsonValidationErrors('karyawan2Id');
    }

    public function test_bahan_wajib_diisi(): void
    {
        $this->masukSebagai();
        $k1 = $this->karyawan('Pardi');

        $this->postJson('/api/v1/produksi/sesi', [
            'tanggal' => '2026-08-13',
            'karyawan1Id' => $k1->id,
        ])->assertStatus(422)->assertJsonValidationErrors('bahan');

        $this->postJson('/api/v1/produksi/sesi', [
            'tanggal' => '2026-08-13',
            'bahan' => [['grade' => 'NS 1', 'kg' => 0]],
            'karyawan1Id' => $k1->id,
        ])->assertStatus(422)->assertJsonValidationErrors('bahan.0.kg');
    }

    /** Klien versi lama masih mengirim `grade` + `kgBahan` tunggal. */
    public function test_bentuk_lama_satu_grade_tetap_diterima(): void
    {
        $this->masukSebagai();
        $k1 = $this->karyawan('Pardi');
        $k2 = $this->karyawan('Asep');
        $this->tambahStok(KategoriStok::NS1, 100);

        $data = $this->postJson('/api/v1/produksi/sesi', [
            'tanggal' => '2026-08-13',
            'grade' => 'NS 1',
            'kgBahan' => 80,
            'karyawan1Id' => $k1->id,
            'karyawan2Id' => $k2->id,
        ])->assertCreated()->json('data');

        $this->assertCount(1, $data['bahan']);
        $this->assertSame('NS 1', $data['bahan'][0]['grade']);
        $this->assertEqualsWithDelta(80.0, $data['bahan'][0]['kg'], 0.001);
    }
}
