import { apiClient } from "@/lib/api-client";

/**
 * Pengelolaan akun pengguna — hanya bisa diakses Owner, yang sekaligus
 * berperan sebagai superadmin. Cross-check ke UserController / UserRequest /
 * UserResource / UserService.
 */

export type RoleKode = "owner" | "admin" | "staff_gudang" | "staff_produksi";

export interface Pengguna {
  id: string;
  nama: string;
  email: string;
  role: RoleKode;
  roleLabel: string;
  aktif: boolean;
  /** true untuk akun yang sedang login — sebagian aksi dikunci untuk baris ini. */
  diriSendiri: boolean;
  dibuatPada: string | null;
}

export interface RoleInfo {
  kode: RoleKode;
  label: string;
  keterangan: string;
  /** Menu yang boleh dibuka role ini; dipakai untuk pratinjau di form. */
  menu: string[];
}

export interface PenggunaListParams {
  q?: string | undefined;
  role?: RoleKode | undefined;
  sertakanNonaktif?: boolean | undefined;
}

export interface PenggunaPayload {
  nama?: string;
  email?: string;
  /** Wajib saat membuat akun; saat mengubah, kosong berarti password tetap. */
  password?: string | undefined;
  role?: RoleKode;
  aktif?: boolean;
}

export async function getPenggunaList(params: PenggunaListParams = {}): Promise<Pengguna[]> {
  const res = await apiClient.get<{ data: Pengguna[] }>("pengguna", {
    q: params.q,
    role: params.role,
    sertakanNonaktif: params.sertakanNonaktif ? 1 : undefined,
  });
  return res.data;
}

export async function getDaftarRole(): Promise<RoleInfo[]> {
  const res = await apiClient.get<{ data: RoleInfo[] }>("pengguna/role");
  return res.data;
}

export async function tambahPengguna(payload: PenggunaPayload): Promise<Pengguna> {
  const res = await apiClient.post<{ data: Pengguna }>("pengguna", payload);
  return res.data;
}

export async function ubahPengguna(id: string, payload: PenggunaPayload): Promise<Pengguna> {
  const res = await apiClient.put<{ data: Pengguna }>(`pengguna/${id}`, payload);
  return res.data;
}

export async function hapusPengguna(id: string): Promise<{ message: string }> {
  return apiClient.delete<{ message: string }>(`pengguna/${id}`);
}
