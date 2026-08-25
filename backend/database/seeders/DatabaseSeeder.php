<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            // Data asli client (195 petani Batuanten, 5 pengepul, 33 karyawan).
            // Dijalankan SEBELUM MasterSeeder: MasterSeeder melewati petani dan
            // karyawan contoh bila tabelnya sudah terisi, jadi data sungguhan
            // tidak tercampur dengan data pengembangan.
            DataClientSeeder::class,
            MasterSeeder::class,
        ]);

        // Data transaksi demo (±6 bulan) hanya untuk lingkungan non-production.
        // Matikan dengan SIGULA_SEED_DEMO=false pada .env server.
        if (! app()->isProduction() && filter_var(config('sigula.seed_demo'), FILTER_VALIDATE_BOOL)) {
            $this->call(DemoTransaksiSeeder::class);
        }
    }
}
