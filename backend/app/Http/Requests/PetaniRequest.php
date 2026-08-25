<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\StatusPenderes;
use App\Enums\StatusPetani;
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
            'status' => ['required', Rule::in(StatusPetani::acceptedInputs())],
            'nomorMember' => [
                'nullable',
                'string',
                'regex:/^\d{3}$/',
                Rule::unique('petani', 'nomor_member')->ignore($petani?->getKey())->whereNull('deleted_at'),
            ],
            'kontak' => ['nullable', 'string', 'max:40'],
            // Status penderes/pemilik lahan — boleh lebih dari satu, mis. PMS + PLMD.
            'statusPenderes' => ['nullable', 'array', 'max:7'],
            'statusPenderes.*' => [Rule::in(StatusPenderes::acceptedInputs())],
            'kodeLahan' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('petani', 'kode_lahan')->ignore($petani?->getKey())->whereNull('deleted_at'),
            ],
            'rtRw' => ['nullable', 'string', 'max:20'],
            'alamat' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nomorMember.regex' => 'Nomor member harus 3 digit angka, contoh 231.',
            'nomorMember.unique' => 'Nomor member sudah dipakai petani lain.',
        ];
    }

    /**
     * @return array{nama: string, status: StatusPetani, nomor_member: string|null, kontak: string|null, alamat: string|null}
     */
    public function payload(): array
    {
        $payload = [
            'nama' => trim((string) $this->input('nama')),
            'status' => StatusPetani::fromAny($this->input('status')),
            'nomor_member' => $this->input('nomorMember') ?: null,
            'kontak' => $this->input('kontak') ?: null,
            'kode_lahan' => $this->input('kodeLahan') ?: null,
            'rt_rw' => $this->input('rtRw') ?: null,
            'alamat' => $this->input('alamat') ?: null,
        ];

        if ($this->has('statusPenderes')) {
            $payload['status_penderes'] = array_map(
                static fn (string $kode): StatusPenderes => StatusPenderes::fromAny($kode),
                (array) $this->input('statusPenderes', []),
            );
        }

        return $payload;
    }
}
