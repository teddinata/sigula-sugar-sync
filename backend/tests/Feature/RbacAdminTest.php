<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use Tests\TestCase;

/** Admin menjalankan operasional penuh, tapi angka keuntungan tertutup untuknya. */
class RbacAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMaster();
        $this->travelTo('2026-08-13 09:00:00');
    }

    public function test_admin_ditolak_di_seluruh_endpoint_keuangan(): void
    {
        $this->masukSebagai(Role::ADMIN);

        $this->getJson('/api/v1/keuangan/laba-rugi')->assertForbidden();
        $this->getJson('/api/v1/keuangan/tren')->assertForbidden();
        $this->getJson('/api/v1/keuangan/biaya')->assertForbidden();
        $this->getJson('/api/v1/keuangan/ringkasan-ai')->assertForbidden();
        $this->getJson('/api/v1/keuangan/laba-rugi/export')->assertForbidden();
        $this->getJson('/api/v1/keuangan/biaya/export')->assertForbidden();
        $this->postJson('/api/v1/keuangan/biaya', [
            'tanggal' => '2026-08-13', 'kategori' => 'Operasional', 'keterangan' => 'Nekat', 'nominal' => 1000,
        ])->assertForbidden();
    }

    public function test_menu_admin_tidak_memuat_keuangan(): void
    {
        $this->masukSebagai(Role::ADMIN);

        $menu = $this->getJson('/api/v1/auth/me')->assertOk()->json('data.menu');

        $this->assertNotContains('keuangan', $menu);
        $this->assertNotContains('pengguna', $menu);
        $this->assertContains('pembelian', $menu);
        $this->assertContains('penggajian', $menu);
        $this->assertContains('penjualan', $menu);
        $this->assertContains('audit', $menu);
    }

    /** Kalau angka laba tetap muncul di dashboard, pembatasan menu jadi percuma. */
    public function test_dashboard_admin_tidak_memuat_angka_keuangan(): void
    {
        $this->masukSebagai(Role::ADMIN);

        $data = $this->getJson('/api/v1/dashboard')->assertOk()->json('data');

        $this->assertArrayNotHasKey('keuangan', $data);
        $this->assertArrayNotHasKey('tren', $data);
        // Data operasional tetap terlihat.
        $this->assertArrayHasKey('stok', $data);
        $this->assertArrayHasKey('produksiHariIni', $data);
    }

    public function test_dashboard_owner_tetap_memuat_angka_keuangan(): void
    {
        $this->masukSebagai(Role::OWNER);

        $data = $this->getJson('/api/v1/dashboard')->assertOk()->json('data');

        $this->assertArrayHasKey('keuangan', $data);
        $this->assertArrayHasKey('tren', $data);
    }

    public function test_admin_tetap_bisa_mengelola_operasional(): void
    {
        $this->masukSebagai(Role::ADMIN);
        $petani = $this->petani();

        $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-13', 'petaniId' => $petani->id, 'grade' => 'NS 1', 'kg' => 100,
        ])->assertCreated();

        $this->getJson('/api/v1/penggajian?tanggal=2026-08-13')->assertOk();
        $this->getJson('/api/v1/penjualan')->assertOk();
        $this->getJson('/api/v1/produksi/sesi')->assertOk();
    }

    /** Audit log dipisah dari keuangan supaya Admin bisa menelusuri perubahan data. */
    public function test_audit_log_bisa_dibuka_admin_tapi_tidak_staff(): void
    {
        $this->masukSebagai(Role::ADMIN);
        $this->getJson('/api/v1/audit-log')->assertOk();

        $this->masukSebagai(Role::STAFF_GUDANG);
        $this->getJson('/api/v1/audit-log')->assertForbidden();
    }

    public function test_staff_tetap_tidak_bisa_membuka_keuangan(): void
    {
        $this->masukSebagai(Role::STAFF_GUDANG);
        $this->getJson('/api/v1/keuangan/laba-rugi')->assertForbidden();

        $this->masukSebagai(Role::STAFF_PRODUKSI);
        $this->getJson('/api/v1/keuangan/laba-rugi')->assertForbidden();
    }
}
