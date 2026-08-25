<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Status penderes/pemilik lahan petani.
     *
     * Dipisah ke tabel sendiri (bukan kolom enum) karena satu petani bisa
     * menyandang beberapa status sekaligus, mis. "PMS + PLMD", dan
     * kombinasinya tidak dibatasi.
     */
    public function up(): void
    {
        Schema::create('petani_status', function (Blueprint $table) {
            $table->id();
            $table->foreignId('petani_id')->constrained('petani')->cascadeOnDelete();
            $table->string('kode', 10);   // lihat App\Enums\StatusPenderes
            $table->timestamps();

            $table->unique(['petani_id', 'kode']);
            $table->index('kode');
        });

        Schema::table('petani', function (Blueprint $table) {
            // Kode lahan asli dari data client (mis. BA-123) — bukan nomor member.
            $table->string('kode_lahan', 20)->nullable()->unique()->after('nomor_member');
            $table->string('rt_rw', 20)->nullable()->after('kode_lahan');
        });
    }

    public function down(): void
    {
        Schema::table('petani', function (Blueprint $table) {
            // SQLite menolak drop kolom yang masih dipakai index, jadi index
            // uniknya harus dilepas lebih dulu.
            $table->dropUnique('petani_kode_lahan_unique');
        });

        Schema::table('petani', function (Blueprint $table) {
            $table->dropColumn(['kode_lahan', 'rt_rw']);
        });
        Schema::dropIfExists('petani_status');
    }
};
