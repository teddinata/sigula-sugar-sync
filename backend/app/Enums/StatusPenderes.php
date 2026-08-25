<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ResolvesFromInput;

/**
 * Status jenis penderes / pemilik lahan (istilah yang dipakai client).
 *
 * Satu petani bisa punya LEBIH DARI SATU status sekaligus (mis. PMS + PLMD),
 * dan kombinasinya tidak dibatasi — karena itu disimpan sebagai relasi banyak
 * baris di tabel `petani_status`, bukan satu kolom enum.
 */
enum StatusPenderes: string
{
    use ResolvesFromInput;

    case PMS = 'pms';
    case PMMS = 'pmms';
    case PLMR = 'plmr';
    case PLMD = 'plmd';
    case PLS = 'pls';
    case PL = 'pl';
    case PM = 'pm';

    public function label(): string
    {
        return strtoupper($this->value);
    }

    /** Keterangan lengkap untuk tooltip/legenda di UI. */
    public function keterangan(): string
    {
        return match ($this) {
            self::PMS => 'Penderes Milik Sendiri',
            self::PMMS => 'Penderes Maro dan Milik Sendiri',
            self::PLMR => 'Pemilik Lahan Maro (Masak Nira)',
            self::PLMD => 'Pemilik Lahan Mendreng (Bayar Gula)',
            self::PLS => 'Pemilik Lahan Sewa (Bayar Uang)',
            self::PL => 'Pemilik Lahan (Manggis)',
            self::PM => 'Penderes Maro',
        };
    }

    /** Urutan tampilan mengikuti daftar client (PMS, PMMS, PLMR, ...), bukan abjad. */
    public function urutan(): int
    {
        return (int) array_search($this, self::cases(), true);
    }

    /** Penderes aktif menyetor bahan mentah; pemilik lahan pasif tidak. */
    public function penderesAktif(): bool
    {
        return in_array($this, [self::PMS, self::PMMS, self::PM], true);
    }

    /**
     * Mengurai teks kombinasi dari data client, mis. "PMS + PLMR".
     *
     * @return array<int, self>
     */
    public static function dariTeksKombinasi(?string $teks): array
    {
        if (blank($teks)) {
            return [];
        }

        $hasil = [];

        foreach (preg_split('/[+,\/]/', $teks) ?: [] as $bagian) {
            $status = self::tryFromAny(trim($bagian));

            if ($status !== null && ! in_array($status, $hasil, true)) {
                $hasil[] = $status;
            }
        }

        return $hasil;
    }
}
