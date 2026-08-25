<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\StatusPenderes;
use App\Models\Petani;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Petani */
class PetaniResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'nama' => $this->nama,
            'status' => $this->status->label(),
            'statusKode' => $this->status->value,
            'nomorMember' => $this->nomor_member ?? '',
            'labelMember' => $this->nomor_member ? 'Petani '.$this->nomor_member : '',
            'kontak' => $this->kontak ?? '',
            'kodeLahan' => $this->kode_lahan,
            'rtRw' => $this->rt_rw,
            // Bisa lebih dari satu status, mis. [PMS, PLMD].
            'statusPenderes' => $this->whenLoaded('statusPenderes', fn (): array => array_map(
                static fn (StatusPenderes $s): array => [
                    'kode' => $s->value,
                    'label' => $s->label(),
                    'keterangan' => $s->keterangan(),
                ],
                $this->daftarStatusPenderes(),
            )),
            'alamat' => $this->alamat ?? '',
            'totalTransaksi' => $this->whenCounted('pembelian', fn (): int => (int) $this->pembelian_count, 0),
            'totalNilai' => $this->when(
                $this->pembelian_sum_total !== null,
                fn (): float => (float) $this->pembelian_sum_total,
                0.0
            ),
        ];
    }
}
