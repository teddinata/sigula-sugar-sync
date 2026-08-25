import { useEffect, useState } from "react";
import { createFileRoute } from "@tanstack/react-router";
import { ArrowDownCircle, ArrowUpCircle, ClipboardCheck, Loader2 } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  DataTable,
  EmptyState,
  PageHeader,
  SearchInput,
  StatCard,
  type Column,
} from "@/components/sigula/ui-bits";
import { angka, kg, tanggalPendek } from "@/lib/format";
import { KATEGORI_STOK, type StokKategori } from "@/lib/sigula-types";
import { todayISO } from "@/lib/sigula-seed";
import { ApiError } from "@/lib/api-client";
import type { JenisMutasi, KartuStokRow } from "@/lib/api/stok";
import { useKartuStok, useStokOpname, useStokPosisi } from "@/hooks/use-stok";
import { ExportButton } from "@/components/sigula/export-button";

export const Route = createFileRoute("/_app/stok")({
  head: () => ({
    meta: [
      { title: "Manajemen Stok — SIGULA" },
      {
        name: "description",
        content:
          "Pantau stok bahan mentah per grade, stok gula kristal dan brondol, kartu stok keluar-masuk, serta lakukan stok opname.",
      },
      { property: "og:title", content: "Manajemen Stok — SIGULA" },
      { property: "og:description", content: "Kartu stok real-time bahan mentah dan produk jadi." },
    ],
  }),
  component: StokPage,
});

const PER_PAGE = 50;

function apiErrorMessage(err: unknown, fallback: string): string {
  return err instanceof ApiError ? (err.firstFieldError ?? err.message) : fallback;
}

function LoadingRow() {
  return (
    <div className="flex items-center justify-center gap-2 px-4 py-14 text-sm text-muted-foreground">
      <Loader2 className="size-4 animate-spin" /> Memuat data…
    </div>
  );
}

