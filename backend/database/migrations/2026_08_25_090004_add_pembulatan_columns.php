<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jejak pembulatan nominal.
     *
     * Nilai hasil perhitungan asli (kg x harga, atau upah) tetap disimpan
     * berdampingan dengan nilai yang benar-benar dibayar, supaya rekonsiliasi
     * dengan perhitungan mentah tetap bisa dilakukan.
     */
    public function up(): void
    {
        Schema::table('pembelian', function (Blueprint $table) {
            $table->decimal('total_sebelum_bulat', 18, 2)->nullable()->after('total');
        });

        Schema::table('gaji_mingguan', function (Blueprint $table) {
            $table->decimal('total_sebelum_bulat', 16, 2)->nullable()->after('total');
        });

        // Data lama: belum ada pembulatan, jadi nilai asli = nilai tersimpan.
        DB::table('pembelian')->update(['total_sebelum_bulat' => DB::raw('total')]);
        DB::table('gaji_mingguan')->update(['total_sebelum_bulat' => DB::raw('total')]);
    }

    public function down(): void
    {
        Schema::table('pembelian', fn (Blueprint $t) => $t->dropColumn('total_sebelum_bulat'));
        Schema::table('gaji_mingguan', fn (Blueprint $t) => $t->dropColumn('total_sebelum_bulat'));
    }
};
