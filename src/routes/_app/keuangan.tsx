import { useEffect, useState } from "react";
import { createFileRoute } from "@tanstack/react-router";
import {
  Bar,
  BarChart,
  CartesianGrid,
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { Loader2, Pencil, Plus, Trash2 } from "lucide-react";
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  DataTable,
  EmptyState,
  PageHeader,
  StatCard,
  type Column,
} from "@/components/sigula/ui-bits";
import { addDays, rupiah, tanggalPendek } from "@/lib/format";
import { todayISO } from "@/lib/sigula-seed";
import type { KategoriBiaya } from "@/lib/sigula-types";
import { ApiError } from "@/lib/api-client";
import type { Biaya, PeriodeLabaRugi } from "@/lib/api/keuangan";
import {
  useBiayaList,
  useHapusBiaya,
  useLabaRugi,
  useTambahBiaya,
  useTrenKeuangan,
  useUbahBiaya,
} from "@/hooks/use-keuangan";
import type { AuditLogEntry } from "@/lib/api/audit-log";
import { useAuditLog } from "@/hooks/use-audit-log";
import { ExportButton } from "@/components/sigula/export-button";
import { RingkasanAiCard } from "@/components/sigula/ringkasan-ai-card";

export const Route = createFileRoute("/_app/keuangan")({
  head: () => ({
    meta: [
      { title: "Keuangan & Laba Rugi — SIGULA" },
      {
        name: "description",
        content:
          "Laporan laba rugi: pendapatan penjualan, HPP pembelian bahan dan gaji karyawan, biaya operasional, margin keuntungan, dan tren 6 bulan.",
      },
      { property: "og:title", content: "Keuangan & Laba Rugi — SIGULA" },
      {
        property: "og:description",
        content: "Analisis pendapatan, biaya, dan laba bersih pengadaan gula.",
      },
    ],
  }),
  component: KeuanganPage,
});

const KATEGORI: KategoriBiaya[] = ["Listrik", "Transport", "Sewa", "Lainnya"];
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

interface BiayaFormState {
  tanggal: string;
  keterangan: string;
  kategori: KategoriBiaya;
  jumlah: string;
}

const emptyBiayaForm: BiayaFormState = {
  tanggal: todayISO(),
  keterangan: "",
  kategori: "Listrik",
  jumlah: "",
};

