<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Mengganti password akun SIGULA dari server.
 *
 * Password diminta secara interaktif (tidak diketik sebagai argumen) supaya
 * tidak tersimpan di shell history maupun terlihat di daftar proses.
 *
 *   php artisan sigula:ganti-password owner@nirasarimurni.com
 *   php artisan sigula:ganti-password --semua
 */
class GantiPassword extends Command
{
    protected $signature = 'sigula:ganti-password
        {email? : Email akun yang mau diganti}
        {--semua : Ganti password SEMUA akun sekaligus}';

    protected $description = 'Mengganti password akun SIGULA (password diketik interaktif)';

    public function handle(): int
    {
        $semua = (bool) $this->option('semua');
        $email = $this->argument('email');

        if (! $semua && blank($email)) {
            $email = $this->anticipate(
                'Email akun yang mau diganti',
                User::query()->pluck('email')->all(),
            );
        }

        $akun = $semua
            ? User::query()->get()
            : User::query()->where('email', $email)->get();

        if ($akun->isEmpty()) {
            $this->error($semua ? 'Belum ada akun di database.' : "Akun {$email} tidak ditemukan.");

            return self::FAILURE;
        }

        if ($semua) {
            $this->warn(sprintf('Password %d akun berikut akan diganti:', $akun->count()));
            $this->line('  '.$akun->pluck('email')->implode(', '));

            if (! $this->confirm('Lanjutkan?', false)) {
                $this->info('Dibatalkan.');

                return self::SUCCESS;
            }
        }

        $password = $this->secret('Password baru');
        $ulangi = $this->secret('Ketik ulang password baru');

        if ($password !== $ulangi) {
            $this->error('Kedua password tidak sama. Tidak ada yang diubah.');

            return self::FAILURE;
        }

        if (mb_strlen((string) $password) < 8) {
            $this->error('Password minimal 8 karakter. Tidak ada yang diubah.');

            return self::FAILURE;
        }

        foreach ($akun as $user) {
            $user->forceFill(['password' => Hash::make($password)])->save();

            // Token Sanctum yang lama harus ikut mati, kalau tidak sesi yang
            // sudah login tetap bisa dipakai dengan password lama.
            $user->tokens()->delete();
        }

        $this->info(sprintf('Password %d akun diperbarui dan seluruh sesi login lama dicabut.', $akun->count()));

        return self::SUCCESS;
    }
}
