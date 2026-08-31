<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pengelolaan akun pengguna oleh Owner (yang sekaligus berperan superadmin).
 *
 * Dua pengaman utama: Owner tidak boleh mengunci dirinya sendiri keluar dari
 * sistem, dan sistem tidak boleh kehabisan Owner aktif — kalau itu terjadi,
 * tidak ada seorang pun yang bisa mengembalikan hak akses siapa pun.
 */
final class UserService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function simpan(array $data, ?User $pelaku = null): User
    {
        return DB::transaction(function () use ($data, $pelaku): User {
            /** @var Role $role */
            $role = $data['role'];

            $user = new User;

            // forceFill: email_verified_at sengaja tidak fillable pada model.
            $user->forceFill([
                'name' => $data['name'],
                'email' => $data['email'],
                // Cast `hashed` pada model User yang meng-hash-nya.
                'password' => $data['password'],
                'role' => $role->value,
                'aktif' => $data['aktif'] ?? true,
                'email_verified_at' => now(),
            ])->save();

            $this->audit->catat(
                'user.simpan',
                sprintf('Akun %s (%s) dibuat sebagai %s', $user->name, $user->email, $role->label()),
                $user,
                ['role' => $role->value],
                $pelaku,
            );

            return $user;
        });
    }

    /** @param array<string, mixed> $data */
    public function ubah(User $user, array $data, ?User $pelaku = null): User
    {
        return DB::transaction(function () use ($user, $data, $pelaku): User {
            $sebelum = ['role' => $user->role->value, 'aktif' => $user->aktif];
            $sendiri = $pelaku !== null && (int) $pelaku->getKey() === (int) $user->getKey();

            /** @var Role|null $roleBaru */
            $roleBaru = $data['role'] ?? null;
            $aktifBaru = $data['aktif'] ?? $user->aktif;

            // Mengubah role atau menonaktifkan diri sendiri = kehilangan akses
            // seketika, dan belum tentu ada Owner lain yang bisa memulihkannya.
            if ($sendiri && $roleBaru !== null && $roleBaru !== $user->role) {
                throw BusinessRuleException::untukField('role', 'Kamu tidak bisa mengubah role akunmu sendiri.');
            }

            if ($sendiri && $aktifBaru === false) {
                throw BusinessRuleException::untukField('aktif', 'Kamu tidak bisa menonaktifkan akunmu sendiri.');
            }

            $this->pastikanMasihAdaOwner($user, $roleBaru, (bool) $aktifBaru);

            $atribut = array_filter([
                'name' => $data['name'] ?? null,
                'email' => $data['email'] ?? null,
            ], static fn ($v): bool => $v !== null);

            if ($roleBaru !== null) {
                $atribut['role'] = $roleBaru->value;
            }

            if (array_key_exists('aktif', $data)) {
                $atribut['aktif'] = (bool) $data['aktif'];
            }

            if (isset($data['password'])) {
                $atribut['password'] = $data['password'];
            }

            $user->forceFill($atribut)->save();

            // Password atau role berubah = sesi lama tidak boleh dipakai lagi.
            if (isset($data['password']) || ($roleBaru !== null && $roleBaru->value !== $sebelum['role'])) {
                $user->tokens()->delete();
            }

            $this->audit->catat(
                'user.ubah',
                sprintf('Akun %s (%s) diperbarui', $user->name, $user->email),
                $user,
                [
                    'sebelum' => $sebelum,
                    'sesudah' => ['role' => $user->role->value, 'aktif' => $user->aktif],
                    'passwordDiubah' => isset($data['password']),
                ],
                $pelaku,
            );

            return $user->refresh();
        });
    }

    public function hapus(User $user, ?User $pelaku = null): void
    {
        if ($pelaku !== null && (int) $pelaku->getKey() === (int) $user->getKey()) {
            throw new BusinessRuleException('Kamu tidak bisa menghapus akunmu sendiri.');
        }

        $this->pastikanMasihAdaOwner($user, null, false);

        DB::transaction(function () use ($user, $pelaku): void {
            $keterangan = sprintf('Akun %s (%s) dihapus', $user->name, $user->email);

            // Riwayat audit-nya tetap tersimpan: foreign key audit_logs.user_id
            // memakai nullOnDelete, jadi barisnya tidak ikut terhapus.
            $user->tokens()->delete();
            $user->delete();

            $this->audit->catat('user.hapus', $keterangan, null, [], $pelaku);
        });
    }

    /**
     * Menolak perubahan yang membuat sistem kehabisan Owner aktif.
     */
    private function pastikanMasihAdaOwner(User $user, ?Role $roleBaru, bool $aktifBaru): void
    {
        $masihOwnerAktif = ($roleBaru ?? $user->role) === Role::OWNER && $aktifBaru;

        if ($user->role !== Role::OWNER || $masihOwnerAktif) {
            return;
        }

        $ownerLain = User::query()
            ->where('role', Role::OWNER->value)
            ->where('aktif', true)
            ->whereKeyNot($user->getKey())
            ->exists();

        if (! $ownerLain) {
            throw new BusinessRuleException(
                'Harus selalu ada minimal satu Owner aktif — jadikan akun lain Owner dulu sebelum mengubah yang ini.',
            );
        }
    }
}
