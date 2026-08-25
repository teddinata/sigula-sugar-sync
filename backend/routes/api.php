<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BiayaOperasionalController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EksportirController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\KaryawanController;
use App\Http\Controllers\Api\V1\LaporanController;
use App\Http\Controllers\Api\V1\MasterHargaController;
use App\Http\Controllers\Api\V1\MasterTarifController;
use App\Http\Controllers\Api\V1\PembelianController;
use App\Http\Controllers\Api\V1\PengepulController;
use App\Http\Controllers\Api\V1\PenggajianController;
use App\Http\Controllers\Api\V1\PenjualanController;
use App\Http\Controllers\Api\V1\PetaniController;
use App\Http\Controllers\Api\V1\SesiTungkuController;
use App\Http\Controllers\Api\V1\StokController;
use App\Http\Controllers\Api\V1\VersiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SIGULA API v1
|--------------------------------------------------------------------------
| Autentikasi memakai token Sanctum (header: Authorization: Bearer <token>).
| Otorisasi memakai Gate per-ability yang dipetakan dari role pengguna
| (lihat App\Enums\Role dan App\Providers\AuthServiceProvider).
*/

Route::prefix('v1')->group(function (): void {
    // Publik: dipakai frontend untuk mendeteksi rilis baru, termasuk sebelum login.
    Route::get('versi', VersiController::class);

    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('dashboard', [DashboardController::class, 'index'])->middleware('can:lihat-dashboard');

        // ---- Data Petani -------------------------------------------------
        Route::middleware('can:lihat-petani')->group(function (): void {
            Route::get('petani', [PetaniController::class, 'index']);
            Route::get('petani/{petani}', [PetaniController::class, 'show']);
        });
        // Pengepul ikut hak akses petani: sama-sama master data pemasok bahan.
        Route::middleware('can:lihat-petani')->group(function (): void {
            Route::get('pengepul', [PengepulController::class, 'index']);
        });

        Route::middleware('can:kelola-petani')->group(function (): void {
            Route::post('pengepul', [PengepulController::class, 'store']);
            Route::put('pengepul/{pengepul}', [PengepulController::class, 'update']);
            Route::delete('pengepul/{pengepul}', [PengepulController::class, 'destroy']);

            Route::post('petani', [PetaniController::class, 'store']);
            Route::put('petani/{petani}', [PetaniController::class, 'update']);
            Route::delete('petani/{petani}', [PetaniController::class, 'destroy']);
        });

        // ---- Master Harga, Tarif, Karyawan & Eksportir --------------------
        Route::middleware('can:lihat-master')->group(function (): void {
            Route::get('master/harga', [MasterHargaController::class, 'index']);
            Route::get('master/tarif', [MasterTarifController::class, 'index']);
            Route::get('master/karyawan', [KaryawanController::class, 'index']);
            Route::get('master/eksportir', [EksportirController::class, 'index']);
        });
        Route::middleware('can:kelola-master')->group(function (): void {
            Route::post('master/harga', [MasterHargaController::class, 'store']);
            Route::post('master/tarif', [MasterTarifController::class, 'store']);

            Route::post('master/karyawan', [KaryawanController::class, 'store']);
            Route::put('master/karyawan/{karyawan}', [KaryawanController::class, 'update']);
            Route::delete('master/karyawan/{karyawan}', [KaryawanController::class, 'destroy']);

            Route::post('master/eksportir', [EksportirController::class, 'store']);
            Route::put('master/eksportir/{eksportir}', [EksportirController::class, 'update']);
            Route::delete('master/eksportir/{eksportir}', [EksportirController::class, 'destroy']);
        });

        // ---- Pembelian Bahan ---------------------------------------------
        Route::middleware('can:lihat-pembelian')->group(function (): void {
            Route::get('pembelian/export', [ExportController::class, 'pembelian']);
            Route::get('pembelian', [PembelianController::class, 'index']);
            Route::get('pembelian/{pembelian}', [PembelianController::class, 'show']);
        });
        Route::middleware('can:kelola-pembelian')->group(function (): void {
            Route::post('pembelian', [PembelianController::class, 'store']);
            Route::delete('pembelian/{pembelian}', [PembelianController::class, 'destroy']);
        });

        // ---- Manajemen Stok ----------------------------------------------
        Route::middleware('can:lihat-stok')->group(function (): void {
            Route::get('stok', [StokController::class, 'index']);
            Route::get('stok/kartu', [StokController::class, 'kartuStok']);
            Route::get('stok/kartu/export', [ExportController::class, 'kartuStok']);
        });
        Route::post('stok/opname', [StokController::class, 'opname'])->middleware('can:kelola-stok');

        // ---- Produksi (Sesi Tungku) --------------------------------------
        Route::middleware('can:lihat-produksi')->group(function (): void {
            Route::get('produksi/sesi/export', [ExportController::class, 'produksi']);
            Route::get('produksi/sesi', [SesiTungkuController::class, 'index']);
            Route::get('produksi/tren-rendemen', [SesiTungkuController::class, 'tren']);
            Route::get('produksi/sesi/{sesi}', [SesiTungkuController::class, 'show']);
        });
        Route::middleware('can:kelola-produksi')->group(function (): void {
            Route::post('produksi/sesi', [SesiTungkuController::class, 'store']);
            Route::post('produksi/sesi/{sesi}/selesai', [SesiTungkuController::class, 'selesaikan']);
            Route::delete('produksi/sesi/{sesi}', [SesiTungkuController::class, 'destroy']);
        });

        // ---- Penggajian ---------------------------------------------------
        Route::middleware('can:lihat-penggajian')->group(function (): void {
            Route::get('penggajian/export', [ExportController::class, 'penggajian']);
            Route::get('penggajian', [PenggajianController::class, 'index']);
            Route::get('penggajian/slip/{karyawan}', [PenggajianController::class, 'slip']);
        });
        Route::middleware('can:kelola-penggajian')->group(function (): void {
            Route::post('penggajian/bayar-semua', [PenggajianController::class, 'bayarSemua']);
            Route::post('penggajian/{karyawan}/bayar', [PenggajianController::class, 'bayar']);
        });

        // ---- Penjualan ----------------------------------------------------
        Route::middleware('can:lihat-penjualan')->group(function (): void {
            Route::get('penjualan/export', [ExportController::class, 'penjualan']);
            Route::get('penjualan', [PenjualanController::class, 'index']);
            Route::get('penjualan/{penjualan}', [PenjualanController::class, 'show']);
        });
        Route::middleware('can:kelola-penjualan')->group(function (): void {
            Route::post('penjualan', [PenjualanController::class, 'store']);
            Route::patch('penjualan/{penjualan}/status', [PenjualanController::class, 'ubahStatus']);
            Route::delete('penjualan/{penjualan}', [PenjualanController::class, 'destroy']);
        });

        // ---- Keuangan & Laporan -------------------------------------------
        Route::middleware('can:lihat-keuangan')->group(function (): void {
            Route::get('keuangan/laba-rugi', [LaporanController::class, 'labaRugi']);
            Route::get('keuangan/tren', [LaporanController::class, 'tren']);
            Route::get('keuangan/ringkasan-ai', [LaporanController::class, 'ringkasanAi']);
            Route::get('keuangan/biaya', [BiayaOperasionalController::class, 'index']);
            Route::get('keuangan/biaya/export', [ExportController::class, 'biaya']);
            Route::get('keuangan/laba-rugi/export', [ExportController::class, 'labaRugi']);
            Route::get('audit-log', [AuditLogController::class, 'index']);
        });
        Route::middleware('can:kelola-keuangan')->group(function (): void {
            Route::post('keuangan/biaya', [BiayaOperasionalController::class, 'store']);
            Route::put('keuangan/biaya/{biaya}', [BiayaOperasionalController::class, 'update']);
            Route::delete('keuangan/biaya/{biaya}', [BiayaOperasionalController::class, 'destroy']);
        });
    });
});
