<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Grade;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Services\ProduksiService;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ExportLaporanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMaster();
        $this->travelTo('2026-08-13 09:00:00');
    }

    // ---------------------------------------------------------------- utilitas

    /** Mengambil isi CSV dari streamed response. */
    private function isiCsv(TestResponse $response): string
    {
        $response->assertOk();

        return $response->streamedContent();
    }

    /** @return array<int, array<int, string>> baris CSV yang sudah diurai */
    private function baris(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;

        return array_values(array_filter(
            array_map(static fn (string $l): array => str_getcsv($l, ';', '"', ''), explode(PHP_EOL, $csv)),
            static fn (array $b): bool => $b !== [null] && implode('', $b) !== '',
        ));
    }

    private function siapkanTransaksi(): void
    {
        $petani = $this->petani('Sukirman');
        $eksportir = $this->eksportir('PT Global Sweet Export');
        $k1 = $this->karyawan('Pardi');
        $k2 = $this->karyawan('Asep Saepudin');

        $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-11', 'petaniId' => $petani->id, 'grade' => 'NS 1', 'kg' => 100,
        ])->assertCreated();

        $produksi = app(ProduksiService::class);
        $sesi = $produksi->mulai([
            'tanggal' => '2026-08-11',
            'grade' => Grade::NS1,
            'kg_bahan_mentah' => 100,
            'karyawan_1_id' => $k1->id,
            'karyawan_2_id' => $k2->id,
        ]);
        $produksi->selesaikan($sesi, 80, 20);

        $this->postJson('/api/v1/penjualan', [
            'tanggal' => '2026-08-12',
            'eksportirId' => $eksportir->id,
            'kristal' => ['kg' => 80, 'harga' => 24500],
            'brondol' => ['kg' => 20, 'harga' => 16500],
        ])->assertCreated();

        $this->postJson('/api/v1/keuangan/biaya', [
            'tanggal' => '2026-08-12', 'keterangan' => 'Tagihan listrik pabrik',
            'kategori' => 'Listrik', 'jumlah' => 500000,
        ])->assertCreated();
    }

    // ---------------------------------------------------------------- format

    public function test_csv_diawali_bom_dan_memakai_pemisah_titik_koma(): void
    {
        $this->masukSebagai(Role::OWNER);

        $csv = $this->isiCsv($this->get('/api/v1/keuangan/laba-rugi/export'));

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'BOM UTF-8 wajib ada agar Excel membaca huruf beraksen dengan benar.');
        $this->assertStringContainsString(';', $csv);
    }

    public function test_nama_file_memuat_jenis_laporan_dan_rentang_tanggal(): void
    {
        $this->masukSebagai(Role::OWNER);

        $this->get('/api/v1/keuangan/laba-rugi/export?periode=custom&dari=2026-08-01&sampai=2026-08-31')
            ->assertOk()
            ->assertDownload('laba-rugi_2026-08-01_sd_2026-08-31.csv');
    }

    public function test_angka_memakai_koma_desimal_tanpa_pemisah_ribuan(): void
    {
        $this->masukSebagai(Role::OWNER);
        $this->siapkanTransaksi();

        $csv = $this->isiCsv($this->get('/api/v1/keuangan/laba-rugi/export'));

        // Excel locale id-ID membaca "1450000,00" sebagai angka; "1.450.000" tidak.
        $this->assertStringContainsString('1450000,00', $csv);
        $this->assertStringNotContainsString('1.450.000', $csv);
    }

    // ---------------------------------------------------------------- isi laporan

    public function test_export_laba_rugi_memuat_seluruh_komponen(): void
    {
        $this->masukSebagai(Role::OWNER);
        $this->siapkanTransaksi();

        $baris = $this->baris($this->isiCsv($this->get('/api/v1/keuangan/laba-rugi/export')));
        $peta = [];
        foreach ($baris as $b) {
            if (count($b) >= 2 && $b[0] !== '') {
                $peta[$b[0]] = $b[1];
            }
        }

        $this->assertSame('2290000,00', $peta['Pendapatan Penjualan']);
        $this->assertSame('1450000,00', $peta['HPP — Pembelian Bahan Baku']);
        $this->assertSame('10000,00', $peta['HPP — Uang Makan']);
        $this->assertSame('118000,00', $peta['HPP — Total Gaji Karyawan']);
        $this->assertSame('1568000,00', $peta['HPP — TOTAL']);
        $this->assertSame('500000,00', $peta['Biaya Operasional Lain-lain']);
        $this->assertSame('222000,00', $peta['LABA BERSIH']);

        // Kepala laporan
        $csv = $this->isiCsv($this->get('/api/v1/keuangan/laba-rugi/export'));
        $this->assertStringContainsString('PT Nira Sari Murni', $csv);
        $this->assertStringContainsString('LAPORAN LABA RUGI', $csv);
    }

    public function test_export_pembelian_memuat_rincian_dan_baris_total(): void
    {
        $this->masukSebagai(Role::OWNER);
        $this->siapkanTransaksi();

        $baris = $this->baris($this->isiCsv($this->get('/api/v1/pembelian/export')));
        $data = end($baris);

        $this->assertStringContainsString('Sukirman', $this->isiCsv($this->get('/api/v1/pembelian/export')));
        $this->assertSame('TOTAL', $data[4]);
        $this->assertSame('100,00', $data[5]);
        $this->assertSame('1450000,00', $data[7]);
    }

    public function test_export_penjualan_memisahkan_kolom_kristal_dan_brondol(): void
    {
        $this->masukSebagai(Role::OWNER);
        $this->siapkanTransaksi();

        $baris = $this->baris($this->isiCsv($this->get('/api/v1/penjualan/export')));
        $trx = $baris[count($baris) - 2];   // baris sebelum TOTAL

        $this->assertSame('80,00', $trx[3]);        // kg kristal
        $this->assertSame('24500,00', $trx[4]);     // harga kristal
        $this->assertSame('1960000,00', $trx[5]);   // subtotal kristal
        $this->assertSame('20,00', $trx[6]);        // kg brondol
        $this->assertSame('330000,00', $trx[8]);    // subtotal brondol
        $this->assertSame('2290000,00', $trx[9]);   // total
    }

    public function test_export_produksi_memuat_dua_karyawan_dan_rendemen(): void
    {
        $this->masukSebagai(Role::OWNER);
        $this->siapkanTransaksi();

        $csv = $this->isiCsv($this->get('/api/v1/produksi/sesi/export'));
        $baris = $this->baris($csv);
        $sesi = $baris[count($baris) - 2];

        $this->assertSame('NS 1', $sesi[2]);
        $this->assertSame('100,00', $sesi[3]);
        $this->assertSame('Pardi', $sesi[4]);
        $this->assertSame('Asep Saepudin', $sesi[5]);
        $this->assertSame('80,00', $sesi[6]);
        $this->assertSame('20,00', $sesi[7]);
        $this->assertSame('100,00', $sesi[8]);      // rendemen (80+20)/100
        $this->assertSame('Selesai', $sesi[9]);
    }

    public function test_export_penggajian_memuat_rincian_dan_tarif(): void
    {
        $this->masukSebagai(Role::OWNER);
        $this->siapkanTransaksi();

        $csv = $this->isiCsv($this->get('/api/v1/penggajian/export?tanggal=2026-08-13'));

        $this->assertStringContainsString('REKAP GAJI MINGGUAN', $csv);
        $this->assertStringContainsString('Periode: 10 Agustus 2026 — 16 Agustus 2026', $csv);
        $this->assertStringContainsString('Kristal Rp 1150/kg', $csv);
        $this->assertStringContainsString('Pardi', $csv);
        // Masing-masing karyawan: 40 kg kristal, 10 kg brondol, 1 hari kerja
        $this->assertStringContainsString('40,00;10,00;1;46000,00;8000,00;5000,00;59000,00', $csv);
    }

    public function test_export_kartu_stok_dan_biaya_operasional(): void
    {
        $this->masukSebagai(Role::OWNER);
        $this->siapkanTransaksi();

        $stok = $this->isiCsv($this->get('/api/v1/stok/kartu/export'));
        $this->assertStringContainsString('KARTU STOK', $stok);
        $this->assertStringContainsString('Bahan Mentah NS 1', $stok);
        $this->assertStringContainsString('Produk Kristal', $stok);

        $biaya = $this->isiCsv($this->get('/api/v1/keuangan/biaya/export'));
        $this->assertStringContainsString('Tagihan listrik pabrik', $biaya);
        $this->assertStringContainsString('500000,00', $biaya);
    }

    // ---------------------------------------------------------------- filter

    public function test_filter_periode_membatasi_isi_export(): void
    {
        $this->masukSebagai(Role::OWNER);
        $petani = $this->petani();

        $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-07-15', 'petaniId' => $petani->id, 'grade' => 'NS 1', 'kg' => 100,
        ])->assertCreated();
        $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-11', 'petaniId' => $petani->id, 'grade' => 'NS 1', 'kg' => 200,
        ])->assertCreated();

        $bulanIni = $this->baris($this->isiCsv($this->get('/api/v1/pembelian/export?periode=bulan_ini')));
        $bulanLalu = $this->baris($this->isiCsv($this->get('/api/v1/pembelian/export?periode=bulan_lalu')));

        // judul(4) + kolom(1) + transaksi(1) + total(1)
        $this->assertCount(7, $bulanIni);
        $this->assertCount(7, $bulanLalu);
        $this->assertSame('2900000,00', end($bulanIni)[7]);
        $this->assertSame('1450000,00', end($bulanLalu)[7]);
    }

    public function test_filter_grade_pada_export_pembelian(): void
    {
        $this->masukSebagai(Role::OWNER);
        $petani = $this->petani();

        $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-11', 'petaniId' => $petani->id, 'grade' => 'NS 1', 'kg' => 100,
        ])->assertCreated();
        $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-11', 'petaniId' => $petani->id, 'grade' => 'Kecap', 'kg' => 50,
        ])->assertCreated();

        $csv = $this->isiCsv($this->get('/api/v1/pembelian/export?grade=ns1'));

        $this->assertStringContainsString('NS 1', $csv);
        $this->assertStringNotContainsString('Kecap', $csv);
    }

    public function test_periode_custom_tanpa_tanggal_ditolak(): void
    {
        $this->masukSebagai(Role::OWNER);

        $this->getJson('/api/v1/pembelian/export?periode=custom')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['dari', 'sampai']);
    }

    // ---------------------------------------------------------------- hak akses

    public function test_staff_gudang_tidak_bisa_export_keuangan_dan_penggajian(): void
    {
        $this->masukSebagai(Role::STAFF_GUDANG);

        $this->getJson('/api/v1/keuangan/laba-rugi/export')->assertStatus(403);
        $this->getJson('/api/v1/keuangan/biaya/export')->assertStatus(403);
        $this->getJson('/api/v1/penggajian/export')->assertStatus(403);
        $this->getJson('/api/v1/penjualan/export')->assertStatus(403);
    }

    public function test_staff_gudang_boleh_export_pembelian_dan_kartu_stok(): void
    {
        $this->masukSebagai(Role::STAFF_GUDANG);

        $this->get('/api/v1/pembelian/export')->assertOk();
        $this->get('/api/v1/stok/kartu/export')->assertOk();
    }

    public function test_staff_produksi_hanya_bisa_export_produksi_dan_stok(): void
    {
        $this->masukSebagai(Role::STAFF_PRODUKSI);

        $this->get('/api/v1/produksi/sesi/export')->assertOk();
        $this->get('/api/v1/stok/kartu/export')->assertOk();
        $this->getJson('/api/v1/pembelian/export')->assertStatus(403);
        $this->getJson('/api/v1/keuangan/laba-rugi/export')->assertStatus(403);
    }

    public function test_export_tanpa_token_ditolak(): void
    {
        $this->getJson('/api/v1/keuangan/laba-rugi/export')->assertStatus(401);
    }

    // ---------------------------------------------------------------- audit

    public function test_setiap_export_tercatat_di_audit_log(): void
    {
        $user = $this->masukSebagai(Role::OWNER);

        $this->get('/api/v1/keuangan/laba-rugi/export?periode=bulan_lalu')->assertOk();

        $log = AuditLog::query()->where('aksi', 'laporan.export')->latest('id')->firstOrFail();

        $this->assertSame($user->id, $log->user_id);
        $this->assertStringContainsString('laba-rugi', $log->deskripsi);
        $this->assertSame('laba-rugi', $log->data['jenis']);
        $this->assertSame('bulan_lalu', $log->data['filter']['periode']);
    }

    public function test_export_tanpa_data_tetap_menghasilkan_file_valid(): void
    {
        $this->masukSebagai(Role::OWNER);

        $baris = $this->baris($this->isiCsv($this->get('/api/v1/penjualan/export')));

        // judul(4) + kolom(1) + baris TOTAL(1), tanpa transaksi
        $this->assertCount(6, $baris);
        $this->assertSame('TOTAL', $baris[5][2]);
    }
}
