<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $akun = [
            ['name' => 'Shoffal (Owner)', 'email' => 'owner@nirasarimurni.com', 'role' => Role::OWNER],
            ['name' => 'Admin', 'email' => 'admin@nirasarimurni.com', 'role' => Role::ADMIN],
            ['name' => 'Staff Gudang', 'email' => 'gudang@nirasarimurni.com', 'role' => Role::STAFF_GUDANG],
            ['name' => 'Staff Produksi', 'email' => 'produksi@nirasarimurni.com', 'role' => Role::STAFF_PRODUKSI],
        ];

        foreach ($akun as $data) {
            $user = User::query()->firstWhere('email', $data['email']);

            if ($user !== null) {
                // Akun yang sudah ada TIDAK disentuh passwordnya. Seeder ini
                // dijalankan ulang tiap kali ada akun bawaan baru, dan menimpa
                // password di sini akan mengembalikan seluruh akun ke password
                // default — termasuk yang sudah diganti owner.
                continue;
            }

            User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => config('sigula.default_password'),
                'role' => $data['role']->value,
                'aktif' => true,
            ])->forceFill(['email_verified_at' => now()])->save();
        }
    }
}
