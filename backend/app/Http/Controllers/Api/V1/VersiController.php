<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Informasi versi aplikasi.
 *
 * Sengaja TANPA autentikasi: frontend memanggilnya berkala (termasuk di halaman
 * login) untuk tahu ada rilis baru, dan endpoint ini tidak membuka data apa pun.
 */
class VersiController extends Controller
{
    public function __invoke(): JsonResponse
    {
        /** @var array{aplikasi: string, dirilis: string, api: string, minimal_web: string, catatan: array<int, string>} $versi */
        $versi = config('sigula.versi');

        return response()->json([
            'data' => [
                'aplikasi' => 'SIGULA',
                'pemilik' => 'PT Nira Sari Murni',
                'versi' => $versi['aplikasi'],
                'dirilis' => $versi['dirilis'],
                'versiApi' => $versi['api'],
                'minimalWeb' => $versi['minimal_web'],
                'catatan' => array_values($versi['catatan']),
            ],
        ]);
    }
}
