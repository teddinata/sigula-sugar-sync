<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Grade;
use App\Enums\StatusSesi;
use App\Http\Controllers\Controller;
use App\Http\Requests\MulaiSesiTungkuRequest;
use App\Http\Requests\SelesaikanSesiTungkuRequest;
use App\Http\Resources\SesiTungkuResource;
use App\Models\SesiTungku;
use App\Services\ProduksiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SesiTungkuController extends Controller
{
    public function __construct(private readonly ProduksiService $produksi) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('perPage', 50), 1), 200);
        $q = $request->string('q')->trim()->value();
        $status = $request->filled('status') ? StatusSesi::tryFromAny($request->input('status')) : null;

        $rows = SesiTungku::query()
            ->with(['karyawan1', 'karyawan2', 'bahan'])
            ->when($request->filled('tanggal'), fn ($query) => $query->whereDate('tanggal', $request->input('tanggal')))
            ->rentang($request->input('dari'), $request->input('sampai'))
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->when($request->filled('grade'), function ($query) use ($request) {
                $grade = Grade::tryFromAny($request->input('grade'));

                return $grade === null ? $query : $query->where('grade', $grade->value);
            })
            ->when($request->filled('karyawanId'), fn ($query) => $query->where(function ($sub) use ($request): void {
                $sub->where('karyawan_1_id', $request->input('karyawanId'))
                    ->orWhere('karyawan_2_id', $request->input('karyawanId'));
            }))
            ->when($q !== '', fn ($query) => $query->where(function ($sub) use ($q): void {
                $sub->where('kode_tungku', 'like', '%'.$q.'%')
                    ->orWhereHas('karyawan1', fn ($k) => $k->where('nama', 'like', '%'.$q.'%'))
                    ->orWhereHas('karyawan2', fn ($k) => $k->where('nama', 'like', '%'.$q.'%'));
            }))
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return SesiTungkuResource::collection($rows)->additional([
            'ringkasan' => $this->produksi->ringkasanHarian($request->input('tanggal')),
        ]);
    }

    public function store(MulaiSesiTungkuRequest $request): JsonResponse
    {
        $sesi = $this->produksi->mulai($request->payload(), $request->user());

        return (new SesiTungkuResource($sesi))
            ->additional(['message' => sprintf('Sesi tungku %s dimulai.', $sesi->kode_tungku)])
            ->response()
            ->setStatusCode(201);
    }

    public function show(SesiTungku $sesi): SesiTungkuResource
    {
        $sesi->load(['karyawan1', 'karyawan2', 'bahan', 'porsiKaryawan']);

        return new SesiTungkuResource($sesi);
    }

    /** Menutup sesi + membagi hasil ke 2 karyawan secara otomatis. */
    public function selesaikan(SelesaikanSesiTungkuRequest $request, SesiTungku $sesi): SesiTungkuResource
    {
        $sesi = $this->produksi->selesaikan(
            $sesi,
            $request->kgKristal(),
            $request->kgBrondol(),
            $request->user(),
        );

        $porsiKristal = round((float) $sesi->kg_kristal_total / 2, 2);
        $porsiBrondol = round((float) $sesi->kg_brondol_total / 2, 2);

        return (new SesiTungkuResource($sesi))->additional([
            'message' => sprintf(
                'Sesi %s selesai. Rendemen %s%%, masing-masing karyawan tercatat %s kg kristal & %s kg brondol.',
                $sesi->kode_tungku,
                $sesi->rendemen,
                $porsiKristal,
                $porsiBrondol,
            ),
        ]);
    }

    public function destroy(Request $request, SesiTungku $sesi): JsonResponse
    {
        $this->produksi->batalkan($sesi, $request->user(), $request->input('alasan'));

        return response()->json(['message' => 'Sesi tungku dibatalkan dan efek stoknya dikembalikan.']);
    }

    /** Tren rendemen harian untuk grafik. */
    public function tren(Request $request): JsonResponse
    {
        $hari = min(max($request->integer('hari', 14), 1), 90);

        return response()->json([
            'data' => $this->produksi->trenRendemen($hari, $request->input('sampai')),
        ]);
    }
}
