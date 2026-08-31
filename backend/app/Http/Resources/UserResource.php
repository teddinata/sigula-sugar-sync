<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'nama' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'roleLabel' => $this->role->label(),
            'aktif' => (bool) $this->aktif,
            // Dipakai frontend untuk menyusun sidebar & menyembunyikan aksi terlarang.
            'menu' => $this->role->menu(),
            'abilities' => $this->role->abilities(),
            // Menandai baris "diri sendiri" di menu Pengguna, supaya UI bisa
            // mencegah owner mengunci dirinya sendiri keluar.
            'diriSendiri' => $request->user()?->getKey() === $this->id,
            'dibuatPada' => $this->created_at?->toIso8601String(),
        ];
    }
}
