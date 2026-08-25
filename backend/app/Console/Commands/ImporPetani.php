<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\StatusPenderes;
use App\Enums\StatusPetani;
use App\Models\Petani;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Impor data petani dari CSV milik client (mis. data-petani-batuanten.csv).
 *
 * Kolomnya ditebak dari header, jadi urutan kolom bebas dan variasi penamaan
 * yang lazim ("nama petani", "no", "rt/rw", "status") tetap terbaca. Kolom
 * status boleh berisi kombinasi seperti "PMS + PLMR" — dipecah otomatis.
 *
 * Idempoten: baris yang kode lahannya (atau namanya, bila kode lahan kosong)
 * sudah ada akan DIPERBARUI, bukan diduplikasi. Jalankan --uji-coba dulu untuk
 * melihat hasilnya tanpa menyentuh database.
 */
class ImporPetani extends Command
{
    protected $signature = 'sigula:impor-petani
        {file : Path file CSV}
        {--pemisah= : Pemisah kolom (default: dideteksi dari baris header)}
        {--uji-coba : Tampilkan hasil tanpa menyimpan apa pun}';

    protected $description = 'Mengimpor data petani (nama, kode lahan, RT/RW, status penderes) dari CSV';

    /** Nama kolom yang dikenali, dipetakan ke field internal. */
    private const KOLOM = [
        'nama' => ['nama', 'nama petani', 'nama_petani', 'petani'],
        'kode_lahan' => ['kode lahan', 'kode_lahan', 'kodelahan', 'kode', 'no lahan'],
        'rt_rw' => ['rt/rw', 'rt rw', 'rt_rw', 'rtrw'],
        'status_penderes' => ['status', 'status penderes', 'status_penderes', 'jenis', 'keterangan'],
        'nomor_member' => ['nomor member', 'no member', 'nomor_member', 'no'],
        'kontak' => ['kontak', 'hp', 'no hp', 'telepon', 'wa'],
        'alamat' => ['alamat', 'desa', 'dusun'],
    ];

    public function handle(): int
    {
        $file = (string) $this->argument('file');

        if (! is_readable($file)) {
            $this->error("File tidak ditemukan atau tidak bisa dibaca: {$file}");

            return self::FAILURE;
        }

        $baris = $this->bacaCsv($file);

        if ($baris === []) {
            $this->error('CSV kosong atau headernya tidak dikenali.');

            return self::FAILURE;
        }

        $ujiCoba = (bool) $this->option('uji-coba');
        $baru = 0;
        $diperbarui = 0;
        $dilewati = [];

        $simpan = function () use ($baris, &$baru, &$diperbarui, &$dilewati): void {
            foreach ($baris as $nomor => $data) {
                $nama = trim((string) ($data['nama'] ?? ''));

                if ($nama === '') {
                    $dilewati[] = "baris {$nomor}: nama kosong";

                    continue;
                }

                $kodeLahan = trim((string) ($data['kode_lahan'] ?? '')) ?: null;

                // Kode lahan unik, jadi jadi kunci utama pencocokan; kalau kosong
                // pakai nama supaya menjalankan ulang tidak menggandakan data.
                $petani = $kodeLahan !== null
                    ? Petani::query()->where('kode_lahan', $kodeLahan)->first()
                    : Petani::query()->where('nama', $nama)->first();

                $nomorMember = trim((string) ($data['nomor_member'] ?? '')) ?: null;
                $nomorMember = $nomorMember !== null && preg_match('/^\d{3}$/', $nomorMember) === 1
                    ? $nomorMember
                    : null;

                $atribut = [
                    'nama' => $nama,
                    'kode_lahan' => $kodeLahan,
                    'rt_rw' => trim((string) ($data['rt_rw'] ?? '')) ?: null,
                    'kontak' => trim((string) ($data['kontak'] ?? '')) ?: null,
                    'alamat' => trim((string) ($data['alamat'] ?? '')) ?: null,
                    'status' => $nomorMember !== null
                        ? StatusPetani::MEMBER->value
                        : StatusPetani::NON_MEMBER->value,
                    'nomor_member' => $nomorMember,
                ];

                if ($petani === null) {
                    $petani = Petani::create($atribut);
                    $baru++;
                } else {
                    $petani->update($atribut);
                    $diperbarui++;
                }

                $status = StatusPenderes::dariTeksKombinasi($data['status_penderes'] ?? null);

                if ($status !== []) {
                    $petani->statusPenderes()->delete();

                    foreach ($status as $kode) {
                        $petani->statusPenderes()->create(['kode' => $kode->value]);
                    }
                }
            }
        };

        if ($ujiCoba) {
            // Dijalankan sungguhan lalu di-rollback: hitungannya akurat tanpa
            // meninggalkan perubahan di database.
            DB::beginTransaction();

            try {
                $simpan();
            } finally {
                DB::rollBack();
            }
        } else {
            DB::transaction($simpan);
        }

        $this->table(
            ['Baru', 'Diperbarui', 'Dilewati'],
            [[$baru, $diperbarui, count($dilewati)]],
        );

        foreach ($dilewati as $alasan) {
            $this->warn('  dilewati — '.$alasan);
        }

        if ($ujiCoba) {
            $this->info('Uji coba: tidak ada perubahan yang disimpan.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, string>> nomor baris => data per field
     */
    private function bacaCsv(string $file): array
    {
        $handle = fopen($file, 'r');

        if ($handle === false) {
            return [];
        }

        $isi = [];

        try {
            $pemisah = $this->pemisah($file);
            $header = fgetcsv($handle, separator: $pemisah, escape: '');

            if ($header === false || $header === [null]) {
                return [];
            }

            $peta = $this->petakanHeader($header);

            if ($peta === []) {
                return [];
            }

            $nomor = 1;

            while (($kolom = fgetcsv($handle, separator: $pemisah, escape: '')) !== false) {
                $nomor++;

                if ($kolom === [null] || $kolom === []) {
                    continue;
                }

                $data = [];

                foreach ($peta as $index => $field) {
                    $data[$field] = (string) ($kolom[$index] ?? '');
                }

                if (implode('', $data) === '') {
                    continue;
                }

                $isi[$nomor] = $data;
            }
        } finally {
            fclose($handle);
        }

        return $isi;
    }

    /** Menebak pemisah dari baris header: yang paling banyak muncul yang dipakai. */
    private function pemisah(string $file): string
    {
        if (filled($this->option('pemisah'))) {
            return (string) $this->option('pemisah');
        }

        $handle = fopen($file, 'r');
        $header = $handle === false ? '' : (string) fgets($handle);

        if ($handle !== false) {
            fclose($handle);
        }

        $kandidat = [',' => substr_count($header, ','), ';' => substr_count($header, ';'), "\t" => substr_count($header, "\t")];
        arsort($kandidat);

        return (string) array_key_first($kandidat);
    }

    /**
     * @param  array<int, string|null>  $header
     * @return array<int, string> index kolom => field internal
     */
    private function petakanHeader(array $header): array
    {
        $peta = [];

        foreach ($header as $index => $judul) {
            $bersih = mb_strtolower(trim((string) $judul));
            $bersih = preg_replace('/^\x{FEFF}/u', '', $bersih) ?? $bersih;

            foreach (self::KOLOM as $field => $alias) {
                if (in_array($bersih, $alias, true) && ! in_array($field, $peta, true)) {
                    $peta[$index] = $field;
                    break;
                }
            }
        }

        return in_array('nama', $peta, true) ? $peta : [];
    }
}
