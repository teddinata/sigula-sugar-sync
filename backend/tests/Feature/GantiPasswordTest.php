<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GantiPasswordTest extends TestCase
{
    public function test_mengganti_password_satu_akun(): void
    {
        $user = User::factory()->create(['email' => 'owner@nirasarimurni.com']);

        $this->artisan('sigula:ganti-password', ['email' => 'owner@nirasarimurni.com'])
            ->expectsQuestion('Password baru', 'RahasiaBaru123')
            ->expectsQuestion('Ketik ulang password baru', 'RahasiaBaru123')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('RahasiaBaru123', $user->refresh()->password));
    }

    public function test_token_lama_dicabut_supaya_sesi_lama_tidak_bisa_dipakai(): void
    {
        $user = User::factory()->create(['email' => 'owner@nirasarimurni.com']);
        $token = $user->createToken('sigula-web')->plainTextToken;

        $this->artisan('sigula:ganti-password', ['email' => 'owner@nirasarimurni.com'])
            ->expectsQuestion('Password baru', 'RahasiaBaru123')
            ->expectsQuestion('Ketik ulang password baru', 'RahasiaBaru123')
            ->assertSuccessful();

        $this->assertSame(0, $user->tokens()->count());

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_password_yang_tidak_cocok_ditolak(): void
    {
        $user = User::factory()->create(['email' => 'owner@nirasarimurni.com']);
        $lama = $user->password;

        $this->artisan('sigula:ganti-password', ['email' => 'owner@nirasarimurni.com'])
            ->expectsQuestion('Password baru', 'RahasiaBaru123')
            ->expectsQuestion('Ketik ulang password baru', 'SalahKetik123')
            ->assertFailed();

        $this->assertSame($lama, $user->refresh()->password);
    }

    public function test_password_terlalu_pendek_ditolak(): void
    {
        $user = User::factory()->create(['email' => 'owner@nirasarimurni.com']);
        $lama = $user->password;

        $this->artisan('sigula:ganti-password', ['email' => 'owner@nirasarimurni.com'])
            ->expectsQuestion('Password baru', 'pendek')
            ->expectsQuestion('Ketik ulang password baru', 'pendek')
            ->assertFailed();

        $this->assertSame($lama, $user->refresh()->password);
    }

    public function test_akun_tidak_dikenal_ditolak(): void
    {
        $this->artisan('sigula:ganti-password', ['email' => 'bukan@siapa.test'])->assertFailed();
    }

    public function test_opsi_semua_mengganti_seluruh_akun_setelah_dikonfirmasi(): void
    {
        $owner = User::factory()->create(['role' => Role::OWNER->value]);
        $gudang = User::factory()->create(['role' => Role::STAFF_GUDANG->value]);

        $this->artisan('sigula:ganti-password', ['--semua' => true])
            ->expectsConfirmation('Lanjutkan?', 'yes')
            ->expectsQuestion('Password baru', 'RahasiaBaru123')
            ->expectsQuestion('Ketik ulang password baru', 'RahasiaBaru123')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('RahasiaBaru123', $owner->refresh()->password));
        $this->assertTrue(Hash::check('RahasiaBaru123', $gudang->refresh()->password));
    }

    public function test_opsi_semua_bisa_dibatalkan(): void
    {
        $user = User::factory()->create();
        $lama = $user->password;

        $this->artisan('sigula:ganti-password', ['--semua' => true])
            ->expectsConfirmation('Lanjutkan?', 'no')
            ->assertSuccessful();

        $this->assertSame($lama, $user->refresh()->password);
    }
}
