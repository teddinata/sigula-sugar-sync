<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menyatukan identitas petani ke satu kolom: KODE LAHAN.
     *
     * Menurut client, kode lahan (mis. "BA-002") memang berfungsi sebagai nomor
     * member — dua kolom untuk satu nilai hanya membuka peluang tidak sinkron.
     * Karena itu `nomor_member` dilebur ke `kode_lahan`, dan kolom `status`
     * (Member/Non-Member) ikut dihapus sebab sekarang bisa disimpulkan sendiri:
     * punya kode lahan = member.
     *
     * Ditambahkan juga `aktif`, supaya petani yang berhenti menderes bisa
     * dinonaktifkan tanpa menghapus riwayat transaksinya.
     */
    public function up(): void
    {
        Schema::table('petani', function (Blueprint $table) {
            $table->boolean('aktif')->default(true)->after('rt_rw')->index();
        });

        // Data lama yang hanya punya nomor member (3 digit) tetap terselamatkan.
        DB::table('petani')
            ->whereNull('kode_lahan')
            ->whereNotNull('nomor_member')
            ->update(['kode_lahan' => DB::raw('nomor_member')]);

        Schema::table('petani', function (Blueprint $table) {
            // Index dilepas lebih dulu: SQLite menolak drop kolom yang masih dipakai index.
            $table->dropUnique('petani_nomor_member_unique');
            $table->dropIndex('petani_status_index');
        });

        Schema::table('petani', function (Blueprint $table) {
            $table->dropColumn(['nomor_member', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('petani', function (Blueprint $table) {
            $table->string('nomor_member', 20)->nullable()->unique()->after('nama');
            $table->string('status', 20)->default('non_member')->index()->after('nama');
        });

        // Kode lahan dikembalikan sebagai nomor member; status disimpulkan ulang.
        DB::table('petani')->whereNotNull('kode_lahan')->update([
            'nomor_member' => DB::raw('kode_lahan'),
            'status' => 'member',
        ]);

        Schema::table('petani', function (Blueprint $table) {
            $table->dropIndex('petani_aktif_index');
        });

        Schema::table('petani', function (Blueprint $table) {
            $table->dropColumn('aktif');
        });
    }
};
