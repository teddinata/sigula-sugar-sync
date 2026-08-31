<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Pengelolaan akun pengguna — hanya untuk Owner (superadmin). */
class UserController extends Controller
{
    public function __construct(private readonly UserService $pengguna) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = $request->string('q')->trim()->value();

        $rows = User::query()
            ->when($q !== '', fn ($query) => $query->where(function ($sub) use ($q): void {
                $sub->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%');
            }))
            ->when($request->filled('role'), fn ($query) => $query->where(
                'role',
                Role::tryFromAny($request->input('role'))?->value ?? '-',
            ))
            ->when(! $request->boolean('sertakanNonaktif'), fn ($query) => $query->aktif())
            ->orderBy('name')
            ->get();

        return UserResource::collection($rows);
    }

    /** Daftar role beserta keterangannya, untuk dropdown di form. */
    public function role(): JsonResponse
    {
        return response()->json([
            'data' => array_map(static fn (Role $r): array => [
                'kode' => $r->value,
                'label' => $r->label(),
                'keterangan' => $r->keterangan(),
                'menu' => $r->menu(),
            ], Role::cases()),
        ]);
    }

    public function store(UserRequest $request): JsonResponse
    {
        $user = $this->pengguna->simpan($request->payload(), $request->user());

        return (new UserResource($user))
            ->additional(['message' => 'Akun pengguna berhasil dibuat.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UserRequest $request, User $pengguna): UserResource
    {
        $user = $this->pengguna->ubah($pengguna, $request->payload(), $request->user());

        return (new UserResource($user))->additional(['message' => 'Akun pengguna diperbarui.']);
    }

    public function destroy(Request $request, User $pengguna): JsonResponse
    {
        $this->pengguna->hapus($pengguna, $request->user());

        return response()->json(['message' => 'Akun pengguna dihapus.']);
    }
}
