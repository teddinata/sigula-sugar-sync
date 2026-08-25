<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\BiayaOperasional;
use App\Models\Eksportir;
use App\Models\GajiMingguan;
use App\Models\GradeHarga;
use App\Models\KartuStok;
use App\Models\Karyawan;
use App\Models\Pembelian;
use App\Models\Penjualan;
use App\Models\PenjualanItem;
use App\Models\Pengepul;
use App\Models\Petani;
use App\Models\PetaniStatus;
use App\Models\ProduksiKaryawan;
use App\Models\SesiTungku;
use App\Models\SesiTungkuBahan;
use App\Models\TarifUpah;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Nama morph disimpan sebagai alias pendek supaya kolom referensi di
        // kartu_stok & audit_logs tidak bergantung pada namespace class.
        Relation::enforceMorphMap([
            'user' => User::class,
            'petani' => Petani::class,
            'karyawan' => Karyawan::class,
            'eksportir' => Eksportir::class,
            'grade_harga' => GradeHarga::class,
            'tarif_upah' => TarifUpah::class,
            'pembelian' => Pembelian::class,
            'sesi_tungku' => SesiTungku::class,
            'produksi_karyawan' => ProduksiKaryawan::class,
            'penjualan' => Penjualan::class,
            'penjualan_item' => PenjualanItem::class,
            'gaji_mingguan' => GajiMingguan::class,
            'biaya_operasional' => BiayaOperasional::class,
            'kartu_stok' => KartuStok::class,
            'pengepul' => Pengepul::class,
            'petani_status' => PetaniStatus::class,
            'sesi_tungku_bahan' => SesiTungkuBahan::class,
        ]);

        // Di luar production, N+1 query dan atribut asing dianggap error supaya
        // ketahuan saat development/test, bukan saat dipakai klien.
        $strict = ! $this->app->isProduction();
        Model::preventLazyLoading($strict);
        Model::preventSilentlyDiscardingAttributes($strict);
        Model::unguard(false);
    }
}
