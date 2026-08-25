<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\JenisTarif;
use App\Enums\StatusGaji;
use App\Exceptions\BusinessRuleException;
use App\Models\GajiMingguan;
use App\Models\Karyawan;
use App\Models\ProduksiKaryawan;
use App\Models\User;
use App\Support\Pembulatan;
use App\Support\Periode;
use App\Support\TarifResolver;
use Illuminate\Support\Facades\DB;

/**
 * Penggajian mingguan.
 *
 * Periode gaji perusahaan: SENIN s.d. JUMAT, dibayarkan tiap Jumat.
 * Sumber data satu-satunya adalah produksi_karyawan (porsi hasil yang sudah
 * dibagi otomatis oleh modul produksi) — modul ini tidak membagi ulang, hanya
 * menjumlahkan.
 *
 * Aturan penting:
 * - Hari kerja = jumlah TANGGAL BERBEDA karyawan tercatat produksi. Ikut 3 sesi
 *   tungku di hari yang sama tetap dihitung 1 hari kerja.
 * - Tarif yang dipakai adalah tarif yang berlaku pada TANGGAL PRODUKSI, bukan
 *   tarif terbaru saat rekap dibuka.
 * - Begitu dibayar, angkanya dibekukan di gaji_mingguan.
 */
final class PenggajianService
{
    public function __construct(
        private readonly TarifService $tarif,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{
     *     periode: array{senin: string, jumat: string, label: string},
     *     tarif: array{kristal: float, brondol: float, uangMakan: float},
     *     baris: array<int, array<string, mixed>>,
     *     ringkasan: array{totalGaji: float, belumDibayar: float, sudahDibayar: float, jumlahKaryawan: int}
     * }
     */
    public function rekapMinggu(?string $tanggalDalamMinggu = null, bool $sertakanTanpaProduksi = false): array
    {
        $periode = Periode::mingguKerja($tanggalDalamMinggu);
        $senin = $periode['senin']->toDateString();
        $jumat = $periode['jumat']->toDateString();

        $hitungan = $this->hitungPerKaryawan($senin, $jumat);

        $snapshot = GajiMingguan::query()
            ->whereDate('periode_senin', $senin)
            ->get()
            ->keyBy('karyawan_id');

        $idKaryawan = array_unique([...array_keys($hitungan), ...$snapshot->keys()->all()]);

        $karyawan = $sertakanTanpaProduksi
            ? Karyawan::query()->aktif()->orderBy('nama')->get()->keyBy('id')
            : Karyawan::query()->withTrashed()->whereIn('id', $idKaryawan)->orderBy('nama')->get()->keyBy('id');

        $baris = [];

        foreach ($karyawan as $id => $orang) {
            $live = $hitungan[$id] ?? $this->barisKosong();
            /** @var GajiMingguan|null $dibayar */
            $dibayar = $snapshot->get($id);

            if ($dibayar === null && $live['total'] <= 0 && ! $sertakanTanpaProduksi) {
                continue;
            }

            $baris[] = $this->susunBaris($orang, $live, $dibayar);
        }

        usort($baris, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        $totalGaji = round(array_sum(array_column($baris, 'total')), 2);
        $sudahDibayar = round(array_sum(array_map(
            static fn (array $b): float => $b['dibayar'] ? (float) $b['total'] : 0.0,
            $baris
        )), 2);

        return [
            'periode' => [
                'senin' => $senin,
                'jumat' => $jumat,
                'label' => Periode::tanggalIndonesia($senin).' — '.Periode::tanggalIndonesia($jumat),
            ],
            'tarif' => [
                'kristal' => $this->tarif->tarifBerlaku(JenisTarif::KRISTAL, $jumat),
                'brondol' => $this->tarif->tarifBerlaku(JenisTarif::BRONDOL, $jumat),
                'uangMakan' => $this->tarif->tarifBerlaku(JenisTarif::UANG_MAKAN, $jumat),
            ],
            'baris' => $baris,
            'ringkasan' => [
                'totalGaji' => $totalGaji,
                'sudahDibayar' => $sudahDibayar,
                'belumDibayar' => round($totalGaji - $sudahDibayar, 2),
                'jumlahKaryawan' => count($baris),
            ],
        ];
    }

    /** Satu baris gaji karyawan pada periode tertentu (untuk slip gaji). */
    public function slip(int|string $karyawanId, ?string $tanggalDalamMinggu = null): array
    {
        $rekap = $this->rekapMinggu($tanggalDalamMinggu);

        foreach ($rekap['baris'] as $baris) {
            if ((string) $baris['karyawanId'] === (string) $karyawanId) {
                return [
                    'periode' => $rekap['periode'],
                    'tarif' => $rekap['tarif'],
                    'baris' => $baris,
                ];
            }
        }

        throw new BusinessRuleException(sprintf(
            'Karyawan tidak punya catatan produksi pada periode %s.',
            $rekap['periode']['label'],
        ));
    }

    /**
     * Membekukan dan menandai gaji satu karyawan sebagai sudah dibayar.
     * Idempoten: memanggil ulang untuk karyawan yang sudah dibayar tidak
     * mengubah apa pun dan tidak menimbulkan error.
     */
    public function bayar(int|string $karyawanId, ?string $tanggalDalamMinggu = null, ?User $user = null): GajiMingguan
    {
        $periode = Periode::mingguKerja($tanggalDalamMinggu);
        $senin = $periode['senin']->toDateString();
        $jumat = $periode['jumat']->toDateString();

        return DB::transaction(function () use ($karyawanId, $senin, $jumat, $user): GajiMingguan {
            $karyawan = Karyawan::query()->findOrFail($karyawanId);

            $existing = GajiMingguan::query()
                ->where('karyawan_id', $karyawan->id)
                ->whereDate('periode_senin', $senin)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->sudahDibayar()) {
                return $existing;
            }

            $hitungan = $this->hitungPerKaryawan($senin, $jumat)[$karyawan->id] ?? null;

            if ($hitungan === null || $hitungan['total'] <= 0) {
                throw new BusinessRuleException(sprintf(
                    'Tidak ada data produksi untuk %s pada periode %s — %s.',
                    $karyawan->nama,
                    Periode::tanggalIndonesia($senin),
                    Periode::tanggalIndonesia($jumat),
                ));
            }

            $atribut = [
                'periode_jumat' => $jumat,
                'kg_kristal' => $hitungan['kgKristal'],
                'kg_brondol' => $hitungan['kgBrondol'],
                'hari_kerja' => $hitungan['hariKerja'],
                'upah_kristal' => $hitungan['upahKristal'],
                'upah_brondol' => $hitungan['upahBrondol'],
                'uang_makan' => $hitungan['uangMakan'],
                'total' => $hitungan['total'],
                'total_sebelum_bulat' => $hitungan['totalSebelumBulat'],
                'status' => StatusGaji::SUDAH_DIBAYAR->value,
                'dibayar_pada' => now(),
                'dibayar_oleh' => $user?->getKey(),
            ];

            $gaji = $existing !== null
                ? tap($existing)->update($atribut)
                : GajiMingguan::create([
                    'karyawan_id' => $karyawan->id,
                    'periode_senin' => $senin,
                    ...$atribut,
                ]);

            $this->audit->catat(
                'gaji.bayar',
                sprintf(
                    'Gaji %s periode %s — %s dibayarkan: Rp %s',
                    $karyawan->nama,
                    Periode::tanggalIndonesia($senin),
                    Periode::tanggalIndonesia($jumat),
                    number_format($hitungan['total'], 0, ',', '.'),
                ),
                $gaji,
                $hitungan,
                $user,
            );

            return $gaji;
        });
    }

    /**
     * @return array<int, GajiMingguan>
     */
    public function bayarSemua(?string $tanggalDalamMinggu = null, ?User $user = null): array
    {
        $rekap = $this->rekapMinggu($tanggalDalamMinggu);
        $dibayar = [];

        foreach ($rekap['baris'] as $baris) {
            if ($baris['dibayar'] || (float) $baris['total'] <= 0) {
                continue;
            }

            $dibayar[] = $this->bayar($baris['karyawanId'], $rekap['periode']['senin'], $user);
        }

        if ($dibayar === []) {
            throw new BusinessRuleException(sprintf(
                'Tidak ada gaji yang perlu dibayarkan pada periode %s.',
                $rekap['periode']['label'],
            ));
        }

        return $dibayar;
    }

    /**
     * Total beban gaji (upah + uang makan) pada rentang tanggal — dipakai
     * sebagai komponen HPP di laporan laba rugi.
     *
     * @return array{upahKristal: float, upahBrondol: float, uangMakan: float, total: float}
     */
    public function totalGajiPeriode(string $dari, string $sampai): array
    {
        $perBulan = $this->totalGajiPerBulan($dari, $sampai);

        $total = ['upahKristal' => 0.0, 'upahBrondol' => 0.0, 'uangMakan' => 0.0, 'total' => 0.0];

        foreach ($perBulan as $nilai) {
            foreach ($total as $kunci => $angka) {
                $total[$kunci] = round($angka + $nilai[$kunci], 2);
            }
        }

        return $total;
    }

    /**
     * Beban gaji dikelompokkan per bulan (Y-m) dalam satu kali pembacaan data.
     *
     * @return array<string, array{upahKristal: float, upahBrondol: float, uangMakan: float, total: float}>
     */
    public function totalGajiPerBulan(string $dari, string $sampai): array
    {
        $resolver = TarifResolver::muatSemua();

        $rows = ProduksiKaryawan::query()
            ->whereBetween('tanggal', [$dari, $sampai])
            ->get(['karyawan_id', 'tanggal', 'kg_kristal_porsi', 'kg_brondol_porsi']);

        $hasil = [];
        $hariKerja = [];

        foreach ($rows as $row) {
            $tanggal = $row->tanggal->toDateString();
            $bulan = substr($tanggal, 0, 7);

            $hasil[$bulan] ??= ['upahKristal' => 0.0, 'upahBrondol' => 0.0, 'uangMakan' => 0.0, 'total' => 0.0];

            $hasil[$bulan]['upahKristal'] += (float) $row->kg_kristal_porsi * $resolver->nilai(JenisTarif::KRISTAL, $tanggal);
            $hasil[$bulan]['upahBrondol'] += (float) $row->kg_brondol_porsi * $resolver->nilai(JenisTarif::BRONDOL, $tanggal);

            // uang makan dihitung sekali per karyawan per tanggal
            $kunciHari = $row->karyawan_id.'|'.$tanggal;

            if (! isset($hariKerja[$kunciHari])) {
                $hariKerja[$kunciHari] = true;
                $hasil[$bulan]['uangMakan'] += $resolver->nilai(JenisTarif::UANG_MAKAN, $tanggal);
            }
        }

        foreach ($hasil as $bulan => $nilai) {
            $hasil[$bulan] = [
                'upahKristal' => round($nilai['upahKristal'], 2),
                'upahBrondol' => round($nilai['upahBrondol'], 2),
                'uangMakan' => round($nilai['uangMakan'], 2),
                'total' => round($nilai['upahKristal'] + $nilai['upahBrondol'] + $nilai['uangMakan'], 2),
            ];
        }

        return $hasil;
    }

    /**
     * Akumulasi produksi + upah per karyawan pada rentang tanggal.
     *
     * @return array<int, array{kgKristal: float, kgBrondol: float, hariKerja: int, upahKristal: float, upahBrondol: float, uangMakan: float, total: float}>
     */
    private function hitungPerKaryawan(string $dari, string $sampai): array
    {
        $resolver = TarifResolver::muatSemua();

        $rows = ProduksiKaryawan::query()
            ->whereBetween('tanggal', [$dari, $sampai])
            ->get(['karyawan_id', 'tanggal', 'kg_kristal_porsi', 'kg_brondol_porsi']);

        $acc = [];

        foreach ($rows as $row) {
            $id = (int) $row->karyawan_id;
            $tanggal = $row->tanggal->toDateString();

            $acc[$id] ??= [
                'kgKristal' => 0.0,
                'kgBrondol' => 0.0,
                'upahKristal' => 0.0,
                'upahBrondol' => 0.0,
                'uangMakan' => 0.0,
                'hari' => [],
            ];

            $acc[$id]['kgKristal'] += (float) $row->kg_kristal_porsi;
            $acc[$id]['kgBrondol'] += (float) $row->kg_brondol_porsi;
            $acc[$id]['upahKristal'] += (float) $row->kg_kristal_porsi * $resolver->nilai(JenisTarif::KRISTAL, $tanggal);
            $acc[$id]['upahBrondol'] += (float) $row->kg_brondol_porsi * $resolver->nilai(JenisTarif::BRONDOL, $tanggal);

            // Ikut beberapa sesi tungku pada hari yang sama tetap 1 hari kerja.
            if (! isset($acc[$id]['hari'][$tanggal])) {
                $acc[$id]['hari'][$tanggal] = true;
                $acc[$id]['uangMakan'] += $resolver->nilai(JenisTarif::UANG_MAKAN, $tanggal);
            }
        }

        $hasil = [];

        foreach ($acc as $id => $nilai) {
            $upahKristal = round($nilai['upahKristal'], 2);
            $upahBrondol = round($nilai['upahBrondol'], 2);
            $uangMakan = round($nilai['uangMakan'], 2);

            // Gaji yang dibayarkan dibulatkan ke kelipatan 500/1.000; nilai
            // hasil hitungan asli tetap dibawa untuk keperluan rekonsiliasi.
            $totalAsli = round($upahKristal + $upahBrondol + $uangMakan, 2);

            $hasil[$id] = [
                'kgKristal' => round($nilai['kgKristal'], 2),
                'kgBrondol' => round($nilai['kgBrondol'], 2),
                'hariKerja' => count($nilai['hari']),
                'upahKristal' => $upahKristal,
                'upahBrondol' => $upahBrondol,
                'uangMakan' => $uangMakan,
                'totalSebelumBulat' => $totalAsli,
                'total' => Pembulatan::keLimaRatus($totalAsli),
            ];
        }

        return $hasil;
    }

    /** @return array{kgKristal: float, kgBrondol: float, hariKerja: int, upahKristal: float, upahBrondol: float, uangMakan: float, total: float} */
    private function barisKosong(): array
    {
        return [
            'kgKristal' => 0.0,
            'kgBrondol' => 0.0,
            'hariKerja' => 0,
            'upahKristal' => 0.0,
            'upahBrondol' => 0.0,
            'uangMakan' => 0.0,
            'totalSebelumBulat' => 0.0,
            'total' => 0.0,
        ];
    }

    /**
     * @param  array{kgKristal: float, kgBrondol: float, hariKerja: int, upahKristal: float, upahBrondol: float, uangMakan: float, total: float}  $live
     * @return array<string, mixed>
     */
    private function susunBaris(Karyawan $karyawan, array $live, ?GajiMingguan $dibayar): array
    {
        $sudahDibayar = $dibayar?->sudahDibayar() ?? false;

        // Setelah dibayar, angka yang ditampilkan adalah snapshot pembayaran.
        $nilai = $sudahDibayar
            ? [
                'kgKristal' => (float) $dibayar->kg_kristal,
                'kgBrondol' => (float) $dibayar->kg_brondol,
                'hariKerja' => (int) $dibayar->hari_kerja,
                'upahKristal' => (float) $dibayar->upah_kristal,
                'upahBrondol' => (float) $dibayar->upah_brondol,
                'uangMakan' => (float) $dibayar->uang_makan,
                'totalSebelumBulat' => (float) ($dibayar->total_sebelum_bulat ?? $dibayar->total),
                'total' => (float) $dibayar->total,
            ]
            : $live;

        return [
            'karyawanId' => (string) $karyawan->id,
            'nama' => $karyawan->nama,
            ...$nilai,
            'dibayar' => $sudahDibayar,
            'dibayarPada' => $dibayar?->dibayar_pada?->toIso8601String(),
            // Menandai sesi produksi yang berubah setelah gaji dibayarkan,
            // supaya selisihnya bisa ditindaklanjuti manual oleh owner.
            'adaPerubahanSetelahDibayar' => $sudahDibayar && abs($live['total'] - (float) $dibayar->total) > 0.01,
        ];
    }
}
