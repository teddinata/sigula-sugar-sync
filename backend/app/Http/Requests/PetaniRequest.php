<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\StatusPenderes;
use App\Models\Petani;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PetaniRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var Petani|null $petani */
        $petani = $this->route('petani');

        return [
            'nama' => ['required', 'string', 'max:120'],
            // Kode lahan sekaligus nomor member — kosong berarti Non-Member.
            'kodeLahan' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('petani', 'kode_lahan')->ignore($petani?->getKey())->whereNull('deleted_at'),
            ],
            'rtRw' => ['nullable', 'string', 'max:20'],
            'kontak' => ['nullable', 'string', 'max:40'],
            'alamat' => ['nullable', 'string', 'max:500'],
            'aktif' => ['nullable', 'boolean'],
            // Status penderes/pemilik lahan — boleh lebih dari satu, mis. PMS + PLMD.
            'statusPenderes' => ['nullable', 'array', 'max:7'],
            'statusPenderes.*' => [Rule::in(StatusPenderes::acceptedInputs())],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'kodeLahan.unique' => 'Kode lahan ini sudah dipakai petani lain.',
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $payload = [
            'nama' => trim((string) $this->input('nama')),
            'kode_lahan' => $this->input('kodeLahan') ?: null,
            'rt_rw' => $this->input('rtRw') ?: null,
            'kontak' => $this->input('kontak') ?: null,
            'alamat' => $this->input('alamat') ?: null,
        ];

        if ($this->has('aktif')) {
            $payload['aktif'] = $this->boolean('aktif');
        }

        if ($this->has('statusPenderes')) {
            $payload['status_penderes'] = array_map(
                static fn (string $kode): StatusPenderes => StatusPenderes::fromAny($kode),
                (array) $this->input('statusPenderes', []),
            );
        }

        return $payload;
    }
}
