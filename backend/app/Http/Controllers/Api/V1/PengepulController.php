<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PengepulRequest;
use App\Http\Resources\PengepulResource;
use App\Models\Pengepul;
use App\Services\MasterDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PengepulController extends Controller
{
    public function __construct(private readonly MasterDataService $master) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $pengepul = Pengepul::query()
            ->cari($request->string('q')->trim()->value())
            ->when(! $request->boolean('sertakanNonaktif'), fn ($q) => $q->aktif())
            ->withCount('pembelian')
            ->orderBy('nama')
            ->get();

        return PengepulResource::collection($pengepul);
    }

    public function store(PengepulRequest $request): JsonResponse
    {
        $pengepul = $this->master->simpanPengepul($request->payload(), $request->user());

        return (new PengepulResource($pengepul))
            ->additional(['message' => 'Pengepul berhasil ditambahkan.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(PengepulRequest $request, Pengepul $pengepul): PengepulResource
    {
        $pengepul = $this->master->ubahPengepul($pengepul, $request->payload(), $request->user());

        return (new PengepulResource($pengepul))->additional(['message' => 'Data pengepul berhasil diperbarui.']);
    }

    public function destroy(Request $request, Pengepul $pengepul): JsonResponse
    {
        $hasil = $this->master->hapusPengepul($pengepul, $request->user());

        return response()->json([
            'message' => $hasil === 'nonaktif'
                ? 'Pengepul punya riwayat transaksi, jadi dinonaktifkan (bukan dihapus) agar data pembelian tetap utuh.'
                : 'Pengepul berhasil dihapus.',
        ]);
    }
}
