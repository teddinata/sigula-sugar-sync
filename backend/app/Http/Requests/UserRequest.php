<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var User|null $pengguna */
        $pengguna = $this->route('pengguna');
        $baru = $this->isMethod('POST');

        return [
            'nama' => [$baru ? 'required' : 'sometimes', 'string', 'max:120'],
            'email' => [
                $baru ? 'required' : 'sometimes',
                'email',
                'max:150',
                Rule::unique('users', 'email')->ignore($pengguna?->getKey()),
            ],
            // Wajib saat membuat akun; saat mengubah, kosong berarti password tetap.
            'password' => [$baru ? 'required' : 'nullable', 'string', 'min:8', 'max:100'],
            'role' => [$baru ? 'required' : 'sometimes', Rule::in(Role::acceptedInputs())],
            'aktif' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.unique' => 'Email ini sudah dipakai akun lain.',
            'password.min' => 'Password minimal 8 karakter.',
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $data = [];

        if ($this->has('nama')) {
            $data['name'] = trim((string) $this->input('nama'));
        }

        if ($this->has('email')) {
            $data['email'] = mb_strtolower(trim((string) $this->input('email')));
        }

        if ($this->filled('password')) {
            $data['password'] = (string) $this->input('password');
        }

        if ($this->has('role')) {
            $data['role'] = Role::fromAny($this->input('role'));
        }

        if ($this->has('aktif')) {
            $data['aktif'] = $this->boolean('aktif');
        }

        return $data;
    }
}
