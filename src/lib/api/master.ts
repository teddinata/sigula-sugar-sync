import { apiClient } from "@/lib/api-client";
import type { Grade } from "@/lib/sigula-types";

/**
 * Bentuk data persis seperti folder "4. Master Harga, Tarif, Karyawan & Eksportir"
 * di SIGULA.postman_collection.json, cross-check ke MasterHargaController,
 * MasterTarifController, KaryawanController, EksportirController.
 */

// ---- Harga beli per grade --------------------------------------------------

export type GradeKode = "ns1" | "ns2" | "kecap";

export interface HargaGrade {
  kode: GradeKode;
  nama: Grade;
  hargaPerKg: number | null;
  berlakuDari: string | null;
}

export interface RiwayatHarga {
  id: string;
  grade: Grade;
  gradeKode: GradeKode;
  tanggal: string;
  hargaLama: number | null;
  hargaBaru: number;
  catatan: string | null;
}

export interface HargaData {
  /** Sama persis dengan state `hargaBeli` lama di frontend: { "NS 1": 14500, ... }. */
  hargaBeli: Record<Grade, number | null>;
  grade: HargaGrade[];
  riwayat: RiwayatHarga[];
}

export interface UbahHargaPayload {
  grade: Grade;
  harga: number;
  berlakuDari?: string;
  catatan?: string;
}

export async function getHarga(grade?: GradeKode): Promise<HargaData> {
  const res = await apiClient.get<{ data: HargaData }>("master/harga", { grade });
  return res.data;
}

export async function ubahHarga(payload: UbahHargaPayload): Promise<void> {
  await apiClient.post("master/harga", payload);
}

// ---- Tarif gaji & uang makan ------------------------------------------------

export type JenisTarifKode = "kristal" | "brondol" | "uang_makan";
export type TarifKey = "kristal" | "brondol" | "uangMakan";

export interface TarifJenis {
  kode: JenisTarifKode;
  nama: string;
  nilai: number;
}

export interface RiwayatTarif {
  id: string;
  jenis: JenisTarifKode;
  jenisLabel: string;
  tanggal: string;
  nilaiLama: number | null;
  nilaiBaru: number;
  catatan: string | null;
}

export interface TarifData {
  tarif: Record<TarifKey, number>;
  jenis: TarifJenis[];
  riwayat: RiwayatTarif[];
}

export interface UbahTarifPayload {
  /** Backend menerima "kristal" | "brondol" | "uang_makan" | "uangMakan". */
  jenis: TarifKey;
  nilai: number;
  berlakuDari?: string;
  catatan?: string;
}

export async function getTarif(jenis?: JenisTarifKode): Promise<TarifData> {
  const res = await apiClient.get<{ data: TarifData }>("master/tarif", { jenis });
  return res.data;
}

export async function ubahTarif(payload: UbahTarifPayload): Promise<void> {
  await apiClient.post("master/tarif", payload);
}

// ---- Karyawan ---------------------------------------------------------------

export interface Karyawan {
  id: string;
  nama: string;
  kontak: string;
  aktif: boolean;
}

export interface KaryawanListParams {
  q?: string | undefined;
  sertakanNonaktif?: boolean | undefined;
}

export interface KaryawanPayload {
  nama?: string;
  /** Opsional — backend menerima kontak kosong (KaryawanRequest: nullable). */
  kontak?: string | undefined;
  aktif?: boolean;
}

export async function getKaryawanList(params: KaryawanListParams = {}): Promise<Karyawan[]> {
  const res = await apiClient.get<{ data: Karyawan[] }>("master/karyawan", {
    q: params.q,
    sertakanNonaktif: params.sertakanNonaktif ? 1 : undefined,
  });
  return res.data;
}

export async function tambahKaryawan(payload: KaryawanPayload): Promise<Karyawan> {
  const res = await apiClient.post<{ data: Karyawan }>("master/karyawan", payload);
  return res.data;
}

export async function ubahKaryawan(id: string, payload: KaryawanPayload): Promise<Karyawan> {
  const res = await apiClient.put<{ data: Karyawan }>(`master/karyawan/${id}`, payload);
  return res.data;
}

/** Karyawan yang pernah ikut sesi tungku dinonaktifkan, bukan dihapus — pesan ada di response.message. */
export async function hapusKaryawan(id: string): Promise<{ message: string }> {
  return apiClient.delete<{ message: string }>(`master/karyawan/${id}`);
}

// ---- Eksportir ----------------------------------------------------------------

export interface Eksportir {
  id: string;
  nama: string;
  kontak: string;
  alamat: string;
  aktif: boolean;
}

export interface EksportirListParams {
  sertakanNonaktif?: boolean | undefined;
}

export interface EksportirPayload {
  nama?: string;
  kontak?: string;
  alamat?: string | undefined;
  aktif?: boolean;
}

export async function getEksportirList(params: EksportirListParams = {}): Promise<Eksportir[]> {
  const res = await apiClient.get<{ data: Eksportir[] }>("master/eksportir", {
    sertakanNonaktif: params.sertakanNonaktif ? 1 : undefined,
  });
  return res.data;
}

export async function tambahEksportir(payload: EksportirPayload): Promise<Eksportir> {
  const res = await apiClient.post<{ data: Eksportir }>("master/eksportir", payload);
  return res.data;
}

export async function ubahEksportir(id: string, payload: EksportirPayload): Promise<Eksportir> {
  const res = await apiClient.put<{ data: Eksportir }>(`master/eksportir/${id}`, payload);
  return res.data;
}

export async function hapusEksportir(id: string): Promise<void> {
  await apiClient.delete(`master/eksportir/${id}`);
}
