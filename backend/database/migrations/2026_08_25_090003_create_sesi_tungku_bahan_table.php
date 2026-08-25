<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rincian bahan mentah per sesi tungku.
     *
     * Satu sesi kini boleh memakai campuran beberapa grade sekaligus
     * (mis. NS 1 60 kg + NS 2 40 kg). Stok tiap grade terpisah, jadi
     * pemotongan stok harus dihitung per baris, bukan dari satu angka gabungan.
     *
     * Kolom lama pada `sesi_tungku` tetap dipertahankan sebagai ringkasan:
     * - `grade`           grade utama (baris pertama)
     * - `kg_bahan_mentah` TOTAL seluruh grade
     * Keduanya dijaga oleh ProduksiService agar laporan & filter lama tetap jalan.
     */
    public function up(): void
    {
        Schema::create('sesi_tungku_bahan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sesi_tungku_id')->constrained('sesi_tungku')->cascadeOnDelete();
            $table->string('grade', 20);
            $table->decimal('kg', 12, 2);
            $table->timestamps();

            $table->unique(['sesi_tungku_id', 'grade']);
        });

        // Pindahkan sesi yang sudah ada ke struktur baru supaya data lama tetap utuh.
        foreach (DB::table('sesi_tungku')->select('id', 'grade', 'kg_bahan_mentah')->cursor() as $sesi) {
            DB::table('sesi_tungku_bahan')->insert([
                'sesi_tungku_id' => $sesi->id,
                'grade' => $sesi->grade,
                'kg' => $sesi->kg_bahan_mentah,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Satu tungku boleh dikerjakan satu orang saja.
        Schema::table('sesi_tungku', function (Blueprint $table) {
            $table->foreignId('karyawan_2_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesi_tungku_bahan');
    }
};
