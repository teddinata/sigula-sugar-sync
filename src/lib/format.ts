const BULAN = [
  "Januari",
  "Februari",
  "Maret",
  "April",
  "Mei",
  "Juni",
  "Juli",
  "Agustus",
  "September",
  "Oktober",
  "November",
  "Desember",
];

export function rupiah(value: number): string {
  const n = Math.round(value || 0);
  return "Rp " + n.toLocaleString("id-ID");
}

export function angka(value: number, digits = 0): string {
  return (value || 0).toLocaleString("id-ID", {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
  });
}

export function kg(value: number): string {
  return angka(value, value % 1 === 0 ? 0 : 1) + " kg";
}

/** "2026-08-08" -> "8 Agustus 2026" */
export function tanggalID(iso: string): string {
  const [y, m, d] = iso.split("-").map(Number);
  if (!y || !m || !d) return iso;
  return `${d} ${BULAN[m - 1]} ${y}`;
}

export function tanggalPendek(iso: string): string {
  const [y, m, d] = iso.split("-").map(Number);
  if (!y || !m || !d) return iso;
  return `${d} ${BULAN[m - 1]!.slice(0, 3)} ${y}`;
}

export function namaBulan(month: number): string {
  return BULAN[month] ?? "";
}

export function toISO(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, "0");
  const d = String(date.getDate()).padStart(2, "0");
  return `${y}-${m}-${d}`;
}

export function parseISO(iso: string): Date {
  const [y, m, d] = iso.split("-").map(Number);
  return new Date(y!, (m ?? 1) - 1, d ?? 1);
}

export function addDays(iso: string, days: number): string {
  const dt = parseISO(iso);
  dt.setDate(dt.getDate() + days);
  return toISO(dt);
}

/** Monday of the week containing `iso` */
export function mondayOf(iso: string): string {
  const dt = parseISO(iso);
  const day = dt.getDay(); // 0 sun .. 6 sat
  const diff = day === 0 ? -6 : 1 - day;
  dt.setDate(dt.getDate() + diff);
  return toISO(dt);
}

export function monthKey(iso: string): string {
  return iso.slice(0, 7);
}

export function labelBulanSingkat(key: string): string {
  const [y, m] = key.split("-").map(Number);
  return `${BULAN[(m ?? 1) - 1]!.slice(0, 3)} ${String(y).slice(2)}`;
}

/**
 * Pembulatan nominal ke kelipatan 500 sesuai aturan client.
 *
 * Cerminan dari App\Support\Pembulatan di backend — dipakai HANYA untuk pratinjau
 * di form; nilai yang disimpan tetap hasil hitungan backend.
 *
 * Sisa di atas kelipatan 1.000 naik ke 500 bila <= 500, selebihnya naik ke
 * 1.000 berikutnya. Contoh: 25.300 → 25.500, 25.700 → 26.000.
 */
export function bulatkanKeLimaRatus(nominal: number): number {
  if (!Number.isFinite(nominal) || nominal <= 0) return 0;

  const dasar = Math.floor(nominal / 1000) * 1000;
  const sisa = Math.round((nominal - dasar) * 100) / 100;

  if (sisa <= 0) return dasar;
  return sisa <= 500 ? dasar + 500 : dasar + 1000;
}
