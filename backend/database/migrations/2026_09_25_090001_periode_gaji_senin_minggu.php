<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Periode gaji diperluas dari Senin-Jumat menjadi Senin-Minggu.
     *
     * Karyawan yang memasak di hari Sabtu/Minggu sebelumnya tidak masuk periode
     * mana pun sehingga upahnya hilang dari penggajian. Kolom penutup periode
     * diganti namanya supaya tidak menyesatkan, dan isinya digeser ke Minggu.
     */
    public function up(): void
    {
        Schema::table('gaji_mingguan', function (Blueprint $table) {
            $table->renameColumn('periode_jumat', 'periode_minggu');
        });

        foreach (DB::table('gaji_mingguan')->select('id', 'periode_senin')->cursor() as $baris) {
            DB::table('gaji_mingguan')->where('id', $baris->id)->update([
                'periode_minggu' => date('Y-m-d', strtotime($baris->periode_senin.' +6 days')),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('gaji_mingguan', function (Blueprint $table) {
            $table->renameColumn('periode_minggu', 'periode_jumat');
        });

        foreach (DB::table('gaji_mingguan')->select('id', 'periode_senin')->cursor() as $baris) {
            DB::table('gaji_mingguan')->where('id', $baris->id)->update([
                'periode_jumat' => date('Y-m-d', strtotime($baris->periode_senin.' +4 days')),
            ]);
        }
    }
};
