<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StatusPenderes;
use App\Models\Petani;
use Tests\TestCase;

/** Poin 1 revisi client: satu petani bisa punya lebih dari satu status penderes. */
class StatusPenderesTest extends TestCase
{
    public function test_petani_bisa_disimpan_dengan_beberapa_status_sekaligus(): void
    {
        $this->masukSebagai();

        $data = $this->postJson('/api/v1/petani', [
            'nama' => 'Sukirman',
            'status' => 'Member',
            'nomorMember' => '301',
            'statusPenderes' => ['PMS', 'plmd'],
            'kodeLahan' => 'BTN-014',
            'rtRw' => '02/05',
        ])->assertCreated()->json('data');

        $this->assertSame(['pms', 'plmd'], array_column($data['statusPenderes'], 'kode'));
        $this->assertSame('BTN-014', $data['kodeLahan']);
        $this->assertSame('02/05', $data['rtRw']);
        $this->assertSame('Pemilik Lahan Mendreng (Bayar Gula)', $data['statusPenderes'][1]['keterangan']);
    }

    public function test_status_diperbarui_sebagai_pengganti_bukan_tambahan(): void
    {
        $this->masukSebagai();
        $petani = $this->petani('Warsono');
        $petani->statusPenderes()->createMany([['kode' => 'pms'], ['kode' => 'plmr']]);

        $data = $this->putJson("/api/v1/petani/{$petani->id}", [
            'nama' => 'Warsono',
            'status' => 'Member',
            'nomorMember' => $petani->nomor_member,
            'statusPenderes' => ['pms', 'pls'],
        ])->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(['pms', 'pls'], array_column($data['statusPenderes'], 'kode'));
        $this->assertSame(2, $petani->statusPenderes()->count());
    }

    public function test_status_kosong_menghapus_seluruh_status(): void
    {
        $this->masukSebagai();
        $petani = $this->petani('Darto');
        $petani->statusPenderes()->create(['kode' => 'pms']);

        $this->putJson("/api/v1/petani/{$petani->id}", [
            'nama' => 'Darto',
            'status' => 'Non-Member',
            'statusPenderes' => [],
        ])->assertOk();

        $this->assertSame(0, $petani->statusPenderes()->count());
    }

    public function test_status_tidak_dikenal_ditolak(): void
    {
        $this->masukSebagai();

        $this->postJson('/api/v1/petani', [
            'nama' => 'Salah Status',
            'status' => 'Non-Member',
            'statusPenderes' => ['pms', 'xyz'],
        ])->assertStatus(422)->assertJsonValidationErrors('statusPenderes.1');
    }

    public function test_daftar_petani_bisa_difilter_per_status(): void
    {
        $this->masukSebagai(Role::STAFF_GUDANG);

        $penderes = $this->petani('Penderes');
        $penderes->statusPenderes()->create(['kode' => 'pms']);

        $pemilik = $this->petani('Pemilik');
        $pemilik->statusPenderes()->create(['kode' => 'pls']);

        $this->petani('Tanpa Status');

        $hanyaPms = $this->getJson('/api/v1/petani?statusPenderes=pms')->assertOk()->json('data');
        $this->assertSame(['Penderes'], array_column($hanyaPms, 'nama'));

        $gabungan = $this->getJson('/api/v1/petani?statusPenderes=pms,pls')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['Penderes', 'Pemilik'], array_column($gabungan, 'nama'));

        // Tanpa filter, semua petani ikut tampil.
        $this->assertCount(3, $this->getJson('/api/v1/petani')->assertOk()->json('data'));
    }

    public function test_kode_lahan_tidak_boleh_dipakai_dua_petani(): void
    {
        $this->masukSebagai();
        Petani::factory()->create(['kode_lahan' => 'BTN-001']);

        $this->postJson('/api/v1/petani', [
            'nama' => 'Kembar',
            'status' => 'Non-Member',
            'kodeLahan' => 'BTN-001',
        ])->assertStatus(422)->assertJsonValidationErrors('kodeLahan');
    }

    public function test_teks_kombinasi_dari_data_client_diurai_jadi_beberapa_status(): void
    {
        $this->assertSame(
            [StatusPenderes::PMS, StatusPenderes::PLMR],
            StatusPenderes::dariTeksKombinasi('PMS + PLMR'),
        );

        $this->assertSame([StatusPenderes::PMS], StatusPenderes::dariTeksKombinasi('pms / PMS'));
        $this->assertSame([], StatusPenderes::dariTeksKombinasi(null));
        $this->assertSame([], StatusPenderes::dariTeksKombinasi('  '));
    }
}
