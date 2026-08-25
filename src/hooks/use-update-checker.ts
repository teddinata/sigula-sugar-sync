/**
 * Mendeteksi ada tidaknya versi baru aplikasi web.
 *
 * Dua sumber:
 *  1. `/version.json` milik deploy yang aktif — buildId beda berarti ada rilis
 *     baru, walau nomor versinya tidak naik.
 *  2. `GET /api/v1/versi` — bila `minimalWeb` lebih baru dari versi yang sedang
 *     jalan, pembaruan jadi WAJIB (tidak bisa ditunda) karena klien lama sudah
 *     tidak kompatibel dengan API.
 *
 * Pengecekan berjalan berkala dan setiap tab kembali aktif, dengan jeda minimum
 * supaya tidak memberondong server.
 */
import { useCallback, useEffect, useRef, useState } from "react";

import {
  BUILD_ID,
  VERSI_WEB,
  ambilVersiServer,
  ambilVersiWeb,
  bandingkanVersi,
  pengecekanVersiAktif,
} from "@/lib/versi";

const JEDA_PERIKSA_MS = 5 * 60 * 1000;
const JEDA_MINIMUM_MS = 60 * 1000;
const LAMA_TUNDA_MS = 6 * 60 * 60 * 1000;
const KUNCI_TUNDA = "sigula.pembaruan.ditunda";

interface Tunda {
  buildId: string;
  sampai: number;
}

function bacaTunda(): Tunda | null {
  try {
    const mentah = window.localStorage.getItem(KUNCI_TUNDA);
    if (!mentah) return null;

    const data = JSON.parse(mentah) as Partial<Tunda>;

    return typeof data.buildId === "string" && typeof data.sampai === "number"
      ? { buildId: data.buildId, sampai: data.sampai }
      : null;
  } catch {
    return null;
  }
}

function simpanTunda(tunda: Tunda): void {
  try {
    window.localStorage.setItem(KUNCI_TUNDA, JSON.stringify(tunda));
  } catch {
    // Mode privat / storage penuh — cukup abaikan, popup akan muncul lagi nanti.
  }
}

export interface StatusPembaruan {
  /** Ada rilis baru dan popup layak ditampilkan. */
  tersedia: boolean;
  /** Klien terlalu lama untuk API saat ini; tidak boleh ditunda. */
  wajib: boolean;
  versiBaru: string | null;
  catatan: string[];
  versiSekarang: string;
  tunda: () => void;
  periksaSekarang: () => void;
}

export function useUpdateChecker(): StatusPembaruan {
  const [versiBaru, setVersiBaru] = useState<string | null>(null);
  const [buildBaru, setBuildBaru] = useState<string | null>(null);
  const [catatan, setCatatan] = useState<string[]>([]);
  const [wajib, setWajib] = useState(false);
  const [ditunda, setDitunda] = useState<Tunda | null>(() =>
    pengecekanVersiAktif ? bacaTunda() : null,
  );

  const terakhirPeriksa = useRef(0);
  const sedangPeriksa = useRef(false);

  const periksa = useCallback(async (paksa = false) => {
    if (!pengecekanVersiAktif || sedangPeriksa.current) return;

    const sekarang = Date.now();
    if (!paksa && sekarang - terakhirPeriksa.current < JEDA_MINIMUM_MS) return;

    sedangPeriksa.current = true;
    terakhirPeriksa.current = sekarang;

    try {
      const [web, server] = await Promise.all([ambilVersiWeb(), ambilVersiServer()]);

      if (web && web.buildId !== BUILD_ID) {
        setBuildBaru(web.buildId);
        setVersiBaru(web.versi);
      }

      if (server) {
        setCatatan(server.catatan ?? []);

        // Versi web yang jalan sudah di bawah batas minimum API.
        if (bandingkanVersi(VERSI_WEB, server.minimalWeb) < 0) {
          setWajib(true);
          setVersiBaru((sebelumnya) => sebelumnya ?? server.versi);
        }
      }
    } finally {
      sedangPeriksa.current = false;
    }
  }, []);

  useEffect(() => {
    if (!pengecekanVersiAktif) return;

    void periksa(true);

    const timer = window.setInterval(() => void periksa(), JEDA_PERIKSA_MS);

    const saatKembali = () => {
      if (document.visibilityState === "visible") void periksa();
    };

    document.addEventListener("visibilitychange", saatKembali);
    window.addEventListener("focus", saatKembali);
    window.addEventListener("online", saatKembali);

    return () => {
      window.clearInterval(timer);
      document.removeEventListener("visibilitychange", saatKembali);
      window.removeEventListener("focus", saatKembali);
      window.removeEventListener("online", saatKembali);
    };
  }, [periksa]);

  const tunda = useCallback(() => {
    if (!buildBaru) return;

    const berikutnya = { buildId: buildBaru, sampai: Date.now() + LAMA_TUNDA_MS };
    simpanTunda(berikutnya);
    setDitunda(berikutnya);
  }, [buildBaru]);

  const adaRilisBaru = buildBaru !== null;
  // Penundaan hanya berlaku untuk build yang sama; rilis berikutnya tanya lagi.
  const masihDitunda =
    ditunda !== null && ditunda.buildId === buildBaru && ditunda.sampai > Date.now();

  return {
    tersedia: (adaRilisBaru && !masihDitunda) || wajib,
    wajib,
    versiBaru,
    catatan,
    versiSekarang: VERSI_WEB,
    tunda,
    periksaSekarang: () => void periksa(true),
  };
}
