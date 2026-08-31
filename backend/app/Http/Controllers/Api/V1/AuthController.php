<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Login berbasis token Sanctum (frontend menyimpan token, bukan session). */
    public function login(LoginRequest $request): JsonResponse
    {
        /** @var User|null $user */
        $user = User::query()->where('email', $request->string('email')->lower()->value())->first();

        if ($user === null || ! Hash::check((string) $request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password salah.'],
            ]);
        }

        if (! $user->aktif) {
            throw ValidationException::withMessages([
                'email' => ['Akun ini sudah dinonaktifkan. Hubungi owner.'],
            ]);
        }

        $token = $user->createToken($request->namaPerangkat());

        // Siapa masuk dari mana ikut tercatat: audit trail tidak lengkap kalau
        // hanya merekam perubahan data tanpa tahu kapan sesinya dimulai.
        $this->audit->catat(
            'auth.login',
            sprintf('%s (%s) masuk ke sistem', $user->name, $user->role->label()),
            $user,
            ['perangkat' => $request->namaPerangkat()],
            $user,
        );

        return response()->json([
            'message' => 'Berhasil masuk.',
            'data' => [
                'token' => $token->plainTextToken,
                'user' => new UserResource($user),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->audit->catat('auth.logout', sprintf('%s keluar dari sistem', $user->name), $user, [], $user);

        $user->currentAccessToken()->delete();

        return response()->json(['message' => 'Berhasil keluar.']);
    }
}
