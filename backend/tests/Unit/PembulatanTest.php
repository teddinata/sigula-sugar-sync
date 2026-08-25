<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Pembulatan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Aturan pembulatan client: sisa di atas kelipatan 1.000 dinaikkan ke 500 bila
 * <= 500, selebihnya dinaikkan ke 1.000 berikutnya. Nominal bulat tidak berubah.
 */
class PembulatanTest extends TestCase
{
    /** @return array<string, array{float, float}> */
    public static function contohClient(): array
    {
        return [
            '25.300 -> 25.500' => [25300.0, 25500.0],
            '25.700 -> 26.000' => [25700.0, 26000.0],
            '124.200 -> 124.500' => [124200.0, 124500.0],
            'tepat 500 tetap 500' => [25500.0, 25500.0],
            'tepat ribuan tidak berubah' => [26000.0, 26000.0],
            'sisa 1 rupiah naik ke 500' => [25001.0, 25500.0],
            'sisa 501 naik ke ribuan' => [25501.0, 26000.0],
            'sisa desimal kecil' => [25000.4, 25500.0],
            'nol tetap nol' => [0.0, 0.0],
            'negatif diabaikan' => [-1500.0, 0.0],
        ];
    }

    #[DataProvider('contohClient')]
    public function test_membulatkan_ke_kelipatan_lima_ratus(float $masukan, float $harapan): void
    {
        $this->assertEqualsWithDelta($harapan, Pembulatan::keLimaRatus($masukan), 0.001);
    }

    public function test_hasil_selalu_kelipatan_lima_ratus(): void
    {
        for ($nominal = 1; $nominal <= 20000; $nominal += 137) {
            $hasil = Pembulatan::keLimaRatus((float) $nominal);

            $this->assertSame(0.0, fmod($hasil, 500.0), "Gagal pada nominal {$nominal}.");
            $this->assertGreaterThanOrEqual($nominal, $hasil, "Pembulatan tidak boleh merugikan penerima ({$nominal}).");
            $this->assertLessThan($nominal + 500, $hasil, "Pembulatan terlalu jauh ({$nominal}).");
        }
    }
}
