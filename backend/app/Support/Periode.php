<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Helper periode SIGULA.
 *
 * Periode gaji perusahaan adalah SENIN s.d. MINGGU (7 hari penuh). Ada
 * karyawan yang tetap memasak di hari Sabtu/Minggu, jadi periode tidak boleh
 * berhenti di Jumat — produksi akhir pekan dulu tidak masuk periode mana pun.
 *
 * Hari pembayarannya tidak tetap (bisa Jumat, Sabtu, atau Minggu); kalau gaji
 * sudah dibayar lalu karyawan masih bekerja, kekurangannya dibayar susulan
 * (lihat PenggajianService::bayar). Semua modul yang menyentuh periode
 * mingguan wajib lewat helper ini supaya definisinya konsisten.
 */
final class Periode
{
    public const HARI_PERIODE = 7;

    public static function tanggal(CarbonInterface|string|null $tanggal = null): CarbonImmutable
    {
        if ($tanggal === null) {
            return CarbonImmutable::today();
        }

        return $tanggal instanceof CarbonInterface
            ? CarbonImmutable::parse($tanggal->toDateString())
            : CarbonImmutable::parse($tanggal)->startOfDay();
    }

    /** Senin dari minggu yang memuat tanggal tersebut (Minggu ikut minggu sebelumnya). */
    public static function senin(CarbonInterface|string|null $tanggal = null): CarbonImmutable
    {
        return self::tanggal($tanggal)->startOfWeek(CarbonInterface::MONDAY);
    }

    /** Hari Minggu penutup periode yang memuat tanggal tersebut. */
    public static function minggu(CarbonInterface|string|null $tanggal = null): CarbonImmutable
    {
        return self::senin($tanggal)->addDays(self::HARI_PERIODE - 1);
    }

    /**
     * Rentang periode gaji Senin-Minggu.
     *
     * @return array{senin: CarbonImmutable, minggu: CarbonImmutable}
     */
    public static function mingguKerja(CarbonInterface|string|null $tanggal = null): array
    {
        $senin = self::senin($tanggal);

        return [
            'senin' => $senin,
            'minggu' => $senin->addDays(self::HARI_PERIODE - 1),
        ];
    }

    /**
     * Rentang satu bulan kalender.
     *
     * @return array{awal: CarbonImmutable, akhir: CarbonImmutable}
     */
    public static function bulan(CarbonInterface|string|null $tanggal = null): array
    {
        $ref = self::tanggal($tanggal);

        return [
            'awal' => $ref->startOfMonth(),
            'akhir' => $ref->endOfMonth()->startOfDay(),
        ];
    }

    /**
     * Daftar kunci bulan (Y-m) sebanyak $jumlah bulan terakhir, termasuk bulan acuan.
     *
     * @return array<int, string>
     */
    public static function bulanTerakhir(int $jumlah, CarbonInterface|string|null $sampai = null): array
    {
        $ref = self::tanggal($sampai)->startOfMonth();
        $keys = [];

        for ($i = $jumlah - 1; $i >= 0; $i--) {
            $keys[] = $ref->subMonths($i)->format('Y-m');
        }

        return $keys;
    }

    /** Label bulan singkat ala frontend, contoh "Agu 26". */
    public static function labelBulanSingkat(string $kunciBulan): string
    {
        $bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        [$tahun, $angkaBulan] = array_map('intval', explode('-', $kunciBulan));

        return $bulan[$angkaBulan - 1].' '.substr((string) $tahun, 2);
    }

    /** Tanggal ditulis gaya Indonesia, contoh "8 Agustus 2026". */
    public static function tanggalIndonesia(CarbonInterface|string $tanggal): string
    {
        $bulan = [
            'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
        ];
        $ref = self::tanggal($tanggal);

        return $ref->day.' '.$bulan[$ref->month - 1].' '.$ref->year;
    }
}
