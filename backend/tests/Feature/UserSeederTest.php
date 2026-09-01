<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserSeederTest extends TestCase
{
    public function test_membuat_empat_akun_bawaan(): void
    {
        $this->seed(UserSeeder::class);

        $this->assertSame(4, User::query()->count());
        $this->assertSame(
            ['owner', 'admin', 'staff_gudang', 'staff_produksi'],
            User::query()->orderBy('id')->pluck('role')->map(fn ($r) => $r->value)->all(),
        );
    }

    public function test_akun_baru_memakai_password_default(): void
    {
        config(['sigula.default_password' => 'PasswordAwal123']);
        $this->seed(UserSeeder::class);

        $admin = User::query()->where('email', 'admin@nirasarimurni.com')->firstOrFail();
        $this->assertTrue(Hash::check('PasswordAwal123', $admin->password));
        $this->assertSame(Role::ADMIN, $admin->role);
    }

    /**
     * Seeder dijalankan ulang tiap ada akun bawaan baru; kalau ia menimpa
     * password, seluruh akun kembali ke default tanpa disadari.
     */
    public function test_menjalankan_ulang_tidak_mereset_password_akun_lama(): void
    {
        config(['sigula.default_password' => 'PasswordAwal123']);
        $this->seed(UserSeeder::class);

        $owner = User::query()->where('email', 'owner@nirasarimurni.com')->firstOrFail();
        $owner->forceFill(['password' => 'PasswordGantiSendiri456'])->save();

        $this->seed(UserSeeder::class);

        $this->assertTrue(Hash::check('PasswordGantiSendiri456', $owner->refresh()->password));
        $this->assertFalse(Hash::check('PasswordAwal123', $owner->password));
        $this->assertSame(4, User::query()->count());
    }

    public function test_akun_bawaan_yang_belum_ada_tetap_dibuat_saat_dijalankan_ulang(): void
    {
        $this->seed(UserSeeder::class);
        User::query()->where('email', 'admin@nirasarimurni.com')->delete();

        $this->seed(UserSeeder::class);

        $this->assertSame(1, User::query()->where('email', 'admin@nirasarimurni.com')->count());
    }
}
