<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pembulatan nominal uang ke kelipatan 500 / 1.000 terdekat ke atas.
 *
 * Aturan dari client:
 *   sisa terhadap kelipatan 1.000 terbawah
 *     = 0      -> tetap
 *     <= 500   -> naik ke +500
 *     > 500    -> naik ke kelipatan 1.000 berikutnya
 *
 * Contoh: 25.300 -> 25.500 · 25.700 -> 26.000 · 124.200 -> 124.500
 */
final class Pembulatan
{
    public static function keLimaRatus(float $nominal): float
    {
        if ($nominal <= 0.0) {
            return 0.0;
        }

        $base = floor($nominal / 1000) * 1000;
        $sisa = round($nominal - $base, 2);

        if ($sisa <= 0.0) {
            return $base;
        }

        return $sisa <= 500.0 ? $base + 500 : $base + 1000;
    }
}
