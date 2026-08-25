<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class VersiTest extends TestCase
{
    public function test_versi_bisa_diakses_tanpa_login(): void
    {
        $data = $this->getJson('/api/v1/versi')->assertOk()->json('data');

        $this->assertSame('SIGULA', $data['aplikasi']);
        $this->assertSame('v1', $data['versiApi']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $data['versi']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $data['minimalWeb']);
        $this->assertNotEmpty($data['catatan']);
    }

    public function test_versi_minimal_web_tidak_boleh_melampaui_versi_aplikasi(): void
    {
        $data = $this->getJson('/api/v1/versi')->assertOk()->json('data');

        $this->assertLessThanOrEqual(
            0,
            version_compare($data['minimalWeb'], $data['versi']),
            'minimal_web tidak boleh lebih baru dari versi aplikasi — semua klien akan dipaksa update tanpa ada rilisnya.',
        );
    }
}
