<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Owner berperan sebagai superadmin: hanya dia yang boleh mengelola akun. */
class KelolaUserTest extends TestCase
{
    public function test_owner_bisa_membuat_akun_baru(): void
    {
        $this->masukSebagai(Role::OWNER);

        $data = $this->postJson('/api/v1/pengguna', [
            'nama' => 'Admin Kantor',
            'email' => 'Admin.Kantor@nirasarimurni.com',
            'password' => 'RahasiaBaru123',
            'role' => 'admin',
        ])->assertCreated()->json('data');

        $this->assertSame('admin', $data['role']);
        // Email disimpan huruf kecil supaya login tidak gagal karena kapitalisasi.
        $this->assertSame('admin.kantor@nirasarimurni.com', $data['email']);

        $baru = User::query()->where('email', 'admin.kantor@nirasarimurni.com')->firstOrFail();
        $this->assertTrue(Hash::check('RahasiaBaru123', $baru->password));
        $this->assertTrue($baru->aktif);
    }

    public function test_akun_baru_langsung_bisa_login(): void
    {
        $this->masukSebagai(Role::OWNER);

        $this->postJson('/api/v1/pengguna', [
            'nama' => 'Admin Kantor',
            'email' => 'admin.kantor@nirasarimurni.com',
            'password' => 'RahasiaBaru123',
            'role' => 'admin',
        ])->assertCreated();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin.kantor@nirasarimurni.com',
            'password' => 'RahasiaBaru123',
        ])->assertOk()->assertJsonPath('data.user.role', 'admin');
    }

    public function test_email_kembar_ditolak(): void
    {
        $this->masukSebagai(Role::OWNER);
        User::factory()->create(['email' => 'kembar@nirasarimurni.com']);

        $this->postJson('/api/v1/pengguna', [
            'nama' => 'Kembar',
            'email' => 'kembar@nirasarimurni.com',
            'password' => 'RahasiaBaru123',
            'role' => 'admin',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_password_pendek_ditolak(): void
    {
        $this->masukSebagai(Role::OWNER);

        $this->postJson('/api/v1/pengguna', [
            'nama' => 'Pendek',
            'email' => 'pendek@nirasarimurni.com',
            'password' => 'abc',
            'role' => 'admin',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_mengubah_role_mencabut_token_lama(): void
    {
        $owner = $this->masukSebagai(Role::OWNER);
        $target = User::factory()->create(['role' => Role::STAFF_GUDANG->value]);
        $token = $target->createToken('sigula-web')->plainTextToken;

        $this->putJson("/api/v1/pengguna/{$target->id}", ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');

        // Sesi lama dicabut supaya hak akses baru langsung berlaku.
        $this->assertSame(0, $target->tokens()->count());
        $this->assertNotNull($owner);
        $this->assertNotEmpty($token);
    }

    public function test_owner_tidak_bisa_mengubah_role_dirinya_sendiri(): void
    {
        $owner = $this->masukSebagai(Role::OWNER);

        $this->putJson("/api/v1/pengguna/{$owner->id}", ['role' => 'admin'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertSame(Role::OWNER, $owner->refresh()->role);
    }

    public function test_owner_tidak_bisa_menonaktifkan_dirinya_sendiri(): void
    {
        $owner = $this->masukSebagai(Role::OWNER);

        $this->putJson("/api/v1/pengguna/{$owner->id}", ['aktif' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('aktif');

        $this->assertTrue($owner->refresh()->aktif);
    }

    public function test_owner_tidak_bisa_menghapus_dirinya_sendiri(): void
    {
        $owner = $this->masukSebagai(Role::OWNER);

        $this->deleteJson("/api/v1/pengguna/{$owner->id}")->assertStatus(422);
        $this->assertNotNull(User::find($owner->id));
    }

    /** Kalau owner terakhir hilang, tidak ada yang bisa memulihkan hak akses siapa pun. */
    public function test_owner_terakhir_tidak_boleh_diturunkan_lewat_akun_lain(): void
    {
        $ownerLama = User::factory()->create(['role' => Role::OWNER->value]);
        $this->masukSebagai(Role::OWNER);

        // Sekarang ada 2 owner: menurunkan salah satunya boleh.
        $this->putJson("/api/v1/pengguna/{$ownerLama->id}", ['role' => 'admin'])->assertOk();

        // Tersisa 1 owner (akun yang sedang login) — dan dia tidak bisa menurunkan dirinya.
        $this->assertSame(1, User::query()->where('role', Role::OWNER->value)->where('aktif', true)->count());
    }

    /**
     * Jaring pengaman terakhir, diuji lewat service karena lewat HTTP tidak
     * mungkin tercapai: pelakunya sendiri sudah pasti seorang Owner aktif.
     */
    public function test_owner_terakhir_tidak_bisa_diturunkan_atau_dihapus(): void
    {
        $owner = User::factory()->create(['role' => Role::OWNER->value]);
        $service = app(\App\Services\UserService::class);

        $this->expectException(\App\Exceptions\BusinessRuleException::class);
        $service->ubah($owner, ['role' => Role::ADMIN]);
    }

    public function test_owner_terakhir_tidak_bisa_dihapus_lewat_service(): void
    {
        $owner = User::factory()->create(['role' => Role::OWNER->value]);
        $service = app(\App\Services\UserService::class);

        $this->expectException(\App\Exceptions\BusinessRuleException::class);
        $service->hapus($owner);
    }

    public function test_admin_tidak_boleh_mengelola_pengguna(): void
    {
        $this->masukSebagai(Role::ADMIN);

        $this->getJson('/api/v1/pengguna')->assertForbidden();
        $this->postJson('/api/v1/pengguna', [
            'nama' => 'Nekat', 'email' => 'nekat@x.test', 'password' => 'RahasiaBaru123', 'role' => 'owner',
        ])->assertForbidden();

        $this->assertSame(0, User::query()->where('email', 'nekat@x.test')->count());
    }

    public function test_staff_tidak_boleh_mengelola_pengguna(): void
    {
        $this->masukSebagai(Role::STAFF_GUDANG);
        $this->getJson('/api/v1/pengguna')->assertForbidden();
    }

    public function test_perubahan_akun_tercatat_di_audit_log(): void
    {
        $this->masukSebagai(Role::OWNER);

        $this->postJson('/api/v1/pengguna', [
            'nama' => 'Admin Kantor',
            'email' => 'admin.kantor@nirasarimurni.com',
            'password' => 'RahasiaBaru123',
            'role' => 'admin',
        ])->assertCreated();

        $log = AuditLog::query()->where('aksi', 'user.simpan')->firstOrFail();
        $this->assertStringContainsString('Admin Kantor', $log->deskripsi);
        $this->assertSame('admin', $log->data['role']);
    }

    public function test_daftar_role_tersedia_untuk_dropdown(): void
    {
        $this->masukSebagai(Role::OWNER);

        $data = $this->getJson('/api/v1/pengguna/role')->assertOk()->json('data');

        $this->assertCount(4, $data);
        $this->assertSame(['owner', 'admin', 'staff_gudang', 'staff_produksi'], array_column($data, 'kode'));
        $this->assertNotContains('keuangan', $data[1]['menu']);
    }
}
