<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Pembelian;
use App\Support\Periode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Pembelian */
class PembelianResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'nomorKwitansi' => $this->nomor_kwitansi,
            'tanggal' => $this->tanggal->toDateString(),
            'tanggalLabel' => Periode::tanggalIndonesia($this->tanggal),
            'petaniId' => (string) $this->petani_id,
            'namaPetani' => $this->whenLoaded('petani', fn (): string => $this->petani->nama, null),
            'grade' => $this->grade->label(),
            'gradeKode' => $this->grade->value,
            'kg' => (float) $this->kilogram,
            'harga' => (float) $this->harga_per_kg,
            'total' => (float) $this->total,
            // Nilai kg x harga sebelum dibulatkan ke kelipatan 500/1.000.
            'totalSebelumBulat' => $this->total_sebelum_bulat === null
                ? null
                : (float) $this->total_sebelum_bulat,
            'pengepulId' => $this->pengepul_id === null ? null : (string) $this->pengepul_id,
            'pengepul' => $this->whenLoaded('pengepul', fn () => $this->pengepul === null ? null : [
                'id' => (string) $this->pengepul->id,
                'nama' => $this->pengepul->nama,
            ], null),
            'statusPembayaran' => $this->status_pembayaran->label(),
            'statusPembayaranKode' => $this->status_pembayaran->value,
            'catatan' => $this->catatan,
            'dibuatPada' => $this->created_at?->toIso8601String(),
        ];
    }
}
