<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\KategoriStok;
use App\Models\AuditLog;
use App\Models\KartuStok;
use Tests\TestCase;

/** Stok awal masuk lewat mekanisme opname supaya jejaknya tetap ada. */
class StokAwalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMaster();
        $this->travelTo('2026-08-13 09:00:00');
    }

    public function test_mengisi_stok_awal_satu_kategori(): void
    {
        $this->artisan('sigula:stok-awal', ['--kg' => 'ns1=22193'])->assertSuccessful();

        $this->assertEqualsWithDelta(22193.0, $this->saldo(KategoriStok::NS1), 0.001);

        $mutasi = KartuStok::query()->where('kategori', KategoriStok::NS1->value)->firstOrFail();
        $this->assertSame('masuk', $mutasi->jenis->value);
        $this->assertEqualsWithDelta(22193.0, (float) $mutasi->jumlah_kg, 0.001);
        $this->assertStringContainsString('Stok awal sistem', $mutasi->keterangan);
    }

    public function test_beberapa_kategori_sekaligus(): void
    {
        $this->artisan('sigula:stok-awal', ['--kg' => 'ns1=22193,ns2=1500.5,kristal=840'])
            ->assertSuccessful();

        $this->assertEqualsWithDelta(22193.0, $this->saldo(KategoriStok::NS1), 0.001);
        $this->assertEqualsWithDelta(1500.5, $this->saldo(KategoriStok::NS2), 0.001);
        $this->assertEqualsWithDelta(840.0, $this->saldo(KategoriStok::KRISTAL), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->saldo(KategoriStok::KECAP), 0.001);
    }

    public function test_tercatat_di_audit_log(): void
    {
        $this->artisan('sigula:stok-awal', ['--kg' => 'ns1=22193'])->assertSuccessful();

        $log = AuditLog::query()->where('aksi', 'stok.opname')->firstOrFail();
        $this->assertEqualsWithDelta(22193.0, $log->data['stok_fisik'], 0.001);
        $this->assertEqualsWithDelta(0.0, $log->data['saldo_sistem'], 0.001);
    }

    /** Menjalankan ulang dengan angka sama tidak menggandakan stok. */
    public function test_dijalankan_ulang_tidak_menambah_saldo(): void
    {
        $this->artisan('sigula:stok-awal', ['--kg' => 'ns1=22193'])->assertSuccessful();
        $this->artisan('sigula:stok-awal', ['--kg' => 'ns1=22193'])->assertSuccessful();

        $this->assertEqualsWithDelta(22193.0, $this->saldo(KategoriStok::NS1), 0.001);
        $this->assertSame(1, KartuStok::query()->count());
    }

    /** Angka berbeda dicatat sebagai koreksi selisihnya saja. */
    public function test_angka_berbeda_dicatat_sebagai_koreksi(): void
    {
        $this->artisan('sigula:stok-awal', ['--kg' => 'ns1=22193'])->assertSuccessful();
        $this->artisan('sigula:stok-awal', ['--kg' => 'ns1=22000', '--alasan' => 'Koreksi hitung ulang'])
            ->assertSuccessful();

        $this->assertEqualsWithDelta(22000.0, $this->saldo(KategoriStok::NS1), 0.001);

        $koreksi = KartuStok::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame('keluar', $koreksi->jenis->value);
        $this->assertEqualsWithDelta(193.0, (float) $koreksi->jumlah_kg, 0.001);
    }

    public function test_kategori_tidak_dikenal_ditolak_tanpa_mengubah_apa_pun(): void
    {
        $this->artisan('sigula:stok-awal', ['--kg' => 'gulaaren=100'])->assertSuccessful();

        $this->assertSame(0, KartuStok::query()->count());
    }
}
