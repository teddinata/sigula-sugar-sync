<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Grade;
use App\Enums\JenisMutasi;
use App\Enums\JenisProduk;
use App\Enums\KategoriBiaya;
use App\Enums\KategoriStok;
use App\Enums\StatusSesi;
use App\Models\BiayaOperasional;
use App\Models\KartuStok;
use App\Models\Pembelian;
use App\Models\Penjualan;
use App\Models\SesiTungku;
use App\Support\CsvExport;
use App\Support\Periode;
use App\Support\RentangPeriode;
use Generator;

/**
 * Penyusun isi file export.
 *
 * Setiap method mengembalikan struktur siap tulis:
 *   ['namaFile' => ..., 'judul' => [...], 'kolom' => [...], 'baris' => iterable]
 *
 * Baris dikembalikan sebagai Generator agar export ribuan transaksi tidak
 * memuat seluruh tabel ke memori sekaligus.
 */
final class LaporanExportService
{
    private const PERUSAHAAN = 'PT Nira Sari Murni';

    public function __construct(
        private readonly LaporanService $laporan,
        private readonly PenggajianService $penggajian,
    ) {}

    /** Laporan laba rugi — bentuk ringkasan dua kolom. */
    public function labaRugi(string $dari, string $sampai): array
    {
        $d = $this->laporan->labaRugi($dari, $sampai);
        $gaji = $d['hpp']['gaji'];

        $baris = [
            ['Pendapatan Penjualan', CsvExport::angka($d['pendapatan'])],
            ['', ''],
            ['HPP — Pembelian Bahan Baku', CsvExport::angka($d['hpp']['bahan'])],
            ['HPP — Upah Gula Kristal', CsvExport::angka($gaji['upahKristal'])],
            ['HPP — Upah Gula Brondol', CsvExport::angka($gaji['upahBrondol'])],
            ['HPP — Uang Makan', CsvExport::angka($gaji['uangMakan'])],
            ['HPP — Total Gaji Karyawan', CsvExport::angka($gaji['total'])],
            ['HPP — TOTAL', CsvExport::angka($d['hpp']['total'])],
            ['', ''],
            ['Biaya Operasional Lain-lain', CsvExport::angka($d['biayaOperasional'])],
            ['', ''],
            ['LABA BERSIH', CsvExport::angka($d['labaBersih'])],
            ['Margin (%)', CsvExport::angka($d['margin'])],
        ];

        return [
            'namaFile' => CsvExport::namaFile('laba-rugi', $dari, $sampai),
            'judul' => $this->judul('LAPORAN LABA RUGI', $dari, $sampai),
            'kolom' => ['Keterangan', 'Jumlah (Rp)'],
            'baris' => $baris,
        ];
    }

    /** Rincian pembelian bahan dari petani. */
    public function pembelian(string $dari, string $sampai, ?Grade $grade = null, ?string $petaniId = null): array
    {
        $query = Pembelian::query()
            ->with('petani')
            ->whereBetween('tanggal', [$dari, $sampai])
            ->when($grade !== null, fn ($q) => $q->where('grade', $grade->value))
            ->when($petaniId !== null, fn ($q) => $q->where('petani_id', $petaniId))
            ->orderBy('tanggal')
            ->orderBy('id');

        $baris = (function () use ($query): Generator {
            $totalKg = 0.0;
            $totalRp = 0.0;

            foreach ($query->lazy(500) as $p) {
                $totalKg += (float) $p->kilogram;
                $totalRp += (float) $p->total;

                yield [
                    $p->tanggal->toDateString(),
                    $p->nomor_kwitansi,
                    $p->petani?->nama ?? '-',
                    $p->petani?->kode_lahan ?: '-',
                    $p->grade->label(),
                    CsvExport::angka($p->kilogram),
                    CsvExport::angka($p->harga_per_kg),
                    CsvExport::angka($p->total),
                    $p->status_pembayaran->label(),
                ];
            }

            yield ['', '', '', '', 'TOTAL', CsvExport::angka($totalKg), '', CsvExport::angka($totalRp), ''];
        })();

        return [
            'namaFile' => CsvExport::namaFile('pembelian-bahan', $dari, $sampai),
            'judul' => $this->judul('LAPORAN PEMBELIAN BAHAN DARI PETANI', $dari, $sampai),
            'kolom' => ['Tanggal', 'No. Kwitansi', 'Petani', 'No. Member', 'Grade', 'Kilogram', 'Harga/Kg (Rp)', 'Total (Rp)', 'Status'],
            'baris' => $baris,
        ];
    }

