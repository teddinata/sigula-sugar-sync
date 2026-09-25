<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Grade;
use App\Enums\KategoriStok;
use App\Enums\Role;
use App\Models\GradeHarga;
use App\Models\KartuStok;
use App\Models\Pembelian;
use Tests\TestCase;

class PembelianTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMaster();
        $this->travelTo('2026-08-13 09:00:00');
    }

    public function test_pembelian_menambah_stok_bahan_mentah_dan_mencatat_kartu_stok(): void
    {
        $this->masukSebagai(Role::STAFF_GUDANG);
        $petani = $this->petani('Sukirman');

        $response = $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-13',
            'petaniId' => $petani->id,
            'grade' => 'NS 1',
            'kg' => 250,
        ])->assertCreated();

        // Harga otomatis diambil dari Master Harga yang berlaku.
        $response->assertJsonPath('data.harga', 14500)
            ->assertJsonPath('data.total', 3625000)
            ->assertJsonPath('data.statusPembayaran', 'Lunas');

        $this->assertSame(250.0, $this->saldo(KategoriStok::NS1));

        $mutasi = KartuStok::query()->where('referensi_type', 'pembelian')->firstOrFail();
        $this->assertSame('masuk', $mutasi->jenis->value);
        $this->assertSame(250.0, (float) $mutasi->jumlah_kg);
        $this->assertSame(250.0, (float) $mutasi->saldo_setelah);
        $this->assertStringContainsString('Sukirman', $mutasi->keterangan);
    }

    public function test_harga_boleh_dinego_manual_per_transaksi(): void
    {
        $this->masukSebagai();
        $petani = $this->petani();

        $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-13',
            'petaniId' => $petani->id,
            'grade' => 'NS 2',
            'kg' => 100,
            'harga' => 13000,
        ])->assertCreated()
            ->assertJsonPath('data.harga', 13000)
            ->assertJsonPath('data.total', 1300000);
    }

    /** Transaksi lama harus tetap merujuk harga yang berlaku saat itu. */
    public function test_memakai_harga_yang_berlaku_pada_tanggal_transaksi(): void
    {
        $this->masukSebagai();
        $petani = $this->petani();

        GradeHarga::create([
            'grade' => Grade::NS1->value,
            'harga_per_kg' => 16000,
            'berlaku_dari' => '2026-08-12 00:00:00',
        ]);

        // Transaksi mundur ke tanggal 10 tetap memakai harga lama.
        $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-10',
            'petaniId' => $petani->id,
            'grade' => 'NS 1',
            'kg' => 10,
        ])->assertCreated()->assertJsonPath('data.harga', 14500);

        $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-13',
            'petaniId' => $petani->id,
            'grade' => 'NS 1',
            'kg' => 10,
        ])->assertCreated()->assertJsonPath('data.harga', 16000);
    }

    public function test_nomor_kwitansi_urut_dan_unik(): void
    {
        $this->masukSebagai();
        $petani = $this->petani();

        $nomor = [];

        for ($i = 0; $i < 3; $i++) {
            $nomor[] = $this->postJson('/api/v1/pembelian', [
                'tanggal' => '2026-08-13',
                'petaniId' => $petani->id,
                'grade' => 'Kecap',
                'kg' => 10,
            ])->assertCreated()->json('data.nomorKwitansi');
        }

        $this->assertSame(['KW/2026/08/0001', 'KW/2026/08/0002', 'KW/2026/08/0003'], $nomor);
    }

    public function test_kilogram_nol_atau_negatif_ditolak(): void
    {
        $this->masukSebagai();
        $petani = $this->petani();

        foreach ([0, -5] as $kg) {
            $this->postJson('/api/v1/pembelian', [
                'tanggal' => '2026-08-13',
                'petaniId' => $petani->id,
                'grade' => 'NS 1',
                'kg' => $kg,
            ])->assertStatus(422)->assertJsonValidationErrors('kg');
        }

        $this->assertSame(0.0, $this->saldo(KategoriStok::NS1));
    }

    public function test_membatalkan_pembelian_mengembalikan_stok(): void
    {
        $this->masukSebagai();
        $petani = $this->petani();

        $id = $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-13',
            'petaniId' => $petani->id,
            'grade' => 'NS 1',
            'kg' => 250,
        ])->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/pembelian/{$id}")->assertOk();

        $this->assertSame(0.0, $this->saldo(KategoriStok::NS1));
        $this->assertSoftDeleted('pembelian', ['id' => $id]);
        // Mutasi balik tetap tercatat sebagai audit trail.
        $this->assertSame(2, KartuStok::query()->where('referensi_type', 'pembelian')->count());
    }

    public function test_pembelian_tidak_bisa_dibatalkan_kalau_bahannya_sudah_dipakai_produksi(): void
    {
        $this->masukSebagai();
        $petani = $this->petani();
        $k1 = $this->karyawan('Pardi');
        $k2 = $this->karyawan('Asep');

        $id = $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-13', 'petaniId' => $petani->id, 'grade' => 'NS 1', 'kg' => 100,
        ])->assertCreated()->json('data.id');

        $sesi = $this->postJson('/api/v1/produksi/sesi', [
            'tanggal' => '2026-08-13', 'grade' => 'NS 1', 'kgBahan' => 100,
            'karyawan1Id' => $k1->id, 'karyawan2Id' => $k2->id,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/produksi/sesi/{$sesi}/selesai", ['kgKristal' => 80, 'kgBrondol' => 20])->assertOk();

        // Stok bahan sudah habis dipakai, jadi pembatalan harus ditolak.
        $this->deleteJson("/api/v1/pembelian/{$id}")->assertStatus(422);
        $this->assertNotSoftDeleted('pembelian', ['id' => $id]);
    }

    public function test_daftar_pembelian_bisa_difilter_dan_membawa_ringkasan(): void
    {
        $this->masukSebagai();
        $petani = $this->petani();

        $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-13', 'petaniId' => $petani->id, 'grade' => 'NS 1', 'kg' => 100,
        ])->assertCreated();
        $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-01', 'petaniId' => $petani->id, 'grade' => 'Kecap', 'kg' => 50,
        ])->assertCreated();

        $response = $this->getJson('/api/v1/pembelian?grade=ns1')->assertOk();
        $this->assertCount(1, $response->json('data'));

        $ringkasan = $this->getJson('/api/v1/pembelian')->assertOk()->json('ringkasan');
        $this->assertEqualsWithDelta(1_450_000.0, $ringkasan['hariIni'], 0.01);
        $this->assertEqualsWithDelta(1_450_000.0 + 475_000.0, $ringkasan['bulanIni'], 0.01);
        $this->assertSame(2, Pembelian::query()->count());
    }

    /** Kilogram desimal disimpan apa adanya, tidak dibulatkan. */
    public function test_kilogram_desimal_tidak_dibulatkan(): void
    {
        $this->masukSebagai();

        $data = $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-13',
            'petaniId' => $this->petani()->id,
            'grade' => 'NS 1',
            'kg' => 104.8,
        ])->assertCreated()->json('data');

        $this->assertEqualsWithDelta(104.8, $data['kg'], 0.0001);
        $this->assertEqualsWithDelta(104.8, $this->saldo(KategoriStok::NS1), 0.0001);
        // 104,8 x 14.500 = 1.519.600 -> dibulatkan ke 1.520.000
        $this->assertEqualsWithDelta(1_519_600.0, $data['totalSebelumBulat'], 0.01);
    }

    public function test_kilogram_lebih_dari_dua_desimal_ditolak(): void
    {
        $this->masukSebagai();

        $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-13',
            'petaniId' => $this->petani()->id,
            'grade' => 'NS 1',
            'kg' => 104.855,
        ])->assertStatus(422)->assertJsonValidationErrors('kg');
    }
}
