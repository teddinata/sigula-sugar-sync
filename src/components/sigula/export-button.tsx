/**
 * Tombol unduh laporan (CSV / Excel / PDF).
 *
 * Filter yang sedang aktif di halaman ikut dikirim lewat `params`, jadi isi file
 * sama persis dengan yang sedang dilihat pengguna di tabel.
 */
import { useState } from "react";
import { Download, FileSpreadsheet, FileText, Loader2, Table2 } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { ApiError } from "@/lib/api-client";
import {
  FORMAT_EXPORT,
  unduhLaporan,
  type FormatExport,
  type JenisLaporan,
} from "@/lib/api/laporan-export";

const IKON: Record<FormatExport, typeof FileText> = {
  xlsx: FileSpreadsheet,
  csv: Table2,
  pdf: FileText,
};

export function ExportButton({
  jenis,
  params = {},
  label = "Export",
  disabled,
}: {
  jenis: JenisLaporan;
  /** Filter aktif halaman; nilai undefined otomatis tidak ikut dikirim. */
  params?: Record<string, string | number | undefined>;
  label?: string;
  disabled?: boolean;
}) {
  const [memuat, setMemuat] = useState<FormatExport | null>(null);

  const unduh = async (format: FormatExport) => {
    setMemuat(format);
    try {
      await unduhLaporan(jenis, { ...params, format });
      toast.success("Laporan berhasil diunduh", {
        description: `Format ${format.toUpperCase()}`,
      });
    } catch (e) {
      toast.error(
        e instanceof ApiError ? (e.firstFieldError ?? e.message) : "Gagal mengunduh laporan.",
      );
    } finally {
      setMemuat(null);
    }
  };

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="outline" disabled={disabled || memuat !== null}>
          {memuat ? (
            <Loader2 className="mr-2 size-4 animate-spin" />
          ) : (
            <Download className="mr-2 size-4" />
          )}
          {label}
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-60">
        <DropdownMenuLabel>Unduh sesuai filter aktif</DropdownMenuLabel>
        <DropdownMenuSeparator />
        {FORMAT_EXPORT.map((f) => {
          const Ikon = IKON[f.kode];
          return (
            <DropdownMenuItem
              key={f.kode}
              onSelect={() => void unduh(f.kode)}
              className="flex-col items-start gap-0.5"
            >
              <span className="flex items-center gap-2 font-medium">
                <Ikon className="size-4" /> {f.label}
              </span>
              <span className="pl-6 text-xs text-muted-foreground">{f.keterangan}</span>
            </DropdownMenuItem>
          );
        })}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
