<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Grade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MulaiSesiTungkuRequest extends FormRequest
{
    /**
     * Grade disamakan dulu ke nilai kanonik ("NS 1" dan "ns1" jadi sama), supaya
     * aturan `distinct` bisa menangkap grade ganda dan pesannya menunjuk ke
     * baris yang salah — bukan gagal belakangan di service.
     */
    protected function prepareForValidation(): void
    {
        $bahan = $this->input('bahan');

        if (! is_array($bahan)) {
            return;
        }

        $this->merge(['bahan' => array_map(static function (mixed $baris): mixed {
            if (is_array($baris) && isset($baris['grade'])) {
                $baris['grade'] = Grade::tryFromAny($baris['grade'])?->value ?? $baris['grade'];
            }

            return $baris;
        }, $bahan)]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $karyawanAda = Rule::exists('karyawan', 'id')->whereNull('deleted_at');

        return [
            'tanggal' => ['required', 'date'],
            'kodeTungku' => ['nullable', 'string', 'max:30'],

            // Bentuk baru: satu tungku boleh dimasak dari beberapa grade sekaligus.
            'bahan' => ['required_without_all:grade,kgBahan', 'array', 'min:1', 'max:'.count(Grade::cases())],
            'bahan.*.grade' => ['required', Rule::in(Grade::acceptedInputs()), 'distinct:ignore_case'],
            'bahan.*.kg' => ['required', 'numeric', 'gt:0', 'max:9999999', 'decimal:0,2'],

            // Bentuk lama (satu grade) tetap diterima supaya klien versi sebelumnya jalan.
            'grade' => ['required_without:bahan', Rule::in(Grade::acceptedInputs())],
            'kgBahan' => ['required_without:bahan', 'numeric', 'gt:0', 'max:9999999', 'decimal:0,2'],

            'karyawan1Id' => ['required', $karyawanAda],
            // Boleh 1 orang saja: ada tungku yang dikerjakan sendirian.
            'karyawan2Id' => ['nullable', 'different:karyawan1Id', $karyawanAda],
            'catatan' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'bahan.required_without_all' => 'Bahan mentah wajib diisi minimal satu grade.',
            'bahan.*.grade.distinct' => 'Grade bahan tidak boleh dipilih dua kali dalam satu tungku.',
            'bahan.*.kg.gt' => 'Kg bahan mentah harus lebih dari 0.',
            'bahan.*.kg.decimal' => 'Kg bahan mentah maksimal 2 angka di belakang koma.',
            'kgBahan.gt' => 'Kg bahan mentah harus lebih dari 0.',
            'kgBahan.decimal' => 'Kg bahan mentah maksimal 2 angka di belakang koma.',
            'karyawan1Id.required' => 'Karyawan 1 wajib dipilih.',
            'karyawan2Id.different' => 'Karyawan 1 dan Karyawan 2 tidak boleh orang yang sama.',
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'tanggal' => (string) $this->input('tanggal'),
            'kode_tungku' => $this->input('kodeTungku') ?: null,
            'bahan' => $this->bahan(),
            'karyawan_1_id' => $this->input('karyawan1Id'),
            'karyawan_2_id' => $this->input('karyawan2Id') ?: null,
            'catatan' => $this->input('catatan') ?: null,
        ];
    }

    /** @return array<int, array{grade: Grade, kg: float}> */
    private function bahan(): array
    {
        $baris = $this->input('bahan');

        if (! is_array($baris) || $baris === []) {
            $baris = [['grade' => $this->input('grade'), 'kg' => $this->input('kgBahan')]];
        }

        return array_values(array_map(static fn (array $b): array => [
            'grade' => Grade::fromAny($b['grade']),
            'kg' => (float) $b['kg'],
        ], $baris));
    }
}
