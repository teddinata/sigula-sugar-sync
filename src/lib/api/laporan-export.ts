import { apiClient } from "@/lib/api-client";

/**
 * Unduh laporan dari folder "Export" pada koleksi Postman — cross-check ke
 * ExportController, LaporanExportService, dan RentangPeriode.
 *
 * Semua endpoint menerima `format` (csv/xlsx/pdf) dan rentang periode yang sama:
 * `periode=bulan_ini|bulan_lalu|custom`, atau `dari`+`sampai` langsung.
 */

export type FormatExport = "csv" | "xlsx" | "pdf";

export const FORMAT_EXPORT: { kode: FormatExport; label: string; keterangan: string }[] = [
  { kode: "xlsx", label: "Excel (.xlsx)", keterangan: "Siap diolah lagi di Excel" },
  { kode: "csv", label: "CSV (.csv)", keterangan: "Ringan, bisa dibuka aplikasi apa pun" },
  { kode: "pdf", label: "PDF (.pdf)", keterangan: "Untuk dicetak atau diarsipkan" },
];

export type JenisLaporan =
  "pembelian" | "produksi" | "penggajian" | "penjualan" | "kartu-stok" | "biaya" | "laba-rugi";

/** Endpoint per jenis laporan. */
const ENDPOINT: Record<JenisLaporan, string> = {
  pembelian: "pembelian/export",
  produksi: "produksi/sesi/export",
  penggajian: "penggajian/export",
  penjualan: "penjualan/export",
  "kartu-stok": "stok/kartu/export",
  biaya: "keuangan/biaya/export",
  "laba-rugi": "keuangan/laba-rugi/export",
};

export interface ParamExport {
  format: FormatExport;
  /** Filter tambahan per modul (grade, petaniId, kategori, tanggal, dst). */
  [key: string]: string | number | undefined;
}

export async function unduhLaporan(jenis: JenisLaporan, params: ParamExport): Promise<void> {
  await apiClient.unduh(ENDPOINT[jenis], params);
}
