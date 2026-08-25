<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Grade;
use App\Enums\KategoriStok;
use App\Enums\StatusGaji;
use App\Enums\StatusSesi;
use App\Exceptions\BusinessRuleException;
use App\Models\GajiMingguan;
use App\Models\Karyawan;
use App\Models\ProduksiKaryawan;
use App\Models\SesiTungku;
use App\Models\SesiTungkuBahan;
use App\Models\User;
use App\Support\Periode;
use Illuminate\Support\Facades\DB;

/**
 * Modul produksi berbasis SESI TUNGKU.
 *
 * Satu sesi = satu tungku, tepat 2 karyawan, satu kali masak yang menghasilkan
 * gula kristal DAN brondol sekaligus (brondol adalah hasil sampingan pada sesi
 * yang sama, bukan sesi terpisah). Dalam satu hari belasan tungku berjalan
 * paralel. Saat sesi diselesaikan, bahan mentah dan hasil dibagi rata ke kedua
 * karyawan dan disimpan di produksi_karyawan sebagai sumber data penggajian.
 */
final class ProduksiService
{
    public function __construct(
        private readonly StokService $stok,
        private readonly NomorGeneratorService $nomor,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{
     *     tanggal: string,
     *     bahan?: array<int, array{grade: Grade, kg: float}>,
     *     grade?: Grade,
     *     kg_bahan_mentah?: float,
     *     karyawan_1_id: int|string,
     *     karyawan_2_id?: int|string|null,
     *     kode_tungku?: string|null,
     *     catatan?: string|null
     * }  $data
     */
    public function mulai(array $data, ?User $user = null): SesiTungku
    {
        $bahan = $this->normalisasiBahan($data);
        $kgTotal = round(array_sum(array_column($bahan, 'kg')), 2);

        $karyawan2Id = $data['karyawan_2_id'] ?? null;

        if ($karyawan2Id !== null && (string) $data['karyawan_1_id'] === (string) $karyawan2Id) {
            throw BusinessRuleException::untukField('karyawan2Id', 'Karyawan 1 dan Karyawan 2 tidak boleh orang yang sama.');
        }

        return DB::transaction(function () use ($data, $bahan, $kgTotal, $karyawan2Id, $user): SesiTungku {
            $tanggal = Periode::tanggal($data['tanggal']);

            $karyawan1 = Karyawan::query()->findOrFail($data['karyawan_1_id']);
            $karyawan2 = $karyawan2Id === null ? null : Karyawan::query()->findOrFail($karyawan2Id);

            // Stok tiap grade terpisah, jadi ketersediaannya dicek satu per satu.
            foreach ($bahan as $baris) {
                $this->pastikanBahanCukup($baris['grade'], $baris['kg']);
            }

            $sesi = SesiTungku::create([
                'tanggal' => $tanggal->toDateString(),
                'kode_tungku' => filled($data['kode_tungku'] ?? null)
                    ? trim((string) $data['kode_tungku'])
                    : $this->nomor->kodeTungku($tanggal),
                // Kolom ringkasan: grade utama (baris pertama) & TOTAL seluruh grade.
                'grade' => $bahan[0]['grade']->value,
                'kg_bahan_mentah' => $kgTotal,
                'karyawan_1_id' => $karyawan1->id,
                'karyawan_2_id' => $karyawan2?->id,
                'status' => StatusSesi::SEDANG_DIPROSES->value,
                'catatan' => $data['catatan'] ?? null,
                'user_id' => $user?->getKey(),
            ]);

            foreach ($bahan as $baris) {
                SesiTungkuBahan::create([
                    'sesi_tungku_id' => $sesi->id,
                    'grade' => $baris['grade']->value,
                    'kg' => $baris['kg'],
                ]);
            }

            $rincian = implode(' + ', array_map(
                static fn (array $b): string => sprintf('%s kg %s', $b['kg'], $b['grade']->label()),
                $bahan,
            ));

            $this->audit->catat(
                'produksi.mulai',
                sprintf(
                    'Sesi tungku %s dimulai: %s oleh %s',
                    $sesi->kode_tungku,
                    $rincian,
                    $karyawan2 === null ? $karyawan1->nama : $karyawan1->nama.' & '.$karyawan2->nama,
                ),
                $sesi,
                [
                    'bahan' => array_map(
                        static fn (array $b): array => ['grade' => $b['grade']->value, 'kg' => $b['kg']],
                        $bahan,
                    ),
                    'kg_total' => $kgTotal,
                ],
                $user,
            );

            return $sesi->load(['karyawan1', 'karyawan2', 'bahan']);
        });
    }

    /**
     * Menyeragamkan input bahan mentah.
     *
     * Menerima bentuk baru (`bahan` sebagai array beberapa grade) maupun bentuk
     * lama (`grade` + `kg_bahan_mentah` tunggal) supaya pemanggil lama —
     * seeder, perintah artisan, test — tidak perlu ikut diubah.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{grade: Grade, kg: float}>
     */
    private function normalisasiBahan(array $data): array
    {
        $mentah = $data['bahan'] ?? null;

        if ($mentah === null && isset($data['grade'])) {
            $mentah = [['grade' => $data['grade'], 'kg' => $data['kg_bahan_mentah'] ?? 0]];
        }

        if (! is_array($mentah) || $mentah === []) {
            throw BusinessRuleException::untukField('bahan', 'Bahan mentah wajib diisi minimal satu grade.');
        }

        $hasil = [];

        foreach ($mentah as $baris) {
            $grade = $baris['grade'] instanceof Grade ? $baris['grade'] : Grade::fromAny($baris['grade']);
            $kg = round((float) ($baris['kg'] ?? 0), 2);

            if ($kg <= 0) {
                throw BusinessRuleException::untukField(
                    'kgBahan',
                    sprintf('Kg bahan mentah %s harus lebih dari 0.', $grade->label()),
                );
            }

            if (isset($hasil[$grade->value])) {
                throw BusinessRuleException::untukField(
                    'bahan',
                    sprintf('Grade %s hanya boleh diisi satu kali dalam satu sesi.', $grade->label()),
                );
            }

            $hasil[$grade->value] = ['grade' => $grade, 'kg' => $kg];
        }

        return array_values($hasil);
    }

    /**
     * Menutup sesi: catat hasil, hitung rendemen, potong stok bahan mentah,
     * tambah stok produk jadi, dan bagi rata hasil ke 2 karyawan.
     */
    public function selesaikan(SesiTungku $sesi, float $kgKristal, float $kgBrondol, ?User $user = null): SesiTungku
    {
        $kgKristal = round($kgKristal, 2);
        $kgBrondol = round($kgBrondol, 2);

        if ($kgKristal < 0 || $kgBrondol < 0) {
            throw new BusinessRuleException('Hasil produksi tidak boleh negatif.');
        }

        if ($kgKristal + $kgBrondol <= 0) {
            throw BusinessRuleException::untukField('kgKristal', 'Total hasil produksi harus lebih dari 0.');
        }

        return DB::transaction(function () use ($sesi, $kgKristal, $kgBrondol, $user): SesiTungku {
            /** @var SesiTungku $terkunci */
            $terkunci = SesiTungku::query()->whereKey($sesi->getKey())->lockForUpdate()->firstOrFail();
            $terkunci->load('bahan');

            if ($terkunci->status === StatusSesi::SELESAI) {
                throw new BusinessRuleException(sprintf('Sesi tungku %s sudah berstatus selesai.', $terkunci->kode_tungku));
            }

            $kgBahan = (float) $terkunci->kg_bahan_mentah;
            $totalHasil = round($kgKristal + $kgBrondol, 2);

            // Tidak ada batas atas rendemen: di lapangan kadang ditambahkan gula
            // lain di luar NS1/NS2 yang tidak tercatat, sehingga hasil bisa
            // melebihi bahan mentah. Persentasenya tetap dihitung & ditampilkan.

            $terkunci->kg_kristal_total = $kgKristal;
            $terkunci->kg_brondol_total = $kgBrondol;
            $terkunci->rendemen = round($totalHasil / $kgBahan * 100, 2);
            $terkunci->status = StatusSesi::SELESAI;
            $terkunci->selesai_pada = now();
            $terkunci->save();

            $label = sprintf('tungku %s', $terkunci->kode_tungku);

            // Stok tiap grade terpisah, jadi dipotong per baris rincian bahan.
            foreach ($terkunci->bahan as $baris) {
                $this->stok->keluar(
                    $baris->grade->kategoriStok(),
                    (float) $baris->kg,
                    $terkunci->tanggal,
                    'Produksi '.$label,
                    $terkunci,
                    $user,
                );
            }

            if ($kgKristal > 0) {
                $this->stok->masuk(KategoriStok::KRISTAL, $kgKristal, $terkunci->tanggal, 'Hasil '.$label, $terkunci, $user);
            }

            if ($kgBrondol > 0) {
                $this->stok->masuk(KategoriStok::BRONDOL, $kgBrondol, $terkunci->tanggal, 'Hasil '.$label, $terkunci, $user);
            }

            $this->simpanPorsiKaryawan($terkunci);

            $this->audit->catat(
                'produksi.selesai',
                sprintf(
                    'Sesi tungku %s selesai: %s kg kristal + %s kg brondol (rendemen %s%%)',
                    $terkunci->kode_tungku, $kgKristal, $kgBrondol, $terkunci->rendemen
                ),
                $terkunci,
                [
                    'kg_bahan_mentah' => $kgBahan,
                    'kg_kristal_total' => $kgKristal,
                    'kg_brondol_total' => $kgBrondol,
                    'rendemen' => $terkunci->rendemen,
                ],
                $user,
            );

            return $terkunci->load(['karyawan1', 'karyawan2', 'porsiKaryawan', 'bahan']);
        });
    }

    /**
     * Mencatat porsi hasil tiap karyawan untuk keperluan penggajian.
     *
     * Dua karyawan: dibagi rata. Porsi kedua dihitung sebagai sisa
     * (total − porsi pertama) supaya jumlah keduanya selalu persis sama dengan
     * total sesi walau angkanya ganjil.
     *
     * Satu karyawan: seluruh hasil menjadi porsinya, tanpa pembagian.
     */
    private function simpanPorsiKaryawan(SesiTungku $sesi): void
    {
        $sesi->porsiKaryawan()->delete();

        $bahan = (float) $sesi->kg_bahan_mentah;
        $kristal = (float) $sesi->kg_kristal_total;
        $brondol = (float) $sesi->kg_brondol_total;

        if ($sesi->karyawan_2_id === null) {
            $porsi = [[$sesi->karyawan_1_id, $bahan, $kristal, $brondol]];
        } else {
            $bagi = static function (float $total): array {
                $pertama = round($total / 2, 2);

                return [$pertama, round($total - $pertama, 2)];
            };

            [$bahan1, $bahan2] = $bagi($bahan);
            [$kristal1, $kristal2] = $bagi($kristal);
            [$brondol1, $brondol2] = $bagi($brondol);

            $porsi = [
                [$sesi->karyawan_1_id, $bahan1, $kristal1, $brondol1],
                [$sesi->karyawan_2_id, $bahan2, $kristal2, $brondol2],
            ];
        }

        foreach ($porsi as [$karyawanId, $kgBahan, $kgKristal, $kgBrondol]) {
            ProduksiKaryawan::create([
                'sesi_tungku_id' => $sesi->id,
                'karyawan_id' => $karyawanId,
                'tanggal' => $sesi->tanggal->toDateString(),
                'kg_bahan_mentah_porsi' => $kgBahan,
                'kg_kristal_porsi' => $kgKristal,
                'kg_brondol_porsi' => $kgBrondol,
            ]);
        }
    }

    /**
     * Membatalkan sesi. Untuk sesi yang sudah selesai, seluruh efek stok
     * dikembalikan lewat mutasi balik dan porsi karyawan dihapus — kecuali gaji
     * periode tersebut sudah dibayar (histori pembayaran tidak boleh berubah).
     */
    public function batalkan(SesiTungku $sesi, ?User $user = null, ?string $alasan = null): void
    {
        DB::transaction(function () use ($sesi, $user, $alasan): void {
            /** @var SesiTungku $terkunci */
            $terkunci = SesiTungku::query()->whereKey($sesi->getKey())->lockForUpdate()->firstOrFail();
            $terkunci->load('bahan');

            if ($terkunci->status === StatusSesi::SELESAI) {
                $this->pastikanGajiBelumDibayar($terkunci);

                $kgKristal = (float) ($terkunci->kg_kristal_total ?? 0);
                $kgBrondol = (float) ($terkunci->kg_brondol_total ?? 0);
                $label = sprintf('sesi tungku %s', $terkunci->kode_tungku);

                if ($kgKristal > 0) {
                    $this->stok->keluar(KategoriStok::KRISTAL, $kgKristal, $terkunci->tanggal, 'Pembatalan '.$label, $terkunci, $user);
                }

                if ($kgBrondol > 0) {
                    $this->stok->keluar(KategoriStok::BRONDOL, $kgBrondol, $terkunci->tanggal, 'Pembatalan '.$label, $terkunci, $user);
                }

                foreach ($terkunci->bahan as $baris) {
                    $this->stok->masuk(
                        $baris->grade->kategoriStok(),
                        (float) $baris->kg,
                        $terkunci->tanggal,
                        'Pengembalian bahan '.$label,
                        $terkunci,
                        $user,
                    );
                }

                $terkunci->porsiKaryawan()->delete();
            }

            $this->audit->catat(
                'produksi.batal',
                sprintf('Sesi tungku %s dibatalkan', $terkunci->kode_tungku),
                $terkunci,
                ['alasan' => $alasan, 'status_sebelumnya' => $terkunci->status->value],
                $user,
            );

            $terkunci->delete();
        });
    }

    /**
     * Ringkasan produksi satu hari (default hari ini).
     *
     * @return array{tanggal: string, jumlahSesi: int, tungkuAktif: int, kgBahan: float, kgKristal: float, kgBrondol: float, totalProduksi: float, rendemen: float|null}
     */
    public function ringkasanHarian(?string $tanggal = null): array
    {
        $tanggal = Periode::tanggal($tanggal)->toDateString();

        $sesi = SesiTungku::query()->whereDate('tanggal', $tanggal)->get();
        $selesai = $sesi->where('status', StatusSesi::SELESAI);

        $kgBahan = (float) $selesai->sum('kg_bahan_mentah');
        $kgKristal = (float) $selesai->sum('kg_kristal_total');
        $kgBrondol = (float) $selesai->sum('kg_brondol_total');

        return [
            'tanggal' => $tanggal,
            'jumlahSesi' => $sesi->count(),
            'tungkuAktif' => $sesi->where('status', StatusSesi::SEDANG_DIPROSES)->count(),
            'kgBahan' => round((float) $sesi->sum('kg_bahan_mentah'), 2),
            'kgKristal' => round($kgKristal, 2),
            'kgBrondol' => round($kgBrondol, 2),
            'totalProduksi' => round($kgKristal + $kgBrondol, 2),
            'rendemen' => $kgBahan > 0 ? round(($kgKristal + $kgBrondol) / $kgBahan * 100, 2) : null,
        ];
    }

    /**
     * Rendemen harian untuk grafik tren.
     *
     * @return array<int, array{tanggal: string, rendemen: float, kgBahan: float, kgHasil: float}>
     */
    public function trenRendemen(int $jumlahHari = 14, ?string $sampai = null): array
    {
        $akhir = Periode::tanggal($sampai);
        $awal = $akhir->subDays(max(1, $jumlahHari) - 1);

        $sesi = SesiTungku::query()
            ->selesai()
            ->whereBetween('tanggal', [$awal->toDateString(), $akhir->toDateString()])
            ->get(['tanggal', 'kg_bahan_mentah', 'kg_kristal_total', 'kg_brondol_total'])
            ->groupBy(fn (SesiTungku $s): string => $s->tanggal->toDateString());

        $hasil = [];

        for ($i = 0; $i < $jumlahHari; $i++) {
            $tanggal = $awal->addDays($i)->toDateString();
            $rows = $sesi->get($tanggal);

            $kgBahan = $rows ? (float) $rows->sum('kg_bahan_mentah') : 0.0;
            $kgHasil = $rows ? (float) $rows->sum('kg_kristal_total') + (float) $rows->sum('kg_brondol_total') : 0.0;

            $hasil[] = [
                'tanggal' => $tanggal,
                'kgBahan' => round($kgBahan, 2),
                'kgHasil' => round($kgHasil, 2),
                'rendemen' => $kgBahan > 0 ? round($kgHasil / $kgBahan * 100, 1) : 0.0,
            ];
        }

        return $hasil;
    }

    /**
     * Bahan mentah yang benar-benar bisa dipakai sesi baru = saldo stok dikurangi
     * kg yang masih "dipegang" sesi lain yang belum selesai. Tanpa ini, 15 tungku
     * bisa sama-sama dimulai padahal bahan hanya cukup untuk 10.
     */
    private function pastikanBahanCukup(Grade $grade, float $kgBahan): void
    {
        $saldo = $this->stok->saldo($grade->kategoriStok());

        $sedangDiproses = (float) SesiTungku::query()
            ->where('grade', $grade->value)
            ->where('status', StatusSesi::SEDANG_DIPROSES->value)
            ->sum('kg_bahan_mentah');

        $tersedia = round($saldo - $sedangDiproses, 2);

        if ($kgBahan > $tersedia) {
            throw BusinessRuleException::untukField('kgBahan', sprintf(
                'Stok bahan %s tidak mencukupi. Saldo %s kg, %s kg sedang dipakai tungku lain, sisa yang bisa dipakai %s kg.',
                $grade->label(),
                $this->format($saldo),
                $this->format($sedangDiproses),
                $this->format(max($tersedia, 0)),
            ));
        }
    }

    private function pastikanGajiBelumDibayar(SesiTungku $sesi): void
    {
        $periode = Periode::mingguKerja($sesi->tanggal);

        $sudahDibayar = GajiMingguan::query()
            ->whereIn('karyawan_id', array_filter([$sesi->karyawan_1_id, $sesi->karyawan_2_id]))
            ->whereDate('periode_senin', $periode['senin']->toDateString())
            ->where('status', StatusGaji::SUDAH_DIBAYAR->value)
            ->exists();

        if ($sudahDibayar) {
            throw new BusinessRuleException(sprintf(
                'Sesi tidak bisa dibatalkan karena gaji periode %s — %s untuk karyawan terkait sudah dibayarkan.',
                Periode::tanggalIndonesia($periode['senin']),
                Periode::tanggalIndonesia($periode['jumat']),
            ));
        }
    }

    private function format(float $nilai): string
    {
        return number_format($nilai, fmod($nilai, 1.0) === 0.0 ? 0 : 2, ',', '.');
    }
}
