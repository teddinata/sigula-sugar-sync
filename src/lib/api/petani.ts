import { apiClient } from "@/lib/api-client";

/**
 * Bentuk data persis seperti folder "3. Data Petani" di
 * SIGULA.postman_collection.json, cross-check ke PetaniController /
 * PetaniRequest / PetaniResource / MasterDataService.
 */

/** Kode status penderes/pemilik lahan yang dipakai client. */
export type StatusPenderesKode = "pms" | "pmms" | "plmr" | "plmd" | "pls" | "pl" | "pm";

export interface StatusPenderes {
  kode: StatusPenderesKode;
  /** Singkatan huruf besar, mis. "PMS". */
  label: string;
  /** Kepanjangannya, mis. "Penderes Milik Sendiri". */
  keterangan: string;
}

/** Urutan & keterangan resmi dari dokumen client; dipakai untuk dropdown. */
export const DAFTAR_STATUS_PENDERES: StatusPenderes[] = [
  { kode: "pms", label: "PMS", keterangan: "Penderes Milik Sendiri" },
  { kode: "pmms", label: "PMMS", keterangan: "Penderes Maro dan Milik Sendiri" },
  { kode: "plmr", label: "PLMR", keterangan: "Pemilik Lahan Maro (Masak Nira)" },
  { kode: "plmd", label: "PLMD", keterangan: "Pemilik Lahan Mendreng (Bayar Gula)" },
  { kode: "pls", label: "PLS", keterangan: "Pemilik Lahan Sewa (Bayar Uang)" },
  { kode: "pl", label: "PL", keterangan: "Pemilik Lahan (Manggis)" },
  { kode: "pm", label: "PM", keterangan: "Penderes Maro" },
];

export interface Petani {
  id: string;
  nama: string;
  /** Disimpulkan dari kode lahan, bukan kolom tersendiri. */
  status: "Member" | "Non-Member";
  statusKode: "member" | "non_member";
  /** Kode lahan (mis. "BA-002") sekaligus berfungsi sebagai nomor member. */
  kodeLahan: string | null;
  /** Alias kodeLahan — dipertahankan supaya kode lama tetap jalan. */
  nomorMember: string;
  labelMember: string;
  rtRw: string | null;
  /** Petani yang berhenti menderes dinonaktifkan, bukan dihapus. */
  aktif: boolean;
  kontak: string;
  alamat: string;
  /** Bisa lebih dari satu, mis. PMS + PLMD. */
  statusPenderes?: StatusPenderes[];
  totalTransaksi: number;
  totalNilai: number;
}

export interface PetaniListParams {
  q?: string | undefined;
  /** Filter multi-status; dikirim sebagai daftar dipisah koma. */
  statusPenderes?: StatusPenderesKode[] | undefined;
  /** Default hanya menampilkan petani aktif. */
  sertakanNonaktif?: boolean | undefined;
}

export interface PetaniPayload {
  nama: string;
  /** Terisi = Member, kosong = Non-Member. Tidak ada nomor member terpisah. */
  kodeLahan?: string | undefined;
  rtRw?: string | undefined;
  aktif?: boolean | undefined;
  kontak?: string | undefined;
  alamat?: string | undefined;
  statusPenderes?: StatusPenderesKode[] | undefined;
}

export async function getPetaniList(params: PetaniListParams = {}): Promise<Petani[]> {
  const res = await apiClient.get<{ data: Petani[] }>("petani", {
    q: params.q,
    statusPenderes: params.statusPenderes?.length ? params.statusPenderes.join(",") : undefined,
    sertakanNonaktif: params.sertakanNonaktif ? 1 : undefined,
  });
  return res.data;
}

export async function getPetani(id: string): Promise<Petani> {
  const res = await apiClient.get<{ data: Petani }>(`petani/${id}`);
  return res.data;
}

export async function tambahPetani(payload: PetaniPayload): Promise<Petani> {
  const res = await apiClient.post<{ data: Petani }>("petani", payload);
  return res.data;
}

export async function ubahPetani(id: string, payload: PetaniPayload): Promise<Petani> {
  const res = await apiClient.put<{ data: Petani }>(`petani/${id}`, payload);
  return res.data;
}

export async function hapusPetani(id: string): Promise<void> {
  await apiClient.delete(`petani/${id}`);
}
