<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatusPenderes;
use App\Http\Controllers\Controller;
use App\Http\Requests\PetaniRequest;
use App\Http\Resources\PetaniResource;
use App\Models\Petani;
use App\Services\MasterDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PetaniController extends Controller
{
    public function __construct(private readonly MasterDataService $master) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $petani = Petani::query()
            ->cari($request->string('q')->trim()->value())
            ->berstatusPenderes($this->filterStatus($request))
            ->with('statusPenderes')
            ->withCount('pembelian')
            ->withSum('pembelian', 'total')
            ->orderBy('nama')
            ->get();

        return PetaniResource::collection($petani);
    }

    /**
     * `?statusPenderes=pms,plmd` — kosong berarti tanpa filter.
     *
     * @return array<int, string>
     */
    private function filterStatus(Request $request): array
    {
        $mentah = $request->input('statusPenderes');
        $mentah = is_array($mentah) ? $mentah : explode(',', (string) $mentah);

        return array_values(array_filter(array_map(
            static fn ($kode): ?string => StatusPenderes::tryFromAny(trim((string) $kode))?->value,
            $mentah,
        )));
    }

    public function store(PetaniRequest $request): JsonResponse
    {
        $petani = $this->master->simpanPetani($request->payload(), $request->user());

        return (new PetaniResource($petani))
            ->additional(['message' => 'Petani berhasil ditambahkan.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Petani $petani): PetaniResource
    {
        $petani->load('statusPenderes')->loadCount('pembelian')->loadSum('pembelian', 'total');

        return new PetaniResource($petani);
    }

    public function update(PetaniRequest $request, Petani $petani): PetaniResource
    {
        $petani = $this->master->ubahPetani($petani, $request->payload(), $request->user());

        return (new PetaniResource($petani))->additional(['message' => 'Data petani berhasil diperbarui.']);
    }

    public function destroy(Request $request, Petani $petani): JsonResponse
    {
        $this->master->hapusPetani($petani, $request->user());

        return response()->json(['message' => 'Petani berhasil dihapus.']);
    }
}
