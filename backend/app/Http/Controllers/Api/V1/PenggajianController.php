<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PenggajianService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PenggajianController extends Controller
{
    public function __construct(private readonly PenggajianService $penggajian) {}

    /**
     * Rekap gaji satu periode Senin-Minggu.
     * Query `tanggal` boleh tanggal mana pun dalam minggu yang dimaksud.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'tanggal' => ['nullable', 'date'],
            'sertakanTanpaProduksi' => ['nullable', 'boolean'],
        ]);

        return response()->json([
            'data' => $this->penggajian->rekapMinggu(
                $request->input('tanggal'),
                $request->boolean('sertakanTanpaProduksi'),
            ),
        ]);
    }

    /** Slip gaji satu karyawan pada periode berjalan. */
    public function slip(Request $request, string $karyawan): JsonResponse
    {
        $request->validate(['tanggal' => ['nullable', 'date']]);

        return response()->json([
            'data' => $this->penggajian->slip($karyawan, $request->input('tanggal')),
        ]);
    }

    public function bayar(Request $request, string $karyawan): JsonResponse
    {
        $request->validate(['tanggal' => ['nullable', 'date']]);

        $gaji = $this->penggajian->bayar($karyawan, $request->input('tanggal'), $request->user());

        return response()->json([
            'message' => 'Gaji ditandai sudah dibayar.',
            'data' => [
                'karyawanId' => (string) $gaji->karyawan_id,
                'periodeSenin' => $gaji->periode_senin->toDateString(),
                'periodeMinggu' => $gaji->periode_minggu->toDateString(),
                'total' => (float) $gaji->total,
                'status' => $gaji->status->label(),
                'dibayarPada' => $gaji->dibayar_pada?->toIso8601String(),
            ],
        ]);
    }

    public function bayarSemua(Request $request): JsonResponse
    {
        $request->validate(['tanggal' => ['nullable', 'date']]);

        $dibayar = $this->penggajian->bayarSemua($request->input('tanggal'), $request->user());

        return response()->json([
            'message' => sprintf('%d gaji karyawan ditandai sudah dibayar.', count($dibayar)),
            'data' => [
                'jumlahKaryawan' => count($dibayar),
                'totalDibayar' => round(array_sum(array_map(
                    static fn ($gaji): float => (float) $gaji->total,
                    $dibayar
                )), 2),
            ],
        ]);
    }
}
