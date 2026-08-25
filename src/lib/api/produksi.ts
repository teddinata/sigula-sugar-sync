import { apiClient } from "@/lib/api-client";
import type { Grade } from "@/lib/sigula-types";

/**
 * Bentuk data persis seperti folder "7. Produksi — Sesi Tungku" di
 * SIGULA.postman_collection.json, cross-check ke SesiTungkuController /
 * MulaiSesiTungkuRequest / SelesaikanSesiTungkuRequest / SesiTungkuResource /
 * ProduksiService.
 *
 * Alur status: `Sedang Diproses` (dibuat lewat mulai(), stok BELUM terpotong)
 * → `Selesai` (lewat selesaikan(), baru di sinilah stok bahan berkurang, stok
 * kristal/brondol bertambah, dan hasil dibagi rata ke 2 karyawan). "Batal"
 * bukan status ketiga — DELETE menghapus barisnya langsung (membalik efek
 * stok dulu bila sudah Selesai), diblokir 422 bila gaji minggu itu sudah dibayar.
 */

export type StatusSesi = "Sedang Diproses" | "Selesai";
export type StatusSesiKode = "sedang_diproses" | "selesai";

export interface KaryawanRingkas {
  id: string;
  nama: string | null;
}

export interface PorsiKaryawan {
  karyawanId: string;
  kgBahan: number;
  kgKristal: number;
  kgBrondol: number;
}

/** Satu baris bahan mentah; satu tungku boleh memakai beberapa grade sekaligus. */
export interface BahanSesi {
  grade: Grade;
  gradeKode: "ns1" | "ns2" | "kecap";
  kg: number;
}

export interface SesiTungku {
  id: string;
  tanggal: string;
  tanggalLabel: string;
  kodeTungku: string;
  /** Grade utama (baris pertama) — rincian lengkapnya di `bahan`. */
  grade: Grade;
  gradeKode: "ns1" | "ns2" | "kecap";
  /** TOTAL seluruh grade. */
  kgBahan: number;
  bahan?: BahanSesi[];
  /** Satu tungku boleh dikerjakan 1 atau 2 orang, jadi panjangnya 1 atau 2. */
  karyawanIds: string[];
  /** Nama karyawan yang mengerjakan — eager-loaded di index/show/store/selesai. */
  karyawan?: KaryawanRingkas[];
  kgKristal: number | null;
  kgBrondol: number | null;
  rendemen: number | null;
  status: StatusSesi;
  statusKode: StatusSesiKode;
  selesaiPada: string | null;
  catatan: string | null;
  /** Hasil bagi rata ke 2 karyawan — hanya ada di detail (show) & setelah selesaikan(). */
  porsiKaryawan?: PorsiKaryawan[];
}

export interface ProduksiRingkasan {
  tanggal: string;
  jumlahSesi: number;
  tungkuAktif: number;
  kgBahan: number;
  kgKristal: number;
  kgBrondol: number;
  totalProduksi: number;
  rendemen: number | null;
}

/** Bentuk default Laravel `paginate()->toResourceCollection()` — snake_case. */
export interface SesiMeta {
  current_page: number;
  from: number | null;
  last_page: number;
  per_page: number;
  to: number | null;
  total: number;
}

export interface SesiListParams {
  tanggal?: string | undefined;
  dari?: string | undefined;
  sampai?: string | undefined;
  status?: StatusSesi | undefined;
  grade?: Grade | undefined;
  karyawanId?: string | undefined;
  q?: string | undefined;
  page?: number | undefined;
  perPage?: number | undefined;
}

export interface SesiListResult {
  data: SesiTungku[];
  meta: SesiMeta;
  ringkasan: ProduksiRingkasan;
}

export interface BahanPayload {
  grade: Grade;
  kg: number;
}

export interface MulaiSesiPayload {
  tanggal: string;
  /** Kosongkan (omit/null) → backend generate otomatis "TGK-01", "TGK-02", ... per hari. */
  kodeTungku?: string | null | undefined;
  /** Boleh lebih dari satu grade dalam satu tungku. */
  bahan: BahanPayload[];
  karyawan1Id: string;
  /** Opsional: ada tungku yang dikerjakan satu orang saja. */
  karyawan2Id?: string | undefined;
  catatan?: string | undefined;
}

export interface SelesaikanSesiPayload {
  /** Kg hasil untuk SATU TUNGKU (gabungan 2 karyawan), bukan per orang. */
  kgKristal?: number | undefined;
  kgBrondol?: number | undefined;
}

export interface TrenRendemenHari {
  tanggal: string;
  kgBahan: number;
  kgHasil: number;
  rendemen: number;
}

export async function getSesiList(params: SesiListParams = {}): Promise<SesiListResult> {
  return apiClient.get<SesiListResult>("produksi/sesi", {
    tanggal: params.tanggal,
    dari: params.dari,
    sampai: params.sampai,
    status: params.status,
    grade: params.grade,
    karyawanId: params.karyawanId,
    q: params.q,
    page: params.page,
    perPage: params.perPage,
  });
}

export async function getSesi(id: string): Promise<SesiTungku> {
  const res = await apiClient.get<{ data: SesiTungku }>(`produksi/sesi/${id}`);
  return res.data;
}

export async function mulaiSesi(
  payload: MulaiSesiPayload,
): Promise<{ sesi: SesiTungku; message: string }> {
  const res = await apiClient.post<{ data: SesiTungku; message: string }>("produksi/sesi", payload);
  return { sesi: res.data, message: res.message };
}

export async function selesaikanSesi(
  id: string,
  payload: SelesaikanSesiPayload,
): Promise<{ sesi: SesiTungku; message: string }> {
  const res = await apiClient.post<{ data: SesiTungku; message: string }>(
    `produksi/sesi/${id}/selesai`,
    payload,
  );
  return { sesi: res.data, message: res.message };
}

export async function batalkanSesi(id: string, alasan?: string): Promise<{ message: string }> {
  return apiClient.delete<{ message: string }>(
    `produksi/sesi/${id}`,
    alasan ? { alasan } : undefined,
  );
}

export async function getTrenRendemen(hari = 14, sampai?: string): Promise<TrenRendemenHari[]> {
  const res = await apiClient.get<{ data: TrenRendemenHari[] }>("produksi/tren-rendemen", {
    hari,
    sampai,
  });
  return res.data;
}
