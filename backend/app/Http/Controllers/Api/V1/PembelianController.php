<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Grade;
use App\Http\Controllers\Controller;
use App\Http\Requests\PembelianRequest;
use App\Http\Resources\PembelianResource;
use App\Models\Pembelian;
use App\Services\PembelianService;
use App\Support\Periode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PembelianController extends Controller
{
    public function __construct(private readonly PembelianService $pembelian) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('perPage', 25), 1), 200);
        $q = $request->string('q')->trim()->value();

        $rows = Pembelian::query()
            ->with(['petani', 'pengepul'])
            ->rentang($request->input('dari'), $request->input('sampai'))
            ->grade($request->filled('grade') ? Grade::tryFromAny($request->input('grade')) : null)
            ->when($request->filled('petaniId'), fn ($query) => $query->where('petani_id', $request->input('petaniId')))
            ->when($request->filled('pengepulId'), fn ($query) => $query->where('pengepul_id', $request->input('pengepulId')))
            // `punyaPengepul=1` -> lewat pengepul saja, `=0` -> beli langsung dari petani.
            ->when($request->filled('punyaPengepul'), fn ($query) => $request->boolean('punyaPengepul')
                ? $query->whereNotNull('pengepul_id')
                : $query->whereNull('pengepul_id'))
            ->when($q !== '', fn ($query) => $query->where(function ($sub) use ($q): void {
                $sub->where('nomor_kwitansi', 'like', '%'.$q.'%')
                    ->orWhereHas('petani', fn ($p) => $p->where('nama', 'like', '%'.$q.'%'))
                    ->orWhereHas('pengepul', fn ($p) => $p->where('nama', 'like', '%'.$q.'%'));
            }))
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return PembelianResource::collection($rows)->additional([
            'ringkasan' => $this->pembelian->ringkasan(),
        ]);
    }

    public function store(PembelianRequest $request): JsonResponse
    {
        $pembelian = $this->pembelian->simpan($request->payload(), $request->user());

        return (new PembelianResource($pembelian))
            ->additional([
                'message' => 'Transaksi pembelian tersimpan.',
                'kwitansi' => $this->kwitansi($pembelian),
            ])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Pembelian $pembelian): PembelianResource
    {
        $pembelian->load(['petani', 'pengepul']);

        return (new PembelianResource($pembelian))->additional(['kwitansi' => $this->kwitansi($pembelian)]);
    }

    public function destroy(Request $request, Pembelian $pembelian): JsonResponse
    {
        $this->pembelian->batalkan($pembelian, $request->user(), $request->input('alasan'));

        return response()->json(['message' => 'Transaksi pembelian dibatalkan dan stok dikembalikan.']);
    }

    /** Data siap cetak untuk kwitansi pembelian. */
    private function kwitansi(Pembelian $pembelian): array
    {
        $pembelian->loadMissing(['petani', 'pengepul']);

        return [
            'nomor' => $pembelian->nomor_kwitansi,
            'tanggal' => Periode::tanggalIndonesia($pembelian->tanggal),
            'namaPetani' => $pembelian->petani?->nama,
            'namaPengepul' => $pembelian->pengepul?->nama,
            'nomorMember' => $pembelian->petani?->nomor_member ? 'Petani '.$pembelian->petani->nomor_member : '-',
            'grade' => $pembelian->grade->label(),
            'kilogram' => (float) $pembelian->kilogram,
            'hargaPerKg' => (float) $pembelian->harga_per_kg,
            'total' => (float) $pembelian->total,
            // Ditampilkan di kwitansi hanya bila pembulatan mengubah nominal.
            'totalSebelumBulat' => $pembelian->total_sebelum_bulat === null
                ? null
                : (float) $pembelian->total_sebelum_bulat,
            'statusPembayaran' => $pembelian->status_pembayaran->label(),
        ];
    }
}
