<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Pembelian;
use App\Models\Pengepul;
use Tests\TestCase;

/** Poin 2 revisi client: pembelian bisa lewat pengepul, bukan hanya langsung ke petani. */
class PengepulTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMaster();
        $this->travelTo('2026-08-13 09:00:00');
    }

    public function test_crud_pengepul(): void
    {
        $this->masukSebagai();

        $dibuat = $this->postJson('/api/v1/pengepul', [
            'nama' => 'Haji Rohmat',
            'kontak' => '081234567890',
            'alamat' => 'Batuanten',
        ])->assertCreated()->json('data');

        $this->assertTrue($dibuat['aktif']);

        $this->putJson("/api/v1/pengepul/{$dibuat['id']}", ['kontak' => '089999999999'])
            ->assertOk()
            ->assertJsonPath('data.kontak', '089999999999')
            ->assertJsonPath('data.nama', 'Haji Rohmat');

        $this->deleteJson("/api/v1/pengepul/{$dibuat['id']}")->assertOk();
        $this->assertSoftDeleted('pengepul', ['id' => $dibuat['id']]);
    }

    public function test_pengepul_yang_punya_transaksi_dinonaktifkan_bukan_dihapus(): void
    {
        $this->masukSebagai();
        $pengepul = Pengepul::factory()->create();

        $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-13',
            'petaniId' => $this->petani()->id,
            'pengepulId' => $pengepul->id,
            'grade' => 'NS 1',
            'kg' => 100,
        ])->assertCreated();

        $this->deleteJson("/api/v1/pengepul/{$pengepul->id}")->assertOk();

        // Riwayat pembelian harus tetap bisa menampilkan nama pengepulnya.
        $this->assertNotSoftDeleted('pengepul', ['id' => $pengepul->id]);
        $this->assertFalse($pengepul->refresh()->aktif);
    }

    public function test_daftar_pengepul_default_hanya_yang_aktif(): void
    {
        $this->masukSebagai(Role::STAFF_GUDANG);
        Pengepul::factory()->create(['nama' => 'Aktif Terus']);
        Pengepul::factory()->nonaktif()->create(['nama' => 'Sudah Berhenti']);

        $aktif = $this->getJson('/api/v1/pengepul')->assertOk()->json('data');
        $this->assertSame(['Aktif Terus'], array_column($aktif, 'nama'));

        $semua = $this->getJson('/api/v1/pengepul?sertakanNonaktif=1')->assertOk()->json('data');
        $this->assertCount(2, $semua);
    }

    public function test_pembelian_menyimpan_pengepul_dan_bisa_difilter(): void
    {
        $this->masukSebagai();
        $pengepul = Pengepul::factory()->create(['nama' => 'Haji Rohmat']);

        $lewatPengepul = $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-13',
            'petaniId' => $this->petani('Sukirman')->id,
            'pengepulId' => $pengepul->id,
            'grade' => 'NS 1',
            'kg' => 100,
        ])->assertCreated()->json('data');

        $this->assertSame((string) $pengepul->id, $lewatPengepul['pengepulId']);
        $this->assertSame('Haji Rohmat', $lewatPengepul['pengepul']['nama']);

        // Beli langsung dari petani: pengepul kosong dan itu tetap sah.
        $langsung = $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-13',
            'petaniId' => $this->petani('Warsono')->id,
            'grade' => 'NS 2',
            'kg' => 50,
        ])->assertCreated()->json('data');

        $this->assertNull($langsung['pengepulId']);

        $filterPengepul = $this->getJson("/api/v1/pembelian?pengepulId={$pengepul->id}")->assertOk()->json('data');
        $this->assertSame([$lewatPengepul['id']], array_column($filterPengepul, 'id'));

        $tanpaPengepul = $this->getJson('/api/v1/pembelian?punyaPengepul=0')->assertOk()->json('data');
        $this->assertSame([$langsung['id']], array_column($tanpaPengepul, 'id'));

        $adaPengepul = $this->getJson('/api/v1/pembelian?punyaPengepul=1')->assertOk()->json('data');
        $this->assertSame([$lewatPengepul['id']], array_column($adaPengepul, 'id'));

        // Pencarian bebas juga menjangkau nama pengepul.
        $cari = $this->getJson('/api/v1/pembelian?q=Rohmat')->assertOk()->json('data');
        $this->assertSame([$lewatPengepul['id']], array_column($cari, 'id'));
    }

    public function test_pengepul_tidak_dikenal_ditolak(): void
    {
        $this->masukSebagai();

        $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-13',
            'petaniId' => $this->petani()->id,
            'pengepulId' => 999,
            'grade' => 'NS 1',
            'kg' => 10,
        ])->assertStatus(422)->assertJsonValidationErrors('pengepulId');
    }

    public function test_staff_produksi_tidak_boleh_mengelola_pengepul(): void
    {
        $this->masukSebagai(Role::STAFF_PRODUKSI);

        $this->postJson('/api/v1/pengepul', ['nama' => 'Nekat'])->assertForbidden();
        $this->assertSame(0, Pengepul::query()->count());
    }

    public function test_pembelian_tetap_utuh_setelah_pengepul_dinonaktifkan(): void
    {
        $this->masukSebagai();
        $pengepul = Pengepul::factory()->create(['nama' => 'Haji Rohmat']);

        $id = $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-13',
            'petaniId' => $this->petani()->id,
            'pengepulId' => $pengepul->id,
            'grade' => 'NS 1',
            'kg' => 100,
        ])->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/pengepul/{$pengepul->id}")->assertOk();

        $this->getJson("/api/v1/pembelian/{$id}")
            ->assertOk()
            ->assertJsonPath('data.pengepul.nama', 'Haji Rohmat');

        $this->assertSame((int) $pengepul->id, Pembelian::findOrFail($id)->pengepul_id);
    }
}
