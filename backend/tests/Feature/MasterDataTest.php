<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\KategoriStok;
use App\Enums\Role;
use App\Models\GradeHarga;
use App\Models\Karyawan;
use App\Models\Petani;
use App\Models\TarifUpah;
use Tests\TestCase;

class MasterDataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMaster();
        $this->travelTo('2026-08-13 09:00:00');
    }

    /** Kode lahan sekaligus nomor member: terisi berarti Member. */
    public function test_petani_dengan_kode_lahan_otomatis_jadi_member(): void
    {
        $this->masukSebagai(Role::STAFF_GUDANG);

        $this->postJson('/api/v1/petani', [
            'nama' => 'Sukirman',
            'kodeLahan' => 'BA-002',
            'rtRw' => '01/01',
            'kontak' => '0812-3344-5566',
            'alamat' => 'Desa Sukamaju',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'Member')
            ->assertJsonPath('data.kodeLahan', 'BA-002')
            // nomorMember & labelMember menunjuk nilai yang sama.
            ->assertJsonPath('data.nomorMember', 'BA-002')
            ->assertJsonPath('data.labelMember', 'BA-002')
            ->assertJsonPath('data.aktif', true);
    }

    public function test_petani_tanpa_kode_lahan_jadi_non_member(): void
    {
        $this->masukSebagai();

        $this->postJson('/api/v1/petani', ['nama' => 'Tarjo'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'Non-Member')
            ->assertJsonPath('data.nomorMember', '');

        $this->assertNull(Petani::query()->firstOrFail()->kode_lahan);
    }

    public function test_kode_lahan_tidak_boleh_kembar(): void
    {
        $this->masukSebagai();
        Petani::factory()->create(['kode_lahan' => 'BA-231']);

        $this->postJson('/api/v1/petani', [
            'nama' => 'Darsono',
            'kodeLahan' => 'BA-231',
        ])->assertStatus(422)->assertJsonValidationErrors('kodeLahan');
    }

    public function test_petani_bisa_dinonaktifkan_tanpa_menghapus_riwayat(): void
    {
        $this->masukSebagai();
        $petani = Petani::factory()->create(['nama' => 'Dasirin']);

        $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-13', 'petaniId' => $petani->id, 'grade' => 'NS 1', 'kg' => 100,
        ])->assertCreated();

        $this->putJson("/api/v1/petani/{$petani->id}", ['nama' => 'Dasirin', 'aktif' => false])
            ->assertOk()
            ->assertJsonPath('data.aktif', false);

        $this->assertFalse($petani->refresh()->aktif);
        $this->assertNotSoftDeleted('petani', ['id' => $petani->id]);

        // Default daftar hanya menampilkan yang aktif.
        $this->assertCount(0, $this->getJson('/api/v1/petani')->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/petani?sertakanNonaktif=1')->assertOk()->json('data'));
    }

    public function test_petani_dengan_transaksi_tidak_bisa_dihapus(): void
    {
        $this->masukSebagai();
        $petani = $this->petani();

        $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-13', 'petaniId' => $petani->id, 'grade' => 'NS 1', 'kg' => 100,
        ])->assertCreated();

        $this->deleteJson("/api/v1/petani/{$petani->id}")->assertStatus(422);
        $this->assertNotSoftDeleted('petani', ['id' => $petani->id]);
    }

    public function test_pencarian_petani_berdasarkan_nama_dan_kode_lahan(): void
    {
        $this->masukSebagai();
        Petani::factory()->create(['nama' => 'Haji Wardi', 'kode_lahan' => 'BA-214']);
        Petani::factory()->create(['nama' => 'Sukirman', 'kode_lahan' => 'BA-231']);

        $this->assertCount(1, $this->getJson('/api/v1/petani?q=Wardi')->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/petani?q=BA-231')->assertOk()->json('data'));
        $this->assertCount(2, $this->getJson('/api/v1/petani')->assertOk()->json('data'));
    }

    /** Perubahan harga tidak boleh mengubah record lama. */
    public function test_ubah_harga_menambah_record_baru_bukan_update(): void
    {
        $this->masukSebagai(Role::OWNER);
        $sebelum = GradeHarga::query()->where('grade', 'ns1')->count();

        $this->postJson('/api/v1/master/harga', [
            'grade' => 'NS 1',
            'harga' => 15200,
            'catatan' => 'Kenaikan harga pasar',
        ])->assertCreated()->assertJsonPath('data.hargaPerKg', 15200);

        $this->assertSame($sebelum + 1, GradeHarga::query()->where('grade', 'ns1')->count());
        // Record harga lama tetap utuh.
        $this->assertDatabaseHas('grade_harga', ['grade' => 'ns1', 'harga_per_kg' => 14500]);

        $riwayat = $this->getJson('/api/v1/master/harga')->assertOk();
        $this->assertEqualsWithDelta(15200.0, $riwayat->json('data.hargaBeli.NS 1'), 0.01);
        $this->assertEqualsWithDelta(14500.0, $riwayat->json('data.riwayat.0.hargaLama'), 0.01);
        $this->assertEqualsWithDelta(15200.0, $riwayat->json('data.riwayat.0.hargaBaru'), 0.01);
    }

    public function test_harga_nol_ditolak(): void
    {
        $this->masukSebagai();

        $this->postJson('/api/v1/master/harga', ['grade' => 'NS 1', 'harga' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('harga');
    }

    public function test_ubah_tarif_menyimpan_histori(): void
    {
        $this->masukSebagai();

        $this->postJson('/api/v1/master/tarif', ['jenis' => 'kristal', 'nilai' => 1250])
            ->assertCreated()
            ->assertJsonPath('data.nilai', 1250);

        $this->assertSame(2, TarifUpah::query()->where('jenis', 'kristal')->count());

        $tarif = $this->getJson('/api/v1/master/tarif')->assertOk();
        $this->assertEqualsWithDelta(1250.0, $tarif->json('data.tarif.kristal'), 0.01);
        $this->assertEqualsWithDelta(800.0, $tarif->json('data.tarif.brondol'), 0.01);
        $this->assertEqualsWithDelta(5000.0, $tarif->json('data.tarif.uangMakan'), 0.01);
    }

    public function test_tarif_uang_makan_menerima_penulisan_camel_case(): void
    {
        $this->masukSebagai();

        $this->postJson('/api/v1/master/tarif', ['jenis' => 'uangMakan', 'nilai' => 7500])->assertCreated();

        $this->assertEqualsWithDelta(
            7500.0,
            $this->getJson('/api/v1/master/tarif')->json('data.tarif.uangMakan'),
            0.01
        );
    }

    public function test_karyawan_dengan_histori_produksi_dinonaktifkan_bukan_dihapus(): void
    {
        $this->masukSebagai();
        $k1 = $this->karyawan('Pardi');
        $k2 = $this->karyawan('Asep');
        $this->tambahStok(KategoriStok::NS1, 100);

        $this->postJson('/api/v1/produksi/sesi', [
            'tanggal' => '2026-08-13', 'grade' => 'NS 1', 'kgBahan' => 100,
            'karyawan1Id' => $k1->id, 'karyawan2Id' => $k2->id,
        ])->assertCreated();

        $this->deleteJson("/api/v1/master/karyawan/{$k1->id}")->assertOk();

        $this->assertNotSoftDeleted('karyawan', ['id' => $k1->id]);
        $this->assertFalse(Karyawan::query()->findOrFail($k1->id)->aktif);
    }

    public function test_karyawan_tanpa_histori_bisa_dihapus(): void
    {
        $this->masukSebagai();
        $karyawan = $this->karyawan('Baru Masuk');

        $this->deleteJson("/api/v1/master/karyawan/{$karyawan->id}")->assertOk();

        $this->assertSoftDeleted('karyawan', ['id' => $karyawan->id]);
    }

    public function test_eksportir_bisa_dikelola(): void
    {
        $this->masukSebagai();

        $id = $this->postJson('/api/v1/master/eksportir', [
            'nama' => 'PT Global Sweet Export',
            'kontak' => '021-5567890',
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/master/eksportir/{$id}", ['kontak' => '021-1112223'])
            ->assertOk()
            ->assertJsonPath('data.kontak', '021-1112223');

        $this->deleteJson("/api/v1/master/eksportir/{$id}")->assertOk();
        $this->assertSoftDeleted('eksportir', ['id' => $id]);
    }
}