    /** Rincian penjualan ke eksportir, kristal & brondol dipisah per kolom. */
    public function penjualan(string $dari, string $sampai, ?string $eksportirId = null): array
    {
        $query = Penjualan::query()
            ->with(['eksportir', 'items'])
            ->whereBetween('tanggal', [$dari, $sampai])
            ->when($eksportirId !== null, fn ($q) => $q->where('eksportir_id', $eksportirId))
            ->orderBy('tanggal')
            ->orderBy('id');

        $baris = (function () use ($query): Generator {
            $kgK = $kgB = $total = 0.0;

            foreach ($query->lazy(500) as $j) {
                $kristal = $j->items->firstWhere('jenis', JenisProduk::KRISTAL);
                $brondol = $j->items->firstWhere('jenis', JenisProduk::BRONDOL);

                $kgK += (float) ($kristal->kilogram ?? 0);
                $kgB += (float) ($brondol->kilogram ?? 0);
                $total += (float) $j->total;

                yield [
                    $j->tanggal->toDateString(),
                    $j->nomor_invoice,
                    $j->eksportir?->nama ?? '-',
                    CsvExport::angka($kristal->kilogram ?? 0),
                    CsvExport::angka($kristal->harga_per_kg ?? 0),
                    CsvExport::angka($kristal->subtotal ?? 0),
                    CsvExport::angka($brondol->kilogram ?? 0),
                    CsvExport::angka($brondol->harga_per_kg ?? 0),
                    CsvExport::angka($brondol->subtotal ?? 0),
                    CsvExport::angka($j->total),
                    $j->status_pembayaran->label(),
                ];
            }

            yield ['', '', 'TOTAL', CsvExport::angka($kgK), '', '', CsvExport::angka($kgB), '', '', CsvExport::angka($total), ''];
        })();

        return [
            'namaFile' => CsvExport::namaFile('penjualan', $dari, $sampai),
            'judul' => $this->judul('LAPORAN PENJUALAN KE EKSPORTIR', $dari, $sampai),
            'kolom' => [
                'Tanggal', 'No. Invoice', 'Eksportir',
                'Kg Kristal', 'Harga Kristal (Rp)', 'Subtotal Kristal (Rp)',
                'Kg Brondol', 'Harga Brondol (Rp)', 'Subtotal Brondol (Rp)',
                'Total (Rp)', 'Status',
            ],
            'baris' => $baris,
        ];
    }

    /** Rincian produksi per sesi tungku. */
    public function produksi(string $dari, string $sampai, ?StatusSesi $status = null): array
    {
        $query = SesiTungku::query()
            ->with(['karyawan1', 'karyawan2'])
            ->whereBetween('tanggal', [$dari, $sampai])
            ->when($status !== null, fn ($q) => $q->where('status', $status->value))
            ->orderBy('tanggal')
            ->orderBy('id');

        $baris = (function () use ($query): Generator {
            $bahan = $kristal = $brondol = 0.0;

            foreach ($query->lazy(500) as $s) {
                $bahan += (float) $s->kg_bahan_mentah;
                $kristal += (float) ($s->kg_kristal_total ?? 0);
                $brondol += (float) ($s->kg_brondol_total ?? 0);

                yield [
                    $s->tanggal->toDateString(),
                    $s->kode_tungku,
                    $s->grade->label(),
                    CsvExport::angka($s->kg_bahan_mentah),
                    $s->karyawan1?->nama ?? '-',
                    $s->karyawan2?->nama ?? '-',
                    $s->kg_kristal_total === null ? '' : CsvExport::angka($s->kg_kristal_total),
                    $s->kg_brondol_total === null ? '' : CsvExport::angka($s->kg_brondol_total),
                    $s->rendemen === null ? '' : CsvExport::angka($s->rendemen),
                    $s->status->label(),
                ];
            }

            $rendemen = $bahan > 0 ? ($kristal + $brondol) / $bahan * 100 : 0.0;

            yield [
                '', 'TOTAL', '', CsvExport::angka($bahan), '', '',
                CsvExport::angka($kristal), CsvExport::angka($brondol), CsvExport::angka($rendemen), '',
            ];
        })();

        return [
            'namaFile' => CsvExport::namaFile('produksi-sesi-tungku', $dari, $sampai),
            'judul' => $this->judul('LAPORAN PRODUKSI (SESI TUNGKU)', $dari, $sampai),
            'kolom' => [
                'Tanggal', 'Kode Tungku', 'Grade', 'Kg Bahan Mentah',
                'Karyawan 1', 'Karyawan 2', 'Kg Kristal', 'Kg Brondol', 'Rendemen (%)', 'Status',
            ],
            'baris' => $baris,
        ];
    }

