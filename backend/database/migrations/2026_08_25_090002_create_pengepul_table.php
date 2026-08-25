<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pengepul (perantara) pada pembelian bahan.
     *
     * Relasinya diletakkan di transaksi, bukan di data petani: petani yang sama
     * bisa menjual langsung di satu waktu dan lewat pengepul di waktu lain.
     */
    public function up(): void
    {
        Schema::create('pengepul', function (Blueprint $table) {
            $table->id();
            $table->string('nama')->index();
            $table->string('kontak', 40)->nullable();
            $table->text('alamat')->nullable();
            $table->boolean('aktif')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('pembelian', function (Blueprint $table) {
            $table->foreignId('pengepul_id')->nullable()->after('petani_id')
                ->constrained('pengepul')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pembelian', function (Blueprint $table) {
            $table->dropForeign(['pengepul_id']);
            $table->dropColumn('pengepul_id');
        });
        Schema::dropIfExists('pengepul');
    }
};
