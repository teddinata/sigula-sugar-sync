<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SesiTungku;
use App\Support\Periode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SesiTungku */
class SesiTungkuResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'tanggal' => $this->tanggal->toDateString(),
            'tanggalLabel' => Periode::tanggalIndonesia($this->tanggal),
            'kodeTungku' => $this->kode_tungku,
            'grade' => $this->grade->label(),
            'gradeKode' => $this->grade->value,
            // Total seluruh grade; rinciannya ada di `bahan`.
            'kgBahan' => (float) $this->kg_bahan_mentah,
            'bahan' => $this->whenLoaded('bahan', fn (): array => $this->bahan
                ->map(fn ($baris): array => [
                    'grade' => $baris->grade->label(),
                    'gradeKode' => $baris->grade->value,
                    'kg' => (float) $baris->kg,
                ])->all()),
            // Tungku bisa dikerjakan 1 atau 2 orang, jadi panjang array mengikuti.
            'karyawanIds' => array_values(array_filter([
                (string) $this->karyawan_1_id,
                $this->karyawan_2_id === null ? null : (string) $this->karyawan_2_id,
            ])),
            'karyawan' => $this->when(
                $this->relationLoaded('karyawan1'),
                fn (): array => array_values(array_filter([
                    ['id' => (string) $this->karyawan_1_id, 'nama' => $this->karyawan1?->nama],
                    $this->karyawan_2_id === null ? null : [
                        'id' => (string) $this->karyawan_2_id,
                        'nama' => $this->relationLoaded('karyawan2') ? $this->karyawan2?->nama : null,
                    ],
                ]))
            ),
            'kgKristal' => $this->kg_kristal_total === null ? null : (float) $this->kg_kristal_total,
            'kgBrondol' => $this->kg_brondol_total === null ? null : (float) $this->kg_brondol_total,
            'rendemen' => $this->rendemen === null ? null : (float) $this->rendemen,
            'status' => $this->status->label(),
            'statusKode' => $this->status->value,
            'selesaiPada' => $this->selesai_pada?->toIso8601String(),
            'catatan' => $this->catatan,
            // Porsi hasil pembagian rata ke 2 karyawan (dipakai penggajian).
            'porsiKaryawan' => $this->whenLoaded('porsiKaryawan', fn (): array => $this->porsiKaryawan
                ->map(fn ($porsi): array => [
                    'karyawanId' => (string) $porsi->karyawan_id,
                    'kgBahan' => (float) $porsi->kg_bahan_mentah_porsi,
                    'kgKristal' => (float) $porsi->kg_kristal_porsi,
                    'kgBrondol' => (float) $porsi->kg_brondol_porsi,
                ])->all()),
        ];
    }
}
