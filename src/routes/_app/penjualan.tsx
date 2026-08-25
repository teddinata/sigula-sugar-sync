import { useEffect, useState } from "react";
import { createFileRoute } from "@tanstack/react-router";
import { Loader2, Plus, Printer, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
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
import { Textarea } from "@/components/ui/textarea";
import { PrintDialog, PrintRow } from "@/components/sigula/print-dialog";
import {
  DataTable,
  EmptyState,
  PageHeader,
  StatCard,
  type Column,
} from "@/components/sigula/ui-bits";
import { angka, rupiah, tanggalPendek } from "@/lib/format";
import { todayISO } from "@/lib/sigula-seed";
import { ApiError } from "@/lib/api-client";
import {
  getPenjualan,
  type Penjualan,
  type PenjualanInvoice,
  type StatusPembayaranKode,
} from "@/lib/api/penjualan";
import {
  useBatalkanPenjualan,
  usePenjualanList,
  useTambahPenjualan,
  useUbahStatusPenjualan,
} from "@/hooks/use-penjualan";
import { useEksportirList } from "@/hooks/use-master-data";
import { useStokPosisi } from "@/hooks/use-stok";
import { ExportButton } from "@/components/sigula/export-button";

export const Route = createFileRoute("/_app/penjualan")({
  head: () => ({
    meta: [
      { title: "Penjualan ke Eksportir — SIGULA" },
      {
        name: "description",
        content:
          "Buat invoice penjualan gula kristal dan brondol dengan harga berbeda per baris, kalkulasi dua arah, validasi stok, dan cetak invoice.",
      },
      { property: "og:title", content: "Penjualan ke Eksportir — SIGULA" },
      { property: "og:description", content: "Invoice dua baris: gula kristal dan gula brondol." },
    ],
  }),
  component: PenjualanPage,
});

const PER_PAGE = 25;

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

interface Baris {
  aktif: boolean;
  kg: string;
  harga: string;
  subtotal: string;
  autoHarga: boolean;
}

const barisAwal: Baris = { aktif: false, kg: "", harga: "", subtotal: "", autoHarga: false };

function PenjualanPage() {
  // ---- Filter & pagination ---------------------------------------------------
  const [q, setQ] = useState("");
  const [qDebounced, setQDebounced] = useState("");
  const [dari, setDari] = useState("");
  const [sampai, setSampai] = useState("");
  const [fEksportirId, setFEksportirId] = useState("semua");
  const [page, setPage] = useState(1);

  useEffect(() => {
    const t = setTimeout(() => setQDebounced(q.trim()), 300);
    return () => clearTimeout(t);
  }, [q]);

  useEffect(() => {
    setPage(1);
  }, [qDebounced, dari, sampai, fEksportirId]);

  const { data: eksportirList = [] } = useEksportirList();

  const {
    data: listResult,
    isLoading,
    isError,
  } = usePenjualanList({
    dari: dari || undefined,
    sampai: sampai || undefined,
    eksportirId: fEksportirId === "semua" ? undefined : fEksportirId,
    q: qDebounced || undefined,
    page,
    perPage: PER_PAGE,
  });

  const rows = listResult?.data ?? [];
  const meta = listResult?.meta;
  const ringkasan = listResult?.ringkasan;
  const filterAktif = Boolean(dari || sampai || fEksportirId !== "semua" || q);

  const resetFilter = () => {
    setDari("");
    setSampai("");
    setFEksportirId("semua");
    setQ("");
  };

  // ---- Stok kristal/brondol (untuk hint & validasi form) ----------------------------
  const { data: posisi } = useStokPosisi();

  // ---- Transaksi baru ----------------------------------------------------------
  const tambahPenjualan = useTambahPenjualan();
  const [open, setOpen] = useState(false);
  const [eksportirId, setEksportirId] = useState("");
  const [tanggal, setTanggal] = useState(todayISO());
  const [statusPembayaran, setStatusPembayaran] = useState<StatusPembayaranKode>("lunas");
  const [kristal, setKristal] = useState<Baris>(barisAwal);
  const [brondol, setBrondol] = useState<Baris>(barisAwal);
  const [err, setErr] = useState<string | null>(null);
  const [invoice, setInvoice] = useState<PenjualanInvoice | null>(null);

  const num = (v: string) => Number(v) || 0;
  const subKristal = kristal.aktif ? num(kristal.kg) * num(kristal.harga) : 0;
  const subBrondol = brondol.aktif ? num(brondol.kg) * num(brondol.harga) : 0;
  const totalJual = subKristal + subBrondol;

  const setKgHarga = (which: "kristal" | "brondol", patch: Partial<Baris>) => {
    const setter = which === "kristal" ? setKristal : setBrondol;
    setter((prev) => {
      const next = { ...prev, ...patch, autoHarga: false };
      next.subtotal = String(num(next.kg) * num(next.harga));
      return next;
    });
  };

  /** kalkulasi dua arah: user edit subtotal -> harga menyesuaikan, kg tetap */
  const editSubtotal = (which: "kristal" | "brondol", value: string) => {
    const setter = which === "kristal" ? setKristal : setBrondol;
    setter((prev) => {
      if (num(prev.kg) <= 0) {
        setErr(
          `Kilogram ${which === "kristal" ? "kristal" : "brondol"} harus diisi sebelum mengubah subtotal.`,
        );
        return { ...prev, subtotal: value };
      }
      setErr(null);
      const hargaBaru = num(value) / num(prev.kg);
      return {
        ...prev,
        subtotal: value,
        harga: String(Math.round(hargaBaru * 100) / 100),
        autoHarga: true,
      };
    });
  };

  const reset = () => {
    setEksportirId("");
    setTanggal(todayISO());
    setStatusPembayaran("lunas");
    setKristal(barisAwal);
    setBrondol(barisAwal);
    setErr(null);
  };

  const buka = () => {
    reset();
    setOpen(true);
  };

  const simpan = async (cetak: boolean) => {
    if (!eksportirId) return setErr("Eksportir wajib dipilih.");
    if (!tanggal) return setErr("Tanggal wajib diisi.");
    if (!kristal.aktif && !brondol.aktif)
      return setErr("Minimal salah satu baris (kristal / brondol) harus diisi.");
    if (kristal.aktif) {
      if (num(kristal.kg) <= 0) return setErr("Kilogram kristal harus lebih dari 0.");
      if (num(kristal.harga) <= 0) return setErr("Harga jual kristal harus lebih dari 0.");
      if (posisi && num(kristal.kg) > posisi.saldo["Kristal"])
        return setErr(`Stok kristal hanya ${angka(posisi.saldo["Kristal"])} kg.`);
    }
    if (brondol.aktif) {
      if (num(brondol.kg) <= 0) return setErr("Kilogram brondol harus lebih dari 0.");
      if (num(brondol.harga) <= 0) return setErr("Harga jual brondol harus lebih dari 0.");
      if (posisi && num(brondol.kg) > posisi.saldo["Brondol"])
        return setErr(`Stok brondol hanya ${angka(posisi.saldo["Brondol"])} kg.`);
    }

    try {
      const res = await tambahPenjualan.mutateAsync({
        tanggal,
        eksportirId,
        kristal: kristal.aktif ? { kg: num(kristal.kg), harga: num(kristal.harga) } : undefined,
        brondol: brondol.aktif ? { kg: num(brondol.kg), harga: num(brondol.harga) } : undefined,
        statusPembayaran,
      });
      toast.success("Transaksi penjualan tersimpan", {
        description: `${res.invoice.eksportir ?? "-"} — ${rupiah(res.penjualan.total)}`,
      });
      setOpen(false);
      reset();
      if (cetak) setInvoice(res.invoice);
    } catch (e) {
      setErr(apiErrorMessage(e, "Gagal menyimpan transaksi penjualan."));
    }
  };

  // ---- Cetak ulang invoice ------------------------------------------------------
  const [printLoadingId, setPrintLoadingId] = useState<string | null>(null);

  const cetakUlang = async (id: string) => {
    setPrintLoadingId(id);
    try {
      const res = await getPenjualan(id);
      setInvoice(res.invoice);
    } catch (e) {
      toast.error(apiErrorMessage(e, "Gagal memuat data invoice."));
    } finally {
      setPrintLoadingId(null);
    }
  };

  // ---- Ubah status pembayaran ---------------------------------------------------
  const ubahStatus = useUbahStatusPenjualan();

  const toggleStatus = async (r: Penjualan) => {
    const statusBaru: StatusPembayaranKode =
      r.statusPembayaranKode === "lunas" ? "belum_lunas" : "lunas";
    try {
      const res = await ubahStatus.mutateAsync({ id: r.id, status: statusBaru });
      toast.success(res.message, { description: r.noInvoice });
    } catch (e) {
      toast.error(apiErrorMessage(e, "Gagal mengubah status pembayaran."));
    }
  };

  // ---- Batalkan transaksi ------------------------------------------------------
  const batalkanPenjualan = useBatalkanPenjualan();
  const [batalTarget, setBatalTarget] = useState<Penjualan | null>(null);
  const [alasanBatal, setAlasanBatal] = useState("");

  const konfirmasiBatal = async () => {
    if (!batalTarget) return;
    try {
      const res = await batalkanPenjualan.mutateAsync({
        id: batalTarget.id,
        alasan: alasanBatal.trim() || undefined,
      });
      toast.success(res.message, { description: batalTarget.noInvoice });
      setBatalTarget(null);
      setAlasanBatal("");
    } catch (e) {
      toast.error(apiErrorMessage(e, "Gagal membatalkan transaksi."));
    }
  };

  const cols: Column<Penjualan>[] = [
    { key: "tgl", header: "Tanggal", cell: (r) => tanggalPendek(r.tanggal) },
    {
      key: "inv",
      header: "No. Invoice",
      cell: (r) => <span className="font-medium">{r.noInvoice}</span>,
    },
    { key: "eks", header: "Eksportir", cell: (r) => r.namaEksportir ?? "-" },
    {
      key: "k",
      header: "Kristal",
      align: "right",
      cell: (r) =>
        r.kristal ? (
          <div className="text-xs">
            <p className="font-medium">{angka(r.kristal.kg)} kg</p>
            <p className="text-muted-foreground">@ {rupiah(r.kristal.harga)}</p>
          </div>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
    {
      key: "b",
      header: "Brondol",
      align: "right",
      cell: (r) =>
        r.brondol ? (
          <div className="text-xs">
            <p className="font-medium">{angka(r.brondol.kg)} kg</p>
            <p className="text-muted-foreground">@ {rupiah(r.brondol.harga)}</p>
          </div>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
    {
      key: "total",
      header: "Total",
      align: "right",
      cell: (r) => <span className="font-semibold">{rupiah(r.total)}</span>,
    },
    {
      key: "status",
      header: "Status",
      cell: (r) => (
        <button
          type="button"
          onClick={() => toggleStatus(r)}
          disabled={ubahStatus.isPending}
          className="cursor-pointer disabled:cursor-not-allowed disabled:opacity-50"
          title="Klik untuk ubah status pembayaran"
        >
          {r.statusPembayaranKode === "lunas" ? (
            <Badge className="bg-success/15 text-success hover:bg-success/15">Lunas</Badge>
          ) : (
            <Badge className="bg-warning/25 text-warning-foreground hover:bg-warning/25">
              Belum Lunas
            </Badge>
          )}
        </button>
      ),
    },
    {
      key: "aksi",
      header: "Aksi",
      align: "right",
      cell: (r) => (
        <div className="flex justify-end gap-1">
          <Button
            variant="ghost"
            size="icon"
            aria-label="Cetak invoice"
            disabled={printLoadingId === r.id}
            onClick={() => cetakUlang(r.id)}
          >
            {printLoadingId === r.id ? (
              <Loader2 className="size-4 animate-spin" />
            ) : (
              <Printer className="size-4" />
            )}
          </Button>
          <Button
            variant="ghost"
            size="icon"
            aria-label="Batalkan"
            onClick={() => {
              setBatalTarget(r);
              setAlasanBatal("");
            }}
          >
            <Trash2 className="size-4 text-destructive" />
          </Button>
        </div>
      ),
    },
  ];

  const barisForm = (
    label: string,
    baris: Baris,
    which: "kristal" | "brondol",
    stokTersedia: number | undefined,
  ) => (
    <div className="rounded-xl border p-4">
      <label className="flex items-center gap-2 text-sm font-medium">
        <input
          type="checkbox"
          checked={baris.aktif}
          onChange={(e) =>
            (which === "kristal" ? setKristal : setBrondol)({ ...baris, aktif: e.target.checked })
          }
          className="size-4 accent-[var(--primary)]"
        />
        Baris {label} (opsional)
      </label>
      <p className="mt-1 text-xs text-muted-foreground">
        Stok {label} Tersedia:{" "}
        <span className="font-medium">
          {stokTersedia !== undefined ? `${angka(stokTersedia)} kg` : "…"}
        </span>
      </p>
      {baris.aktif && (
        <div className="mt-3 grid gap-3 sm:grid-cols-3">
          <div className="space-y-1">
            <Label className="text-xs">Kilogram</Label>
            <Input
              type="number"
              min={0}
              value={baris.kg}
              onChange={(e) => setKgHarga(which, { kg: e.target.value })}
              placeholder="0"
            />
          </div>
          <div className="space-y-1">
            <Label className="text-xs">Harga Jual / kg</Label>
            <Input
              type="number"
              min={0}
              value={baris.harga}
              onChange={(e) => setKgHarga(which, { harga: e.target.value })}
              className={baris.autoHarga ? "border-primary ring-2 ring-primary/25" : undefined}
              placeholder="0"
            />
            {baris.autoHarga && (
              <p className="text-[11px] font-medium text-primary">Harga disesuaikan otomatis</p>
            )}
          </div>
          <div className="space-y-1">
            <Label className="text-xs">Subtotal (bisa diedit)</Label>
            <Input
              type="number"
              min={0}
              value={baris.subtotal}
              onChange={(e) => editSubtotal(which, e.target.value)}
            />
          </div>
        </div>
      )}
    </div>
  );

  return (
    <>
      <PageHeader
        title="Penjualan ke Eksportir"
        subtitle="Satu invoice dapat memuat baris gula kristal dan gula brondol dengan harga berbeda"
        action={
          <div className="flex flex-wrap gap-2">
            <ExportButton
              jenis="penjualan"
              params={{
                dari: dari || undefined,
                sampai: sampai || undefined,
                eksportirId: fEksportirId === "semua" ? undefined : fEksportirId,
              }}
            />
            <Button onClick={buka}>
              <Plus className="mr-2 size-4" /> Transaksi Penjualan Baru
            </Button>
          </div>
        }
      />

      <div className="grid gap-4 sm:grid-cols-3">
        <StatCard
          label="Total Penjualan Bulan Ini"
          value={ringkasan ? rupiah(ringkasan.rupiah) : "…"}
          tone="primary"
          hint={ringkasan ? `${ringkasan.jumlahTransaksi} transaksi` : undefined}
        />
        <StatCard
          label="Kristal Terjual Bulan Ini"
          value={ringkasan ? `${angka(ringkasan.kgKristal)} kg` : "…"}
          tone="success"
          hint={ringkasan ? rupiah(ringkasan.rupiahKristal) : undefined}
        />
        <StatCard
          label="Brondol Terjual Bulan Ini"
          value={ringkasan ? `${angka(ringkasan.kgBrondol)} kg` : "…"}
          tone="warning"
          hint={ringkasan ? rupiah(ringkasan.rupiahBrondol) : undefined}
        />
      </div>

      <Card className="mt-6 overflow-hidden shadow-card">
        <CardContent className="space-y-4 px-0 py-4">
          <div className="flex flex-wrap items-end gap-3 px-4">
            <div className="space-y-1">
              <Label className="text-xs">Cari (eksportir / no. invoice)</Label>
              <Input
                value={q}
                onChange={(e) => setQ(e.target.value)}
                placeholder="Cari..."
                className="w-[220px]"
              />
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
            <div className="space-y-1">
              <Label className="text-xs">Eksportir</Label>
              <Select value={fEksportirId} onValueChange={setFEksportirId}>
                <SelectTrigger className="w-[220px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="semua">Semua Eksportir</SelectItem>
                  {eksportirList.map((e) => (
                    <SelectItem key={e.id} value={e.id}>
                      {e.nama}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            {filterAktif && (
              <Button variant="ghost" onClick={resetFilter}>
                Reset filter
              </Button>
            )}
          </div>

          {isError && (
            <p className="px-4 text-sm text-destructive">
              Gagal memuat data penjualan. Coba muat ulang halaman.
            </p>
          )}

          {isLoading ? (
            <LoadingRow />
          ) : (
            <>
              <DataTable
                rows={rows}
                columns={cols}
                rowKey={(r) => r.id}
                empty={
                  <EmptyState
                    title="Belum ada penjualan"
                    description="Buat transaksi penjualan pertama."
                    action={
                      <Button onClick={buka}>
                        <Plus className="mr-2 size-4" /> Transaksi Penjualan Baru
                      </Button>
                    }
                  />
                }
              />
              {meta && meta.total > 0 && (
                <div className="flex flex-wrap items-center justify-between gap-3 px-4 text-sm text-muted-foreground">
                  <span>
                    Menampilkan {meta.from ?? 0}–{meta.to ?? 0} dari {angka(meta.total)} transaksi
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

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Transaksi Penjualan Baru</DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-3">
              <div className="space-y-2">
                <Label>Eksportir</Label>
                <Select value={eksportirId} onValueChange={setEksportirId}>
                  <SelectTrigger>
                    <SelectValue placeholder="Pilih eksportir" />
                  </SelectTrigger>
                  <SelectContent>
                    {eksportirList.map((e) => (
                      <SelectItem key={e.id} value={e.id}>
                        {e.nama}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Tanggal</Label>
                <Input type="date" value={tanggal} onChange={(e) => setTanggal(e.target.value)} />
              </div>
              <div className="space-y-2">
                <Label>Status Pembayaran</Label>
                <Select
                  value={statusPembayaran}
                  onValueChange={(v: StatusPembayaranKode) => setStatusPembayaran(v)}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="lunas">Lunas</SelectItem>
                    <SelectItem value="belum_lunas">Belum Lunas</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>

            {barisForm("Kristal", kristal, "kristal", posisi?.saldo["Kristal"])}
            {barisForm("Brondol", brondol, "brondol", posisi?.saldo["Brondol"])}

            <div className="rounded-xl bg-cream px-4 py-3">
              <div className="flex justify-between text-sm">
                <span className="text-muted-foreground">Subtotal Kristal</span>
                <span>{rupiah(subKristal)}</span>
              </div>
              <div className="flex justify-between text-sm">
                <span className="text-muted-foreground">Subtotal Brondol</span>
                <span>{rupiah(subBrondol)}</span>
              </div>
              <div className="mt-2 flex justify-between border-t pt-2 text-lg font-semibold">
                <span>Total Penjualan</span>
                <span>{rupiah(totalJual)}</span>
              </div>
            </div>
            {err && <p className="text-sm text-destructive">{err}</p>}
          </div>
          <DialogFooter className="flex-col gap-2 sm:flex-row">
            <Button variant="outline" onClick={() => setOpen(false)}>
              Batal
            </Button>
            <Button
              variant="secondary"
              onClick={() => simpan(false)}
              disabled={tambahPenjualan.isPending}
            >
              {tambahPenjualan.isPending && <Loader2 className="size-4 animate-spin" />}
              Simpan
            </Button>
            <Button onClick={() => simpan(true)} disabled={tambahPenjualan.isPending}>
              {tambahPenjualan.isPending ? (
                <Loader2 className="mr-2 size-4 animate-spin" />
              ) : (
                <Printer className="mr-2 size-4" />
              )}
              Simpan & Cetak Invoice
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Konfirmasi batalkan */}
      <Dialog open={batalTarget !== null} onOpenChange={(v) => !v && setBatalTarget(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Batalkan Transaksi Penjualan</DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
              Transaksi{" "}
              <span className="font-medium text-foreground">{batalTarget?.noInvoice}</span> akan
              dibatalkan dan stok kristal/brondol yang sudah keluar akan dikembalikan. Tindakan ini
              tidak bisa dibatalkan.
            </p>
            <div className="space-y-2">
              <Label>Alasan pembatalan (opsional)</Label>
              <Textarea
                value={alasanBatal}
                onChange={(e) => setAlasanBatal(e.target.value)}
                placeholder="Contoh: Barang dikembalikan eksportir"
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setBatalTarget(null)}>
              Tutup
            </Button>
            <Button
              variant="destructive"
              onClick={konfirmasiBatal}
              disabled={batalkanPenjualan.isPending}
            >
              {batalkanPenjualan.isPending && <Loader2 className="size-4 animate-spin" />}
              Ya, Batalkan
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <PrintDialog
        open={invoice !== null}
        onOpenChange={(v) => !v && setInvoice(null)}
        title="Invoice Penjualan"
      >
        {invoice && (
          <div>
            <p className="mb-3 text-center text-sm font-semibold">INVOICE PENJUALAN</p>
            <PrintRow label="No. Invoice" value={invoice.nomor} />
            <PrintRow label="Tanggal" value={invoice.tanggal} />
            <PrintRow label="Pembeli" value={invoice.eksportir ?? "-"} />
            <div className="mt-2 border-t pt-2">
              {invoice.baris.map((b) => (
                <PrintRow
                  key={b.jenis}
                  label={`${b.jenis} — ${angka(b.kilogram)} kg × ${rupiah(b.hargaPerKg)}`}
                  value={rupiah(b.subtotal)}
                />
              ))}
            </div>
            <div className="mt-2 border-t pt-2">
              <PrintRow label="Total" value={rupiah(invoice.total)} strong />
              <PrintRow label="Status" value={invoice.statusPembayaran} />
            </div>
          </div>
        )}
      </PrintDialog>
    </>
  );
}
