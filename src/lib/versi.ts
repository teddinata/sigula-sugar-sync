/**
 * Versi aplikasi web dan deteksi rilis baru.
 *
 * Nilai VERSI_WEB/BUILD_ID ditanam saat build oleh `vite.config.spa.ts`, yang
 * sekaligus menulis `version.json` ke hasil build. Aplikasi yang sedang jalan
 * mengambil file itu berkala; kalau buildId-nya berbeda berarti ada deploy baru
 * dan pengguna diminta memuat ulang halaman.
 *
 * Di mode dev / build SSR Lovable nilainya kosong, dan seluruh pengecekan
 * otomatis dinonaktifkan (lihat `pengecekanVersiAktif`).
 */
import { API_BASE_URL } from "./api-client";

export const VERSI_WEB = (import.meta.env["VITE_APP_VERSION"] as string | undefined) ?? "dev";
export const BUILD_ID = (import.meta.env["VITE_BUILD_ID"] as string | undefined) ?? "";
export const WAKTU_BUILD = (import.meta.env["VITE_BUILD_TIME"] as string | undefined) ?? "";

/** Hanya bundel hasil `npm run build:spa` yang punya buildId untuk dibandingkan. */
export const pengecekanVersiAktif = BUILD_ID !== "" && typeof window !== "undefined";

export interface VersiWeb {
  versi: string;
  buildId: string;
  dibangunPada: string;
}

export interface VersiServer {
  aplikasi: string;
  pemilik: string;
  versi: string;
  dirilis: string;
  versiApi: string;
  minimalWeb: string;
  catatan: string[];
}

/**
 * Membandingkan dua versi semver sederhana ("1.2.10" > "1.2.9").
 * Mengembalikan <0 bila a lebih lama, 0 bila sama, >0 bila a lebih baru.
 */
export function bandingkanVersi(a: string, b: string): number {
  const pecah = (v: string): number[] =>
    v
      .split(/[+-]/)[0]!
      .split(".")
      .map((bagian) => Number.parseInt(bagian, 10) || 0);

  const kiri = pecah(a);
  const kanan = pecah(b);

  for (let i = 0; i < Math.max(kiri.length, kanan.length); i++) {
    const selisih = (kiri[i] ?? 0) - (kanan[i] ?? 0);
    if (selisih !== 0) return selisih;
  }

  return 0;
}

/**
 * Mengambil version.json milik deploy yang sedang aktif di server.
 * `cache: "no-store"` penting: tanpa itu browser bisa terus membaca versi lama.
 */
export async function ambilVersiWeb(signal?: AbortSignal): Promise<VersiWeb | null> {
  try {
    const res = await fetch(`/version.json?t=${Date.now()}`, {
      cache: "no-store",
      headers: { Accept: "application/json" },
      ...(signal ? { signal } : {}),
    });

    if (!res.ok) return null;

    const data: unknown = await res.json();

    if (
      typeof data === "object" &&
      data !== null &&
      typeof (data as VersiWeb).buildId === "string" &&
      (data as VersiWeb).buildId !== ""
    ) {
      return data as VersiWeb;
    }

    return null;
  } catch {
    // Offline, file belum ada (mode dev), atau HTML 404 — abaikan diam-diam.
    return null;
  }
}

/** Info versi backend; dipakai untuk menandai update yang wajib. */
export async function ambilVersiServer(signal?: AbortSignal): Promise<VersiServer | null> {
  try {
    const res = await fetch(`${API_BASE_URL}/versi`, {
      cache: "no-store",
      headers: { Accept: "application/json" },
      ...(signal ? { signal } : {}),
    });

    if (!res.ok) return null;

    const body: unknown = await res.json();
    const data = (body as { data?: VersiServer }).data;

    return data && typeof data.versi === "string" ? data : null;
  } catch {
    return null;
  }
}

/**
 * Memuat ulang aplikasi dengan bundel terbaru.
 *
 * Cache Storage dibersihkan lebih dulu supaya reload tidak menyajikan aset lama
 * (relevan bila suatu saat dipasang service worker).
 */
export async function muatUlangAplikasi(): Promise<void> {
  try {
    if (typeof caches !== "undefined") {
      const kunci = await caches.keys();
      await Promise.all(kunci.map((k) => caches.delete(k)));
    }
  } catch {
    // Bukan masalah: reload tetap dilakukan.
  }

  window.location.reload();
}