function KeuanganPage() {
  // ---- Periode laba rugi (dipakai juga untuk filter tanggal biaya) --------------
  const [periode, setPeriode] = useState<"ini" | "lalu" | "custom">("ini");
  const [dari, setDari] = useState(addDays(todayISO(), -30));
  const [sampai, setSampai] = useState(todayISO());

  const periodeApi: PeriodeLabaRugi =
    periode === "ini" ? "bulan_ini" : periode === "lalu" ? "bulan_lalu" : "custom";

  const {
    data: labaRugi,
    isLoading: labaRugiLoading,
    isError: labaRugiError,
  } = useLabaRugi({
    periode: periodeApi,
    dari: periode === "custom" ? dari : undefined,
    sampai: periode === "custom" ? sampai : undefined,
  });

  const rangeDari = labaRugi?.periode.dari ?? dari;
  const rangeSampai = labaRugi?.periode.sampai ?? sampai;

  // ---- Tren 6 bulan ---------------------------------------------------------------
  const { data: tren = [] } = useTrenKeuangan(6);

  // ---- Biaya operasional: filter & pagination --------------------------------------
  const [fKategori, setFKategori] = useState<"semua" | KategoriBiaya>("semua");
  const [page, setPage] = useState(1);

  useEffect(() => {
    setPage(1);
  }, [fKategori, rangeDari, rangeSampai]);

  const {
    data: biayaResult,
    isLoading: biayaLoading,
    isError: biayaError,
  } = useBiayaList({
    dari: rangeDari,
    sampai: rangeSampai,
    kategori: fKategori === "semua" ? undefined : fKategori,
    page,
    perPage: PER_PAGE,
  });

  const biayaRows = biayaResult?.data ?? [];
  const biayaMeta = biayaResult?.meta;

  // ---- CRUD biaya ---------------------------------------------------------------
  const tambahBiaya = useTambahBiaya();
  const ubahBiaya = useUbahBiaya();
  const hapusBiaya = useHapusBiaya();

  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState<Biaya | null>(null);
  const [form, setForm] = useState<BiayaFormState>(emptyBiayaForm);
  const [err, setErr] = useState<string | null>(null);

  const bukaTambah = () => {
    setEditing(null);
    setForm(emptyBiayaForm);
    setErr(null);
    setOpen(true);
  };

  const bukaEdit = (b: Biaya) => {
    setEditing(b);
    setForm({
      tanggal: b.tanggal,
      keterangan: b.keterangan,
      kategori: b.kategori,
      jumlah: String(b.jumlah),
    });
    setErr(null);
    setOpen(true);
  };

  const simpan = async () => {
    const n = Number(form.jumlah);
    if (!form.keterangan.trim()) return setErr("Keterangan wajib diisi.");
    if (!form.jumlah.trim() || Number.isNaN(n) || n <= 0)
      return setErr("Jumlah harus lebih dari 0.");
    if (!form.tanggal) return setErr("Tanggal wajib diisi.");

    const payload = {
      tanggal: form.tanggal,
      keterangan: form.keterangan.trim(),
      kategori: form.kategori,
      jumlah: n,
    };

    try {
      if (editing) {
        await ubahBiaya.mutateAsync({ id: editing.id, payload });
        toast.success("Biaya operasional diperbarui", {
          description: `${payload.keterangan} — ${rupiah(n)}`,
        });
      } else {
        await tambahBiaya.mutateAsync(payload);
        toast.success("Biaya operasional ditambahkan", {
          description: `${payload.keterangan} — ${rupiah(n)}`,
        });
      }
      setOpen(false);
    } catch (e) {
      setErr(apiErrorMessage(e, editing ? "Gagal mengubah biaya." : "Gagal menambah biaya."));
    }
  };

  const hapus = async (b: Biaya) => {
    try {
      await hapusBiaya.mutateAsync(b.id);
      toast.success("Biaya dihapus", { description: b.keterangan });
    } catch (e) {
      toast.error(apiErrorMessage(e, "Gagal menghapus biaya."));
    }
  };

  const saving = tambahBiaya.isPending || ubahBiaya.isPending;

  const biayaCols: Column<Biaya>[] = [
    { key: "tgl", header: "Tanggal", cell: (r) => tanggalPendek(r.tanggal) },
    { key: "ket", header: "Keterangan", cell: (r) => r.keterangan },
    {
      key: "kat",
      header: "Kategori",
      cell: (r) => <Badge variant="secondary">{r.kategori}</Badge>,
    },
    {
      key: "jml",
      header: "Jumlah",
      align: "right",
      cell: (r) => <span className="font-medium">{rupiah(r.jumlah)}</span>,
    },
    {
      key: "aksi",
      header: "Aksi",
      align: "right",
      cell: (r) => (
        <div className="flex justify-end gap-1">
          <Button variant="ghost" size="icon" aria-label="Ubah" onClick={() => bukaEdit(r)}>
            <Pencil className="size-4" />
          </Button>
          <Button variant="ghost" size="icon" aria-label="Hapus" onClick={() => hapus(r)}>
            <Trash2 className="size-4 text-destructive" />
          </Button>
        </div>
      ),
    },
  ];

  // ---- Audit log ---------------------------------------------------------------
  const [fAksi, setFAksi] = useState("");
  const [fUserId, setFUserId] = useState("");
  const [fAuditDari, setFAuditDari] = useState("");
  const [fAuditSampai, setFAuditSampai] = useState("");
  const [auditPage, setAuditPage] = useState(1);

  useEffect(() => {
    setAuditPage(1);
  }, [fAksi, fUserId, fAuditDari, fAuditSampai]);

  const {
    data: auditResult,
    isLoading: auditLoading,
    isError: auditError,
  } = useAuditLog({
    aksi: fAksi.trim() || undefined,
    userId: fUserId.trim() || undefined,
    dari: fAuditDari || undefined,
    sampai: fAuditSampai || undefined,
    page: auditPage,
    perPage: PER_PAGE,
  });

  const auditRows = auditResult?.data ?? [];
  const auditMeta = auditResult?.meta;

  const auditCols: Column<AuditLogEntry>[] = [
    {
      key: "waktu",
      header: "Waktu",
      cell: (r) => (r.waktu ? new Date(r.waktu).toLocaleString("id-ID") : "-"),
    },
    { key: "aksi", header: "Aksi", cell: (r) => <Badge variant="secondary">{r.aksi}</Badge> },
    { key: "deskripsi", header: "Deskripsi", cell: (r) => r.deskripsi },
    {
      key: "user",
      header: "Pelaku",
      cell: (r) =>
        r.user ? (
          <div className="text-xs">
            <p className="font-medium">{r.user.nama}</p>
            <p className="text-muted-foreground">{r.user.role}</p>
          </div>
        ) : (
          <span className="text-muted-foreground">Sistem</span>
        ),
    },
    { key: "ip", header: "IP", cell: (r) => r.ip ?? "-" },
  ];

  return (
    <>
      <PageHeader
        title="Keuangan & Laporan Laba Rugi"
        subtitle={`Periode ${tanggalPendek(rangeDari)} — ${tanggalPendek(rangeSampai)}`}
        action={
          <div className="flex flex-wrap gap-2">
            <ExportButton
              jenis="laba-rugi"
              label="Export Laba Rugi"
              params={{ dari: rangeDari, sampai: rangeSampai }}
            />
            <ExportButton
              jenis="biaya"
              label="Export Biaya"
              params={{
                dari: rangeDari,
                sampai: rangeSampai,
                kategori: fKategori === "semua" ? undefined : fKategori,
              }}
            />
          </div>
        }
      />

      <Tabs defaultValue="laba-rugi">
        <TabsList className="mb-4">
          <TabsTrigger value="laba-rugi">Laba Rugi & Biaya</TabsTrigger>
          <TabsTrigger value="audit">Audit Log</TabsTrigger>
        </TabsList>

        <TabsContent value="laba-rugi" className="space-y-6">
          <RingkasanAiCard dari={rangeDari} sampai={rangeSampai} />
          <Card className="shadow-card">
            <CardContent className="flex flex-wrap items-end gap-3 p-4">
              <div className="space-y-1">
                <Label className="text-xs">Periode</Label>
                <Select
                  value={periode}
                  onValueChange={(v: "ini" | "lalu" | "custom") => setPeriode(v)}
                >
                  <SelectTrigger className="w-[180px]">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="ini">Bulan Ini</SelectItem>
                    <SelectItem value="lalu">Bulan Lalu</SelectItem>
                    <SelectItem value="custom">Custom Range</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              {periode === "custom" && (
                <>
                  <div className="space-y-1">
                    <Label className="text-xs">Dari</Label>
                    <Input
                      type="date"
                      value={dari}
                      onChange={(e) => setDari(e.target.value)}
                      className="w-[170px]"
                    />
                  </div>
                  <div className="space-y-1">
                    <Label className="text-xs">Sampai</Label>
                    <Input
                      type="date"
                      value={sampai}
                      onChange={(e) => setSampai(e.target.value)}
                      className="w-[170px]"
                    />
                  </div>
                </>
              )}
            </CardContent>
          </Card>

          {labaRugiError && (
            <p className="text-sm text-destructive">
              Gagal memuat laporan laba rugi. Coba muat ulang halaman.
            </p>
          )}

          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
              label="Pendapatan"
              value={labaRugiLoading || !labaRugi ? "…" : rupiah(labaRugi.pendapatan)}
              tone="primary"
            />
            <StatCard
              label="Total HPP"
              value={labaRugiLoading || !labaRugi ? "…" : rupiah(labaRugi.hpp.total)}
              hint={
                labaRugi
                  ? `Bahan ${rupiah(labaRugi.hpp.bahan)} · Gaji ${rupiah(labaRugi.hpp.gaji.total)}`
                  : undefined
              }
            />
            <StatCard
              label="Biaya Operasional Lain"
              value={labaRugiLoading || !labaRugi ? "…" : rupiah(labaRugi.biayaOperasional)}
            />
            <StatCard
              label="Laba Bersih"
              value={
                labaRugiLoading || !labaRugi ? (
                  "…"
                ) : (
                  <span className={labaRugi.labaBersih >= 0 ? "text-success" : "text-destructive"}>
                    {rupiah(labaRugi.labaBersih)}
                  </span>
                )
              }
              tone={!labaRugi || labaRugi.labaBersih >= 0 ? "success" : "warning"}
              hint={
                labaRugi
                  ? labaRugi.pendapatan > 0
                    ? `Margin ${labaRugi.margin.toFixed(1)}%`
                    : "Belum ada pendapatan"
                  : undefined
              }
            />
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <Card className="shadow-card">
              <CardHeader>
                <CardTitle className="text-base">Pendapatan vs Biaya per Bulan</CardTitle>
              </CardHeader>
              <CardContent className="h-[280px]">
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={tren} margin={{ left: 8, right: 8 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                    <XAxis dataKey="label" tickLine={false} axisLine={false} fontSize={12} />
                    <YAxis
                      tickFormatter={(v: number) => `${Math.round(v / 1_000_000)}jt`}
                      tickLine={false}
                      axisLine={false}
                      fontSize={12}
                    />
                    <Tooltip formatter={(v) => rupiah(Number(v))} />
                    <Legend />
                    <Bar
                      dataKey="pendapatan"
                      name="Pendapatan"
                      fill="var(--chart-1)"
                      radius={[6, 6, 0, 0]}
                    />
                    <Bar
                      dataKey="totalBiaya"
                      name="Total Biaya"
                      fill="var(--chart-4)"
                      radius={[6, 6, 0, 0]}
                    />
                  </BarChart>
                </ResponsiveContainer>
              </CardContent>
            </Card>

            <Card className="shadow-card">
              <CardHeader>
                <CardTitle className="text-base">Tren Margin Keuntungan (%)</CardTitle>
              </CardHeader>
              <CardContent className="h-[280px]">
                <ResponsiveContainer width="100%" height="100%">
                  <LineChart data={tren} margin={{ left: 8, right: 8 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                    <XAxis dataKey="label" tickLine={false} axisLine={false} fontSize={12} />
                    <YAxis tickLine={false} axisLine={false} fontSize={12} unit="%" />
                    <Tooltip formatter={(v) => `${v}%`} />
                    <Line
                      type="monotone"
                      dataKey="margin"
                      name="Margin"
                      stroke="var(--chart-3)"
                      strokeWidth={3}
                      dot={{ r: 4 }}
                    />
                  </LineChart>
                </ResponsiveContainer>
              </CardContent>
            </Card>
          </div>

          <Card className="overflow-hidden shadow-card">
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle className="text-base">Biaya Operasional Lain-lain</CardTitle>
              <Button size="sm" onClick={bukaTambah}>
                <Plus className="mr-2 size-4" /> Tambah Biaya
              </Button>
            </CardHeader>
            <CardContent className="space-y-4 px-0">
              <div className="flex flex-wrap items-end gap-3 px-4">
                <div className="space-y-1">
                  <Label className="text-xs">Kategori</Label>
                  <Select
                    value={fKategori}
                    onValueChange={(v: "semua" | KategoriBiaya) => setFKategori(v)}
                  >
                    <SelectTrigger className="w-[160px]">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="semua">Semua Kategori</SelectItem>
                      {KATEGORI.map((k) => (
                        <SelectItem key={k} value={k}>
                          {k}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                {biayaResult && (
                  <p className="text-sm text-muted-foreground">
                    Total periode ini:{" "}
                    <span className="font-medium">{rupiah(biayaResult.ringkasan.total)}</span>
                  </p>
                )}
              </div>

              {biayaError && (
                <p className="px-4 text-sm text-destructive">
                  Gagal memuat daftar biaya. Coba muat ulang halaman.
                </p>
              )}

              {biayaLoading ? (
                <LoadingRow />
              ) : (
                <>
                  <DataTable
                    rows={biayaRows}
                    columns={biayaCols}
                    rowKey={(r) => r.id}
                    empty={
                      <EmptyState
                        title="Belum ada biaya"
                        description="Tambahkan biaya operasional pada periode ini."
                      />
                    }
                  />
                  {biayaMeta && biayaMeta.total > 0 && (
                    <div className="flex flex-wrap items-center justify-between gap-3 px-4 text-sm text-muted-foreground">
                      <span>
                        Menampilkan {biayaMeta.from ?? 0}–{biayaMeta.to ?? 0} dari {biayaMeta.total}{" "}
                        biaya
                      </span>
                      <div className="flex items-center gap-2">
                        <Button
                          variant="outline"
                          size="sm"
                          disabled={biayaMeta.current_page <= 1}
                          onClick={() => setPage((p) => p - 1)}
                        >
                          Sebelumnya
                        </Button>
                        <span>
                          Halaman {biayaMeta.current_page} dari {biayaMeta.last_page}
                        </span>
                        <Button
                          variant="outline"
                          size="sm"
                          disabled={biayaMeta.current_page >= biayaMeta.last_page}
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
        </TabsContent>

        <TabsContent value="audit">
          <Card className="overflow-hidden shadow-card">
            <CardHeader>
              <CardTitle className="text-base">Jejak Audit</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4 px-0">
              <div className="flex flex-wrap items-end gap-3 px-4">
                <div className="space-y-1">
                  <Label className="text-xs">Aksi (prefix)</Label>
                  <Input
                    value={fAksi}
                    onChange={(e) => setFAksi(e.target.value)}
                    placeholder="mis. harga. / produksi."
                    className="w-[200px]"
                  />
                </div>
                <div className="space-y-1">
                  <Label className="text-xs">User ID</Label>
                  <Input
                    value={fUserId}
                    onChange={(e) => setFUserId(e.target.value)}
                    placeholder="Filter pelaku"
                    className="w-[140px]"
                  />
                </div>
                <div className="space-y-1">
                  <Label className="text-xs">Dari Tanggal</Label>
                  <Input
                    type="date"
                    value={fAuditDari}
                    onChange={(e) => setFAuditDari(e.target.value)}
                    className="w-[160px]"
                  />
                </div>
                <div className="space-y-1">
                  <Label className="text-xs">Sampai Tanggal</Label>
                  <Input
                    type="date"
                    value={fAuditSampai}
                    onChange={(e) => setFAuditSampai(e.target.value)}
                    className="w-[160px]"
                  />
                </div>
                {(fAksi || fUserId || fAuditDari || fAuditSampai) && (
                  <Button
                    variant="ghost"
                    onClick={() => {
                      setFAksi("");
                      setFUserId("");
                      setFAuditDari("");
                      setFAuditSampai("");
                    }}
                  >
                    Reset filter
                  </Button>
                )}
              </div>

              {auditError && (
                <p className="px-4 text-sm text-destructive">
                  Gagal memuat audit log. Coba muat ulang halaman.
                </p>
              )}

              {auditLoading ? (
                <LoadingRow />
              ) : (
                <>
                  <DataTable
                    rows={auditRows}
                    columns={auditCols}
                    rowKey={(r) => r.id}
                    empty={
                      <EmptyState
                        title="Tidak ada jejak audit"
                        description="Tidak ada aktivitas yang cocok dengan filter saat ini."
                      />
                    }
                  />
                  {auditMeta && auditMeta.total > 0 && (
                    <div className="flex flex-wrap items-center justify-between gap-3 px-4 text-sm text-muted-foreground">
                      <span>
                        {auditMeta.total} entri · Halaman {auditMeta.currentPage} dari{" "}
                        {auditMeta.lastPage}
                      </span>
                      <div className="flex items-center gap-2">
                        <Button
                          variant="outline"
                          size="sm"
                          disabled={auditMeta.currentPage <= 1}
                          onClick={() => setAuditPage((p) => p - 1)}
                        >
                          Sebelumnya
                        </Button>
                        <Button
                          variant="outline"
                          size="sm"
                          disabled={auditMeta.currentPage >= auditMeta.lastPage}
                          onClick={() => setAuditPage((p) => p + 1)}
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
        </TabsContent>
      </Tabs>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {editing ? "Ubah Biaya Operasional" : "Tambah Biaya Operasional"}
            </DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label>Tanggal</Label>
              <Input
                type="date"
                value={form.tanggal}
                onChange={(e) => setForm({ ...form, tanggal: e.target.value })}
              />
            </div>
            <div className="space-y-2">
              <Label>Keterangan</Label>
              <Input
                value={form.keterangan}
                onChange={(e) => setForm({ ...form, keterangan: e.target.value })}
                placeholder="Contoh: tagihan listrik pabrik"
              />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label>Kategori</Label>
                <Select
                  value={form.kategori}
                  onValueChange={(v: KategoriBiaya) => setForm({ ...form, kategori: v })}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {KATEGORI.map((k) => (
                      <SelectItem key={k} value={k}>
                        {k}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Jumlah (Rp)</Label>
                <Input
                  type="number"
                  min={0}
                  value={form.jumlah}
                  onChange={(e) => setForm({ ...form, jumlah: e.target.value })}
                />
              </div>
            </div>
            {err && <p className="text-sm text-destructive">{err}</p>}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)}>
              Batal
            </Button>
            <Button onClick={simpan} disabled={saving}>
              {saving && <Loader2 className="size-4 animate-spin" />}
              {editing ? "Simpan Perubahan" : "Simpan Biaya"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
