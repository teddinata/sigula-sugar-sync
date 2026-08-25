<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Grade;
use App\Enums\StatusPembayaran;
use App\Exceptions\BusinessRuleException;
use App\Models\Pembelian;
use App\Models\Pengepul;
use App\Models\Petani;
use App\Models\User;
use App\Support\Pembulatan;
use App\Support\Periode;
use Illuminate\Support\Facades\DB;

/** Pembelian bahan mentah dari petani + efek otomatis ke stok bahan mentah. */
final class PembelianService
{
    public function __construct(
        private readonly StokService $stok,
        private readonly HargaService $harga,
        private readonly NomorGeneratorService $nomor,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array{
     *     tanggal: string,
     *     petani_id: int|string,
     *     pengepul_id?: int|string|null,
     *     grade: Grade,
     *     kilogram: float,
     *     harga_per_kg?: float|null,
     *     status_pembayaran?: StatusPembayaran|null,
     *     catatan?: string|null
     * } $data
     */
    public function simpan(array $data, ?User $user = null): Pembelian
    {
        $kilogram = round((float) $data['kilogram'], 2);

        if ($kilogram <= 0) {
            throw BusinessRuleException::untukField('kilogram', 'Kilogram harus lebih dari 0.');
        }

        return DB::transaction(function () use ($data, $kilogram, $user): Pembelian {
            /** @var Grade $grade */
            $grade = $data['grade'];
            $tanggal = Periode::tanggal($data['tanggal']);
            $petani = Petani::query()->findOrFail($data['petani_id']);
            $pengepul = filled($data['pengepul_id'] ?? null)
                ? Pengepul::query()->findOrFail($data['pengepul_id'])
                : null;

            $hargaMaster = $this->harga->hargaBerlaku($grade, $tanggal);
            $hargaPerKg = isset($data['harga_per_kg']) && $data['harga_per_kg'] !== null
                ? round((float) $data['harga_per_kg'], 2)
                : $hargaMaster?->harga_per_kg;

            if ($hargaPerKg === null) {
                throw BusinessRuleException::untukField(
                    'hargaPerKg',
                    sprintf('Harga untuk grade %s belum diatur di Master Harga. Isi harga manual atau atur harga grade terlebih dahulu.', $grade->label())
                );
            }

            if ($hargaPerKg <= 0) {
                throw BusinessRuleException::untukField('hargaPerKg', 'Harga per kg harus lebih dari 0.');
            }

            // Nominal dibayar dibulatkan ke kelipatan 500/1.000; nilai asli
            // tetap disimpan supaya bisa direkonsiliasi dengan kg x harga.
            $totalAsli = round($kilogram * $hargaPerKg, 2);
            $totalBayar = Pembulatan::keLimaRatus($totalAsli);

            $pembelian = Pembelian::create([
                'nomor_kwitansi' => $this->nomor->kwitansiPembelian($tanggal),
                'tanggal' => $tanggal->toDateString(),
                'petani_id' => $petani->id,
                'pengepul_id' => $pengepul?->id,
                'grade' => $grade->value,
                'grade_harga_id' => $hargaMaster?->id,
                'kilogram' => $kilogram,
                'harga_per_kg' => $hargaPerKg,
                'total' => $totalBayar,
                'total_sebelum_bulat' => $totalAsli,
                'status_pembayaran' => ($data['status_pembayaran'] ?? StatusPembayaran::LUNAS)->value,
                'catatan' => $data['catatan'] ?? null,
                'user_id' => $user?->getKey(),
            ]);

            $this->stok->masuk(
                $grade->kategoriStok(),
                $kilogram,
                $tanggal,
                sprintf('Pembelian dari %s (%s)', $petani->nama, $pembelian->nomor_kwitansi),
                $pembelian,
                $user,
            );

            $this->audit->catat(
                'pembelian.simpan',
                sprintf('Pembelian %s dari %s: %s kg %s', $pembelian->nomor_kwitansi, $petani->nama, $kilogram, $grade->label()),
                $pembelian,
                [
                    'kilogram' => $kilogram,
                    'harga_per_kg' => $hargaPerKg,
                    'total' => (float) $pembelian->total,
                    'total_sebelum_bulat' => $totalAsli,
                    'pengepul' => $pengepul?->nama,
                    'harga_master' => $hargaMaster?->harga_per_kg,
                ],
                $user,
            );

            return $pembelian->load(['petani', 'pengepul', 'gradeHarga']);
        });
    }

    /**
     * Pembatalan transaksi: stok dikembalikan lewat mutasi balik (kartu stok
     * tetap utuh sebagai audit trail), lalu record di-soft delete.
     */
    public function batalkan(Pembelian $pembelian, ?User $user = null, ?string $alasan = null): void
    {
        DB::transaction(function () use ($pembelian, $user, $alasan): void {
            $terkunci = Pembelian::query()->whereKey($pembelian->getKey())->lockForUpdate()->firstOrFail();

            $this->stok->keluar(
                $terkunci->grade->kategoriStok(),
                (float) $terkunci->kilogram,
                $terkunci->tanggal,
                sprintf('Pembatalan pembelian %s', $terkunci->nomor_kwitansi),
                $terkunci,
                $user,
            );

            $this->audit->catat(
                'pembelian.batal',
                sprintf('Pembelian %s dibatalkan', $terkunci->nomor_kwitansi),
                $terkunci,
                ['alasan' => $alasan, 'total' => (float) $terkunci->total],
                $user,
            );

            $terkunci->delete();
        });
    }

    /**
     * @return array{hariIni: float, bulanIni: float, kgHariIni: float, kgBulanIni: float}
     */
    public function ringkasan(): array
    {
        $hariIni = Periode::tanggal()->toDateString();
        $bulan = Periode::bulan();

        $agregat = static fn (string $dari, string $sampai): array => (array) Pembelian::query()
            ->whereBetween('tanggal', [$dari, $sampai])
            ->selectRaw('COALESCE(SUM(total), 0) as total, COALESCE(SUM(kilogram), 0) as kg')
            ->first()
            ?->only(['total', 'kg']);

        $today = $agregat($hariIni, $hariIni);
        $month = $agregat($bulan['awal']->toDateString(), $bulan['akhir']->toDateString());

        return [
            'hariIni' => (float) ($today['total'] ?? 0),
            'kgHariIni' => (float) ($today['kg'] ?? 0),
            'bulanIni' => (float) ($month['total'] ?? 0),
            'kgBulanIni' => (float) ($month['kg'] ?? 0),
        ];
    }
}