    /** Rekap gaji satu periode Senin-Jumat. */
    public function penggajian(?string $tanggalDalamMinggu = null): array
    {
        $rekap = $this->penggajian->rekapMinggu($tanggalDalamMinggu);
        $senin = $rekap['periode']['senin'];
        $jumat = $rekap['periode']['jumat'];

        $baris = [];
        foreach ($rekap['baris'] as $b) {
            $baris[] = [
                $b['nama'],
                CsvExport::angka($b['kgKristal']),
                CsvExport::angka($b['kgBrondol']),
                (string) $b['hariKerja'],
                CsvExport::angka($b['upahKristal']),
                CsvExport::angka($b['upahBrondol']),
                CsvExport::angka($b['uangMakan']),
                CsvExport::angka($b['total']),
                $b['dibayar'] ? 'Sudah Dibayar' : 'Belum Dibayar',
            ];
        }

        $r = $rekap['ringkasan'];
        $baris[] = ['', '', '', '', '', '', 'TOTAL', CsvExport::angka($r['totalGaji']), ''];
        $baris[] = ['', '', '', '', '', '', 'Sudah dibayar', CsvExport::angka($r['sudahDibayar']), ''];
        $baris[] = ['', '', '', '', '', '', 'Belum dibayar', CsvExport::angka($r['belumDibayar']), ''];

        return [
            'namaFile' => CsvExport::namaFile('penggajian', $senin, $jumat),
            'judul' => [
                self::PERUSAHAAN,
                'REKAP GAJI MINGGUAN',
                'Periode: '.$rekap['periode']['label'].' (dibayarkan Jumat)',
                sprintf(
                    'Tarif: Kristal Rp %s/kg · Brondol Rp %s/kg · Uang makan Rp %s/hari',
                    CsvExport::angka($rekap['tarif']['kristal'], 0),
                    CsvExport::angka($rekap['tarif']['brondol'], 0),
                    CsvExport::angka($rekap['tarif']['uangMakan'], 0),
                ),
                'Diekspor: '.Periode::tanggalIndonesia(now()->toDateString()),
            ],
            'kolom' => [
                'Nama Karyawan', 'Kg Kristal', 'Kg Brondol', 'Hari Kerja',
                'Upah Kristal (Rp)', 'Upah Brondol (Rp)', 'Uang Makan (Rp)', 'Total Gaji (Rp)', 'Status',
            ],
            'baris' => $baris,
        ];
    }

    /** Kartu stok (histori mutasi keluar-masuk). */
    public function kartuStok(string $dari, string $sampai, ?KategoriStok $kategori = null, ?JenisMutasi $jenis = null): array
    {
        $query = KartuStok::query()
            ->whereBetween('tanggal', [$dari, $sampai])
            ->when($kategori !== null, fn ($q) => $q->where('kategori', $kategori->value))
            ->when($jenis !== null, fn ($q) => $q->where('jenis', $jenis->value))
            ->orderBy('tanggal')
            ->orderBy('id');

        $baris = (function () use ($query): Generator {
            foreach ($query->lazy(500) as $m) {
                yield [
                    $m->tanggal->toDateString(),
                    $m->jenis->label(),
                    $m->kategori->labelPanjang(),
                    CsvExport::angka($m->jumlah_kg),
                    CsvExport::angka($m->saldo_setelah),
                    $m->keterangan,
                ];
            }
        })();

        return [
            'namaFile' => CsvExport::namaFile('kartu-stok', $dari, $sampai),
            'judul' => $this->judul('KARTU STOK', $dari, $sampai),
            'kolom' => ['Tanggal', 'Jenis', 'Kategori', 'Jumlah (kg)', 'Saldo Setelah (kg)', 'Keterangan'],
            'baris' => $baris,
        ];
    }

    /** Rincian biaya operasional lain-lain. */
    public function biaya(string $dari, string $sampai, ?KategoriBiaya $kategori = null): array
    {
        $query = BiayaOperasional::query()
            ->whereBetween('tanggal', [$dari, $sampai])
            ->when($kategori !== null, fn ($q) => $q->where('kategori', $kategori->value))
            ->orderBy('tanggal')
            ->orderBy('id');

        $baris = (function () use ($query): Generator {
            $total = 0.0;

            foreach ($query->lazy(500) as $b) {
                $total += (float) $b->jumlah;

                yield [
                    $b->tanggal->toDateString(),
                    $b->keterangan,
                    $b->kategori->label(),
                    CsvExport::angka($b->jumlah),
                ];
            }

            yield ['', '', 'TOTAL', CsvExport::angka($total)];
        })();

        return [
            'namaFile' => CsvExport::namaFile('biaya-operasional', $dari, $sampai),
            'judul' => $this->judul('LAPORAN BIAYA OPERASIONAL', $dari, $sampai),
            'kolom' => ['Tanggal', 'Keterangan', 'Kategori', 'Jumlah (Rp)'],
            'baris' => $baris,
        ];
    }

    /** @return array<int, string> */
    private function judul(string $namaLaporan, string $dari, string $sampai): array
    {
        return [
            self::PERUSAHAAN,
            $namaLaporan,
            'Periode: '.RentangPeriode::label($dari, $sampai),
            'Diekspor: '.Periode::tanggalIndonesia(now()->toDateString()),
        ];
    }
}
