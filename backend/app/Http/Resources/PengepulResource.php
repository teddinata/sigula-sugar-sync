<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Pengepul;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Pengepul */
class PengepulResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'nama' => $this->nama,
            'kontak' => $this->kontak ?? '',
            'alamat' => $this->alamat ?? '',
            'aktif' => (bool) $this->aktif,
            'totalTransaksi' => $this->whenCounted('pembelian'),
        ];
    }
}
