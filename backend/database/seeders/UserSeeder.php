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
            User::query()->updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => config('sigula.default_password'),
                    'role' => $data['role']->value,
                    'aktif' => true,
                    'email_verified_at' => now(),
                ],
            );
        }
    }
}
