<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Grade;
use App\Enums\StatusPembayaran;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PembelianRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date'],
            'petaniId' => ['required', Rule::exists('petani', 'id')->whereNull('deleted_at')],
            'pengepulId' => ['nullable', Rule::exists('pengepul', 'id')->whereNull('deleted_at')],
            'grade' => ['required', Rule::in(Grade::acceptedInputs())],
            'kg' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            // Dikosongkan berarti pakai harga master yang berlaku pada tanggal transaksi.
            'harga' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'statusPembayaran' => ['nullable', Rule::in(StatusPembayaran::acceptedInputs())],
            'catatan' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'kg.gt' => 'Kilogram harus lebih dari 0.',
            'harga.gt' => 'Harga per kg harus lebih dari 0.',
            'petaniId.exists' => 'Petani tidak ditemukan.',
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'tanggal' => (string) $this->input('tanggal'),
            'petani_id' => $this->input('petaniId'),
            'pengepul_id' => $this->input('pengepulId') ?: null,
            'grade' => Grade::fromAny($this->input('grade')),
            'kilogram' => (float) $this->input('kg'),
            'harga_per_kg' => $this->filled('harga') ? (float) $this->input('harga') : null,
            'status_pembayaran' => $this->filled('statusPembayaran')
                ? StatusPembayaran::fromAny($this->input('statusPembayaran'))
                : StatusPembayaran::LUNAS,
            'catatan' => $this->input('catatan') ?: null,
        ];
    }
}
