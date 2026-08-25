import { apiClient } from "@/lib/api-client";

/**
 * Pengepul: perantara antara petani dan perusahaan pada transaksi pembelian.
 * Cross-check ke PengepulController / PengepulRequest / PengepulResource.
 */

export interface Pengepul {
  id: string;
  nama: string;
  kontak: string;
  alamat: string;
  aktif: boolean;
  /** Hanya terisi pada endpoint daftar. */
  totalTransaksi?: number;
}

export interface PengepulListParams {
  q?: string | undefined;
  sertakanNonaktif?: boolean | undefined;
}

export interface PengepulPayload {
  nama?: string;
  kontak?: string | undefined;
  alamat?: string | undefined;
  aktif?: boolean;
}

export async function getPengepulList(params: PengepulListParams = {}): Promise<Pengepul[]> {
  const res = await apiClient.get<{ data: Pengepul[] }>("pengepul", {
    q: params.q,
    sertakanNonaktif: params.sertakanNonaktif ? 1 : undefined,
  });
  return res.data;
}

export async function tambahPengepul(payload: PengepulPayload): Promise<Pengepul> {
  const res = await apiClient.post<{ data: Pengepul }>("pengepul", payload);
  return res.data;
}

export async function ubahPengepul(id: string, payload: PengepulPayload): Promise<Pengepul> {
  const res = await apiClient.put<{ data: Pengepul }>(`pengepul/${id}`, payload);
  return res.data;
}

/**
 * Pengepul yang sudah punya transaksi hanya dinonaktifkan (bukan dihapus)
 * supaya riwayat pembelian tetap utuh — penjelasannya ada di response.message.
 */
export async function hapusPengepul(id: string): Promise<{ message: string }> {
  return apiClient.delete<{ message: string }>(`pengepul/${id}`);
}
