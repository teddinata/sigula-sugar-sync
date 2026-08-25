import { apiClient } from "@/lib/api-client";

/**
 * Ringkasan keuangan hasil AI (lihat RingkasanAiService + RingkasanKeuanganAgent).
 *
 * Angka-angkanya dihitung backend lebih dulu lalu disuapkan ke model sebagai
 * fakta, jadi isi ringkasan tidak mengarang nominal. Hasilnya di-cache 30 menit
 * di server.
 */

export interface RingkasanAi {
  periode: { dari: string; sampai: string; label: string };
  /** Teks ringkasan dari model. */
  ringkasan: string;
  /** Nama model yang dipakai, mis. "claude-opus-5" atau "gemini-3.7-flash". */
  model: string;
  /** true bila hasil diambil dari cache 30 menit, bukan panggilan model baru. */
  dariCache: boolean;
  /** Angka yang disuapkan ke model — ditampilkan supaya bisa dicek ulang. */
  angka: {
    labaRugi: {
      periode: { dari: string; sampai: string };
      pendapatan: number;
      hpp: { bahan: number; gaji: { total: number }; total: number };
      biayaOperasional: number;
      labaBersih: number;
      margin: number;
    };
  };
}

export interface ParamRingkasanAi {
  periode?: "bulan_ini" | "bulan_lalu" | "custom" | undefined;
  dari?: string | undefined;
  sampai?: string | undefined;
  /** Abaikan cache 30 menit dan minta ringkasan baru. */
  segarkan?: boolean | undefined;
}

export async function getRingkasanAi(params: ParamRingkasanAi = {}): Promise<RingkasanAi> {
  const res = await apiClient.get<{ data: RingkasanAi }>("keuangan/ringkasan-ai", {
    periode: params.periode,
    dari: params.dari,
    sampai: params.sampai,
    segarkan: params.segarkan ? 1 : undefined,
  });
  return res.data;
}