function StokPage() {
  // ---- Posisi stok -------------------------------------------------------------
  const { data: posisi, isLoading: posisiLoading, isError: posisiError } = useStokPosisi();

  // ---- Kartu stok: filter & pagination -------------------------------------------
  const [q, setQ] = useState("");
  const [qDebounced, setQDebounced] = useState("");
  const [dari, setDari] = useState("");
  const [sampai, setSampai] = useState("");
  const [fKategori, setFKategori] = useState<"semua" | StokKategori>("semua");
  const [fJenis, setFJenis] = useState<"semua" | JenisMutasi>("semua");
  const [page, setPage] = useState(1);

  useEffect(() => {
    const t = setTimeout(() => setQDebounced(q.trim()), 300);
    return () => clearTimeout(t);
  }, [q]);

  useEffect(() => {
    setPage(1);
  }, [qDebounced, dari, sampai, fKategori, fJenis]);

  const {
    data: kartuResult,
    isLoading: kartuLoading,
    isError: kartuError,
  } = useKartuStok({
    kategori: fKategori === "semua" ? undefined : fKategori,
    jenis: fJenis === "semua" ? undefined : fJenis,
    dari: dari || undefined,
    sampai: sampai || undefined,
    q: qDebounced || undefined,
    page,
    perPage: PER_PAGE,
  });

  const rows = kartuResult?.data ?? [];
  const meta = kartuResult?.meta;

  // ---- Stok opname ---------------------------------------------------------------
  const stokOpname = useStokOpname();
  const [opnameOpen, setOpnameOpen] = useState(false);
  const [opname, setOpname] = useState({
    kategori: "NS 1" as StokKategori,
    fisik: "",
    alasan: "",
    tanggal: todayISO(),
  });
  const [err, setErr] = useState<string | null>(null);

  const saldoSaatIni = posisi?.saldo[opname.kategori] ?? 0;
  const selisih = (Number(opname.fisik) || 0) - saldoSaatIni;

  const bukaOpname = () => {
    setOpname({ kategori: "NS 1", fisik: "", alasan: "", tanggal: todayISO() });
    setErr(null);
    setOpnameOpen(true);
  };

  const simpanOpname = async () => {
    if (!opname.fisik.trim() || Number.isNaN(Number(opname.fisik)))
      return setErr("Jumlah stok fisik wajib diisi berupa angka.");
    if (Number(opname.fisik) < 0) return setErr("Jumlah stok fisik tidak boleh negatif.");
    if (!opname.alasan.trim()) return setErr("Alasan koreksi wajib diisi.");
    if (selisih === 0) return setErr("Tidak ada selisih dengan stok sistem.");
    try {
      await stokOpname.mutateAsync({
        kategori: opname.kategori,
        stokFisik: Number(opname.fisik),
        alasan: opname.alasan.trim(),
        tanggal: opname.tanggal || undefined,
      });
      toast.success("Stok opname tercatat", {
        description: `${opname.kategori}: koreksi ${selisih > 0 ? "+" : "−"}${angka(Math.abs(selisih))} kg`,
      });
      setOpnameOpen(false);
    } catch (e) {
      setErr(apiErrorMessage(e, "Gagal menyimpan stok opname."));
    }
  };

  const cols: Column<KartuStokRow>[] = [
    { key: "tgl", header: "Tanggal", cell: (r) => tanggalPendek(r.tanggal) },
    {
      key: "jenis",
      header: "Jenis",
      cell: (r) =>
        r.jenisKode === "masuk" ? (
          <Badge className="bg-success/15 text-success hover:bg-success/15">
            <ArrowDownCircle className="mr-1 size-3" /> Masuk
          </Badge>
        ) : (
          <Badge variant="secondary">
            <ArrowUpCircle className="mr-1 size-3" /> Keluar
          </Badge>
        ),
    },
    { key: "kat", header: "Kategori", cell: (r) => <span>{r.kategoriLabel}</span> },
    {
      key: "jml",
      header: "Jumlah",
      align: "right",
      cell: (r) => (
        <span className={r.jenisKode === "masuk" ? "font-medium text-success" : "font-medium"}>
          {r.jenisKode === "masuk" ? "+" : "−"}
          {angka(r.jumlah)} kg
        </span>
      ),
    },
    {
      key: "saldo",
      header: "Saldo Setelah",
      align: "right",
      cell: (r) => `${angka(r.saldoSetelah)} kg`,
    },
    {
      key: "ket",
      header: "Keterangan",
      cell: (r) => <span className="text-muted-foreground">{r.keterangan}</span>,
    },
  ];

  return (
    <>
      <PageHeader
        title="Manajemen Stok"
        subtitle="Posisi stok dan kartu stok keluar-masuk"
        action={
          <div className="flex flex-wrap gap-2">
            <ExportButton
              jenis="kartu-stok"
              label="Export Kartu Stok"
              params={{
                dari: dari || undefined,
                sampai: sampai || undefined,
                kategori: fKategori === "semua" ? undefined : fKategori,
                jenis: fJenis === "semua" ? undefined : fJenis,
              }}
            />
            <Button onClick={bukaOpname} variant="outline">
              <ClipboardCheck className="mr-2 size-4" /> Stok Opname
            </Button>
          </div>
        }
      />

      {posisiError && (
        <p className="mb-4 text-sm text-destructive">
          Gagal memuat posisi stok. Coba muat ulang halaman.
        </p>
      )}

      <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-muted-foreground">
        Stok Bahan Mentah
      </h2>
      <div className="grid gap-4 md:grid-cols-3">
        {posisiLoading || !posisi
          ? Array.from({ length: 3 }).map((_, i) => (
              <Card key={i} className="shadow-card">
                <CardContent className="p-5 text-sm text-muted-foreground">Memuat…</CardContent>
              </Card>
            ))
          : posisi.bahanMentah.map((b) => {
              const aman = b.status === "aman";
              return (
                <Card key={b.kode} className="shadow-card">
                  <CardHeader className="pb-2">
                    <CardTitle className="flex items-center justify-between text-base">
                      <span>Grade {b.nama}</span>
                      {aman ? (
                        <Badge className="bg-success/15 text-success hover:bg-success/15">
                          Aman
                        </Badge>
                      ) : (
                        <Badge className="bg-warning/25 text-warning-foreground hover:bg-warning/25">
                          Menipis
                        </Badge>
                      )}
                    </CardTitle>
                  </CardHeader>
                  <CardContent>
                    <p className="text-3xl font-semibold tracking-tight">{kg(b.saldo)}</p>
                    <div className="mt-3 h-2 w-full overflow-hidden rounded-full bg-secondary">
                      <div
                        className={aman ? "h-full bg-success" : "h-full bg-warning"}
                        style={{
                          width: `${Math.min(100, (b.saldo / (posisi.ambangMenipis * 3)) * 100)}%`,
                        }}
                      />
                    </div>
                    <p className="mt-2 text-xs text-muted-foreground">
                      Ambang menipis: {kg(posisi.ambangMenipis)}
                    </p>
                  </CardContent>
                </Card>
              );
            })}
      </div>

      <h2 className="mb-3 mt-8 text-sm font-semibold uppercase tracking-wide text-muted-foreground">
        Stok Produk Jadi
      </h2>
      <div className="grid gap-4 md:grid-cols-2">
        {(posisi?.produkJadi ?? []).map((p) => (
          <StatCard
            key={p.kode}
            label={`Gula ${p.nama}`}
            value={kg(p.saldo)}
            tone={p.kode === "kristal" ? "success" : "warning"}
            hint={p.kode === "kristal" ? "Produk utama" : "Hasil sampingan"}
          />
        ))}
      </div>

      <Card className="mt-8 overflow-hidden shadow-card">
        <CardHeader>
          <CardTitle className="text-base">Kartu Stok</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4 px-0">
          <div className="flex flex-wrap items-end gap-3 px-4">
            <SearchInput value={q} onChange={setQ} placeholder="Cari keterangan..." />
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
            <div className="space-y-1">
              <Label className="text-xs">Kategori</Label>
              <Select
                value={fKategori}
                onValueChange={(v: "semua" | StokKategori) => setFKategori(v)}
              >
                <SelectTrigger className="w-[180px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="semua">Semua Kategori</SelectItem>
                  {KATEGORI_STOK.map((k) => (
                    <SelectItem key={k} value={k}>
                      {k}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <Label className="text-xs">Jenis</Label>
              <Select value={fJenis} onValueChange={(v: "semua" | JenisMutasi) => setFJenis(v)}>
                <SelectTrigger className="w-[140px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="semua">Semua</SelectItem>
                  <SelectItem value="Masuk">Masuk</SelectItem>
                  <SelectItem value="Keluar">Keluar</SelectItem>
                </SelectContent>
              </Select>
            </div>
            {(dari || sampai || fKategori !== "semua" || fJenis !== "semua" || q) && (
              <Button
                variant="ghost"
                onClick={() => {
                  setDari("");
                  setSampai("");
                  setFKategori("semua");
                  setFJenis("semua");
                  setQ("");
                }}
              >
                Reset filter
              </Button>
            )}
          </div>

          {kartuError && (
            <p className="px-4 text-sm text-destructive">
              Gagal memuat kartu stok. Coba muat ulang halaman.
            </p>
          )}

          {kartuLoading ? (
            <LoadingRow />
          ) : (
            <>
              <DataTable
                rows={rows}
                columns={cols}
                rowKey={(r) => r.id}
                empty={
                  <EmptyState
                    title="Kartu stok kosong"
                    description="Belum ada pergerakan stok yang cocok dengan filter."
                  />
                }
              />
              {meta && meta.total > 0 && (
                <div className="flex flex-wrap items-center justify-between gap-3 px-4 text-sm text-muted-foreground">
                  <span>
                    Menampilkan {meta.from ?? 0}–{meta.to ?? 0} dari {angka(meta.total)} mutasi
                  </span>
                  <div className="flex items-center gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={meta.current_page <= 1}
                      onClick={() => setPage((p) => p - 1)}
                    >
                      Sebelumnya
                    </Button>
                    <span>
                      Halaman {meta.current_page} dari {meta.last_page}
                    </span>
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={meta.current_page >= meta.last_page}
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

      <Dialog open={opnameOpen} onOpenChange={setOpnameOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Stok Opname</DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label>Kategori Stok</Label>
              <Select
                value={opname.kategori}
                onValueChange={(v: StokKategori) => setOpname({ ...opname, kategori: v })}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {KATEGORI_STOK.map((k) => (
                    <SelectItem key={k} value={k}>
                      {k}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">
                Stok sistem saat ini: {kg(saldoSaatIni)}
              </p>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label>Stok Fisik Hasil Hitung (kg)</Label>
                <Input
                  type="number"
                  min={0}
                  value={opname.fisik}
                  onChange={(e) => setOpname({ ...opname, fisik: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label>Tanggal</Label>
                <Input
                  type="date"
                  value={opname.tanggal}
                  onChange={(e) => setOpname({ ...opname, tanggal: e.target.value })}
                />
              </div>
            </div>
            <div className="space-y-2">
              <Label>Alasan Koreksi</Label>
              <Input
                value={opname.alasan}
                onChange={(e) => setOpname({ ...opname, alasan: e.target.value })}
                placeholder="Contoh: susut penyimpanan"
              />
            </div>
            <div className="rounded-xl bg-cream px-4 py-3 text-sm">
              Selisih koreksi:{" "}
              <span className="font-semibold">
                {selisih > 0 ? "+" : selisih < 0 ? "−" : ""}
                {angka(Math.abs(selisih))} kg
              </span>
            </div>
            {err && <p className="text-sm text-destructive">{err}</p>}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpnameOpen(false)}>
              Batal
            </Button>
            <Button onClick={simpanOpname} disabled={stokOpname.isPending}>
              {stokOpname.isPending && <Loader2 className="size-4 animate-spin" />}
              Simpan Koreksi
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
