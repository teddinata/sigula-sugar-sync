import { apiClient } from "@/lib/api-client";

/**
 * Bentuk data persis seperti folder "8. Penggajian" di
 * SIGULA.postman_collection.json, cross-check ke PenggajianController /
 * PenggajianService.
 *
 * Periode gaji: SENIN s.d. MINGGU (kerja Sabtu/Minggu ikut terhitung). Hari
 * bayarnya tidak tetap; kalau karyawan masih bekerja setelah gajinya dibayar,
 * selisihnya muncul sebagai `kurangBayar` dan dibayar lewat endpoint yang sama.
 * `tanggal` boleh tanggal MANA SAJA dalam minggu yang dimaksud.
 */

export interface BarisGaji {
  karyawanId: string;
  nama: string;
  kgKristal: number;
  kgBrondol: number;
  hariKerja: number;
  upahKristal: number;
  upahBrondol: number;
  uangMakan: number;
  /** Hasil hitungan sebelum dibulatkan ke kelipatan 500. */
  totalSebelumBulat: number;
  total: number;
  /** Lunas: pernah dibayar dan tidak ada kekurangan. */
  dibayar: boolean;
  /** Nominal yang sudah diserahkan sejauh ini (0 bila belum pernah dibayar). */
  sudahDibayarkan: number;
  /** Sisa yang belum dibayar: total - sudahDibayarkan. */
  kurangBayar: number;
  dibayarPada: string | null;
  /** Produksi berubah setelah gaji dibayar (snapshot vs live berbeda) — perlu ditinjau manual. */
  adaPerubahanSetelahDibayar: boolean;
}

export interface PeriodeGaji {
  senin: string;
  minggu: string;
  label: string;
}

export interface TarifGaji {
  kristal: number;
  brondol: number;
  uangMakan: number;
}

export interface RingkasanGaji {
  totalGaji: number;
  sudahDibayar: number;
  belumDibayar: number;
  jumlahKaryawan: number;
}

export interface RekapGajiMinggu {
  periode: PeriodeGaji;
  tarif: TarifGaji;
  baris: BarisGaji[];
  ringkasan: RingkasanGaji;
}

export interface RekapGajiParams {
  tanggal?: string | undefined;
  sertakanTanpaProduksi?: boolean | undefined;
}

export async function getRekapGaji(params: RekapGajiParams = {}): Promise<RekapGajiMinggu> {
  const res = await apiClient.get<{ data: RekapGajiMinggu }>("penggajian", {
    tanggal: params.tanggal,
    sertakanTanpaProduksi: params.sertakanTanpaProduksi ? 1 : undefined,
  });
  return res.data;
}

export interface SlipGaji {
  periode: PeriodeGaji;
  tarif: TarifGaji;
  baris: BarisGaji;
}

/** 422 bila karyawan tidak punya catatan produksi pada periode itu. */
export async function getSlipGaji(karyawanId: string, tanggal?: string): Promise<SlipGaji> {
  const res = await apiClient.get<{ data: SlipGaji }>(`penggajian/slip/${karyawanId}`, {
    tanggal,
  });
  return res.data;
}

export interface BayarGajiResult {
  karyawanId: string;
  periodeSenin: string;
  periodeMinggu: string;
  total: number;
  status: string;
  dibayarPada: string | null;
}

/**
 * Membayar gaji, atau kekurangannya bila sebagian sudah dibayar. Idempoten —
 * memanggil ulang saat tidak ada kekurangan tidak mengubah apa pun.
 */
export async function bayarGaji(
  karyawanId: string,
  tanggal?: string,
): Promise<{ message: string; data: BayarGajiResult }> {
  return apiClient.post<{ message: string; data: BayarGajiResult }>(
    `penggajian/${karyawanId}/bayar`,
    tanggal ? { tanggal } : {},
  );
}

export interface BayarSemuaResult {
  jumlahKaryawan: number;
  totalDibayar: number;
}

/** 422 bila tidak ada gaji yang perlu dibayarkan pada periode itu. */
export async function bayarSemuaGaji(
  tanggal?: string,
): Promise<{ message: string; data: BayarSemuaResult }> {
  return apiClient.post<{ message: string; data: BayarSemuaResult }>(
    "penggajian/bayar-semua",
    tanggal ? { tanggal } : {},
  );
}
