import { useEffect, useState } from "react";
import { createFileRoute } from "@tanstack/react-router";
import { Loader2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { DataTable, EmptyState, PageHeader, type Column } from "@/components/sigula/ui-bits";
import { useAuditLog } from "@/hooks/use-audit-log";
import type { AuditLogEntry } from "@/lib/api/audit-log";

export const Route = createFileRoute("/_app/audit")({
  head: () => ({
    meta: [
      { title: "Audit Log — SIGULA" },
      {
        name: "description",
        content:
          "Jejak audit seluruh modul SIGULA: siapa mengubah harga, mencatat pembelian, menutup sesi tungku, membayar gaji, hingga keluar-masuk sistem.",
      },
      { property: "og:title", content: "Audit Log — SIGULA" },
      {
        property: "og:description",
        content: "Rekam jejak setiap perubahan data di seluruh modul.",
      },
    ],
  }),
  component: AuditPage,
});

const PER_PAGE = 50;

/**
 * Filter per modul memakai prefix aksi yang dipakai backend (`petani.simpan`,
 * `produksi.selesai`, dst) — lihat pemanggilan AuditLogger::catat().
 */
const MODUL = [
  { prefix: "", label: "Semua modul" },
  { prefix: "auth.", label: "Login & Logout" },
  { prefix: "petani.", label: "Data Petani" },
  { prefix: "pengepul.", label: "Pengepul" },
  { prefix: "karyawan.", label: "Karyawan" },
  { prefix: "eksportir.", label: "Eksportir" },
  { prefix: "harga.", label: "Master Harga" },
  { prefix: "tarif.", label: "Master Tarif" },
  { prefix: "pembelian.", label: "Pembelian" },
  { prefix: "stok.", label: "Stok" },
  { prefix: "produksi.", label: "Produksi" },
  { prefix: "gaji.", label: "Penggajian" },
  { prefix: "penjualan.", label: "Penjualan" },
  { prefix: "biaya.", label: "Biaya Operasional" },
  { prefix: "laporan.", label: "Laporan & Export" },
  { prefix: "user.", label: "Kelola Pengguna" },
] as const;

const SEMUA = "__semua__";

/** Warna badge mengikuti sifat aksinya, bukan modulnya. */
function nadaAksi(aksi: string): string {
  if (aksi.endsWith(".hapus") || aksi.endsWith(".batal")) {
    return "bg-destructive/10 text-destructive hover:bg-destructive/10";
  }
  if (aksi.endsWith(".simpan") || aksi.endsWith(".mulai")) {
    return "bg-success/15 text-success hover:bg-success/15";
  }
  if (aksi.startsWith("auth.")) {
    return "bg-muted text-muted-foreground hover:bg-muted";
  }
  return "bg-warning/25 text-warning-foreground hover:bg-warning/25";
}

function waktuLengkap(iso: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleString("id-ID", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function AuditPage() {
  const [modul, setModul] = useState<string>(SEMUA);
  const [dari, setDari] = useState("");
  const [sampai, setSampai] = useState("");
  const [q, setQ] = useState("");
  const [qDebounced, setQDebounced] = useState("");
  const [page, setPage] = useState(1);

  useEffect(() => {
    const t = setTimeout(() => setQDebounced(q.trim()), 300);
    return () => clearTimeout(t);
  }, [q]);

  useEffect(() => {
    setPage(1);
  }, [modul, dari, sampai, qDebounced]);

  const { data, isLoading, isError } = useAuditLog({
    aksi: modul === SEMUA ? undefined : modul,
    dari: dari || undefined,
    sampai: sampai || undefined,
    page,
    perPage: PER_PAGE,
  });

  const semua = data?.data ?? [];
  const meta = data?.meta;

  // Pencarian teks dilakukan di sisi klien: endpoint audit hanya menyaring
  // berdasarkan prefix aksi, pelaku, dan rentang tanggal.
  const rows =
    qDebounced === ""
      ? semua
      : semua.filter((r) =>
          `${r.deskripsi} ${r.aksi} ${r.user?.nama ?? ""}`
            .toLowerCase()
            .includes(qDebounced.toLowerCase()),
        );

  const filterAktif = modul !== SEMUA || dari !== "" || sampai !== "" || q !== "";

  const resetFilter = () => {
    setModul(SEMUA);
    setDari("");
    setSampai("");
    setQ("");
  };

  const cols: Column<AuditLogEntry>[] = [
    {
      key: "waktu",
      header: "Waktu",
      cell: (r) => <span className="whitespace-nowrap text-xs">{waktuLengkap(r.waktu)}</span>,
    },
    {
      key: "pelaku",
      header: "Pelaku",
      cell: (r) =>
        r.user ? (
          <div className="text-xs">
            <p className="font-medium">{r.user.nama}</p>
            <p className="text-muted-foreground">{r.user.role}</p>
          </div>
        ) : (
          <span className="text-xs text-muted-foreground">Sistem</span>
        ),
    },
    {
      key: "aksi",
      header: "Aksi",
      cell: (r) => <Badge className={`font-mono text-[10px] ${nadaAksi(r.aksi)}`}>{r.aksi}</Badge>,
    },
    { key: "deskripsi", header: "Keterangan", cell: (r) => r.deskripsi },
    {
      key: "data",
      header: "Detail",
      cell: (r) =>
        r.data ? (
          <details className="text-xs">
            <summary className="cursor-pointer text-muted-foreground hover:text-foreground">
              Lihat
            </summary>
            <pre className="mt-1 max-w-md overflow-x-auto rounded-lg bg-muted p-2 text-[10px]">
              {JSON.stringify(r.data, null, 2)}
            </pre>
          </details>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
    {
      key: "ip",
      header: "IP",
      cell: (r) => <span className="font-mono text-xs text-muted-foreground">{r.ip ?? "—"}</span>,
    },
  ];

  return (
    <>
      <PageHeader
        title="Audit Log"
        subtitle="Rekam jejak setiap perubahan data di seluruh modul, termasuk keluar-masuk sistem"
      />

      <Card className="overflow-hidden shadow-card">
        <CardContent className="space-y-4 px-0 py-4">
          <div className="flex flex-wrap items-end gap-3 px-4">
            <div className="space-y-1">
              <Label className="text-xs">Modul</Label>
              <Select value={modul} onValueChange={setModul}>
                <SelectTrigger className="w-[200px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {MODUL.map((m) => (
                    <SelectItem key={m.prefix || SEMUA} value={m.prefix || SEMUA}>
                      {m.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <Label className="text-xs">Dari Tanggal</Label>
              <Input
                type="date"
                value={dari}
                onChange={(e) => setDari(e.target.value)}
                className="w-[160px]"
              />
            </div>
            <div className="space-y-1">
              <Label className="text-xs">Sampai Tanggal</Label>
              <Input
                type="date"
                value={sampai}
                onChange={(e) => setSampai(e.target.value)}
                className="w-[160px]"
              />
            </div>
            <div className="min-w-[200px] flex-1 space-y-1">
              <Label className="text-xs">Cari keterangan / pelaku</Label>
              <Input
                value={q}
                onChange={(e) => setQ(e.target.value)}
                placeholder="mis. Sukirman, TGK-01…"
              />
            </div>
            {filterAktif && (
              <Button variant="ghost" onClick={resetFilter}>
                Reset filter
              </Button>
            )}
          </div>

          {isError && (
            <p className="px-4 text-sm text-destructive">
              Gagal memuat audit log. Coba muat ulang halaman.
            </p>
          )}

          {isLoading ? (
            <div className="flex items-center justify-center gap-2 px-4 py-14 text-sm text-muted-foreground">
              <Loader2 className="size-4 animate-spin" /> Memuat data…
            </div>
          ) : (
            <>
              <DataTable
                rows={rows}
                columns={cols}
                rowKey={(r) => r.id}
                empty={
                  <EmptyState
                    title="Tidak ada jejak audit"
                    description="Tidak ada aktivitas yang cocok dengan filter saat ini."
                  />
                }
              />

              {meta && meta.total > 0 && (
                <div className="flex flex-wrap items-center justify-between gap-3 px-4 pt-2">
                  <p className="text-xs text-muted-foreground">
                    Halaman {meta.currentPage} dari {meta.lastPage} · {meta.total} aktivitas
                    {qDebounced !== "" && ` · ${rows.length} cocok di halaman ini`}
                  </p>
                  <div className="flex gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={meta.currentPage <= 1}
                      onClick={() => setPage((p) => Math.max(1, p - 1))}
                    >
                      Sebelumnya
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={meta.currentPage >= meta.lastPage}
                      onClick={() => setPage((p) => p + 1)}
                    >
                      Berikutnya
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>
    </>
  );
}
