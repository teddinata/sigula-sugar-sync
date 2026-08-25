import { useMemo, useState } from "react";
import { createFileRoute } from "@tanstack/react-router";
import { AlertTriangle, ChevronLeft, ChevronRight, Loader2, Printer, Wallet } from "lucide-react";
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
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { PrintDialog, PrintRow } from "@/components/sigula/print-dialog";
import { BarisThermal, GarisThermal, JudulThermal } from "@/components/sigula/thermal-print";
import {
  DataTable,
  EmptyState,
  PageHeader,
  SearchInput,
  StatCard,
  type Column,
} from "@/components/sigula/ui-bits";
import { addDays, angka, mondayOf, rupiah } from "@/lib/format";
import { todayISO } from "@/lib/sigula-seed";
import { ApiError } from "@/lib/api-client";
import type { BarisGaji } from "@/lib/api/penggajian";
import { useBayarGaji, useBayarSemuaGaji, useRekapGaji, useSlipGaji } from "@/hooks/use-penggajian";

export const Route = createFileRoute("/_app/penggajian")({
  head: () => ({
    meta: [
      { title: "Penggajian Karyawan — SIGULA" },
      {
        name: "description",
        content:
          "Hitung gaji karyawan periode Senin-Jumat: upah per kg gula kristal & brondol, uang makan harian, dan cetak slip gaji lengkap.",
      },
      { property: "og:title", content: "Penggajian Karyawan — SIGULA" },
      { property: "og:description", content: "Penggajian mingguan berbasis hasil sesi tungku." },
    ],
  }),
  component: PenggajianPage,
});

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

const EMPTY_BARIS: BarisGaji[] = [];

function PenggajianPage() {
  const [senin, setSenin] = useState(() => mondayOf(todayISO()));
  const [sertakanTanpaProduksi, setSertakanTanpaProduksi] = useState(false);
  const [q, setQ] = useState("");

  const {
    data: rekap,
    isLoading,
    isError,
  } = useRekapGaji({ tanggal: senin, sertakanTanpaProduksi });

  const rows = rekap?.baris ?? EMPTY_BARIS;
  const filtered = useMemo(() => {
    const term = q.trim().toLowerCase();
    return term ? rows.filter((r) => r.nama.toLowerCase().includes(term)) : rows;
  }, [rows, q]);

  // ---- Bayar 1 karyawan ---------------------------------------------------------
  const bayarGaji = useBayarGaji();
  const [bayarTarget, setBayarTarget] = useState<BarisGaji | null>(null);

  const konfirmasiBayar = async () => {
    if (!bayarTarget) return;
    try {
      await bayarGaji.mutateAsync({ karyawanId: bayarTarget.karyawanId, tanggal: senin });
      toast.success("Gaji dibayarkan", {
        description: `${bayarTarget.nama} — ${rupiah(bayarTarget.total)}`,
      });
      setBayarTarget(null);
    } catch (e) {
      toast.error(apiErrorMessage(e, "Gagal membayar gaji karyawan ini."));
    }
  };

  // ---- Bayar semua ---------------------------------------------------------------
  const bayarSemuaGaji = useBayarSemuaGaji();
  const [konfirmasiBayarSemua, setKonfirmasiBayarSemua] = useState(false);

  const jalankanBayarSemua = async () => {
    try {
      const res = await bayarSemuaGaji.mutateAsync(senin);
      toast.success(res.message);
      setKonfirmasiBayarSemua(false);
    } catch (e) {
      toast.error(apiErrorMessage(e, "Gagal membayar semua gaji."));
    }
  };

  // ---- Slip gaji ---------------------------------------------------------------
  const [slipKaryawanId, setSlipKaryawanId] = useState<string | null>(null);
  const { data: slip, isLoading: slipLoading } = useSlipGaji(slipKaryawanId ?? undefined, senin);

  const cols: Column<BarisGaji>[] = [
    {
      key: "nama",
      header: "Nama",
      sortValue: (r) => r.nama,
      cell: (r) => <span className="font-medium">{r.nama}</span>,
    },
    {
      key: "kristal",
      header: "Kg Kristal",
      align: "right",
      sortValue: (r) => r.kgKristal,
      cell: (r) => `${angka(r.kgKristal, 1)} kg`,
    },
    {
      key: "brondol",
      header: "Kg Brondol",
      align: "right",
      sortValue: (r) => r.kgBrondol,
      cell: (r) => `${angka(r.kgBrondol, 1)} kg`,
    },
    {
      key: "hari",
      header: "Hari Kerja",
      align: "center",
      sortValue: (r) => r.hariKerja,
      cell: (r) => `${r.hariKerja} hari`,
    },
    {
      key: "uk",
      header: "Upah Kristal",
      align: "right",
      sortValue: (r) => r.upahKristal,
      cell: (r) => rupiah(r.upahKristal),
    },
    {
      key: "ub",
      header: "Upah Brondol",
      align: "right",
      sortValue: (r) => r.upahBrondol,
      cell: (r) => rupiah(r.upahBrondol),
    },
    {
      key: "um",
      header: "Uang Makan",
      align: "right",
      sortValue: (r) => r.uangMakan,
      cell: (r) => rupiah(r.uangMakan),
    },
    {
      key: "total",
      header: "Total Gaji",
      align: "right",
      sortValue: (r) => r.total,
      cell: (r) => <span className="font-semibold">{rupiah(r.total)}</span>,
    },
    {
      key: "status",
      header: "Status",
      sortValue: (r) => String(r.dibayar),
      cell: (r) => (
        <div className="flex items-center gap-1.5">
          {r.dibayar ? (
            <Badge className="bg-success/15 text-success hover:bg-success/15">Sudah Dibayar</Badge>
          ) : (
            <Badge className="bg-warning/25 text-warning-foreground hover:bg-warning/25">
              Belum Dibayar
            </Badge>
          )}
          {r.adaPerubahanSetelahDibayar && (
            <span
              title="Data produksi berubah setelah gaji ini dibayar — periksa manual"
              aria-label="Data produksi berubah setelah gaji ini dibayar"
            >
              <AlertTriangle className="size-4 text-warning-foreground" />
            </span>
          )}
        </div>
      ),
    },
    {
      key: "aksi",
      header: "Aksi",
      align: "right",
      cell: (r) => (
        <div className="flex justify-end gap-1">
          {!r.dibayar && (
            <Button size="sm" onClick={() => setBayarTarget(r)}>
              Bayar
            </Button>
          )}
          <Button
            variant="ghost"
            size="icon"
            aria-label="Cetak slip gaji"
            onClick={() => setSlipKaryawanId(r.karyawanId)}
          >
            <Printer className="size-4" />
          </Button>
        </div>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title="Penggajian Karyawan"
        subtitle="Periode Senin s.d. Jumat, dibayarkan setiap hari Jumat"
        action={
          <Button
            onClick={() => setKonfirmasiBayarSemua(true)}
            disabled={!rekap || rekap.ringkasan.belumDibayar <= 0}
          >
            <Wallet className="mr-2 size-4" /> Bayar Semua
          </Button>
        }
      />

      <Card className="mb-4 shadow-card">
        <CardContent className="flex flex-wrap items-center justify-between gap-3 p-4">
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="icon"
              onClick={() => setSenin(addDays(senin, -7))}
              aria-label="Minggu sebelumnya"
            >
              <ChevronLeft className="size-4" />
            </Button>
            <Button variant="outline" onClick={() => setSenin(mondayOf(todayISO()))}>
              Minggu Ini
            </Button>
            <Button
              variant="outline"
              size="icon"
              onClick={() => setSenin(addDays(senin, 7))}
              aria-label="Minggu berikutnya"
            >
              <ChevronRight className="size-4" />
            </Button>
          </div>
          <p className="text-sm font-medium">
            {rekap ? `Periode: ${rekap.periode.label}` : "Memuat periode…"}
          </p>
        </CardContent>
      </Card>

      <div className="grid gap-4 sm:grid-cols-3">
        <StatCard
          label="Total Gaji Minggu Ini"
          value={rekap ? rupiah(rekap.ringkasan.totalGaji) : "…"}
          tone="primary"
          hint={rekap ? `Dibayarkan ${rekap.periode.jumat}` : undefined}
        />
        <StatCard
          label="Belum Dibayar"
          value={rekap ? rupiah(rekap.ringkasan.belumDibayar) : "…"}
          tone="warning"
        />
        <StatCard
          label="Karyawan Aktif Periode Ini"
          value={rekap ? `${rekap.ringkasan.jumlahKaryawan} orang` : "…"}
        />
      </div>

      <Card className="mt-6 overflow-hidden shadow-card">
        <CardContent className="space-y-4 px-0 py-4">
          <div className="flex flex-wrap items-center gap-3 px-4">
            <SearchInput value={q} onChange={setQ} placeholder="Cari nama karyawan..." />
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <Switch
                id="tanpa-produksi"
                checked={sertakanTanpaProduksi}
                onCheckedChange={setSertakanTanpaProduksi}
              />
              <Label htmlFor="tanpa-produksi" className="cursor-pointer font-normal">
                Sertakan karyawan tanpa produksi
              </Label>
            </div>
          </div>

          {isError && (
            <p className="px-4 text-sm text-destructive">
              Gagal memuat data gaji. Coba muat ulang halaman.
            </p>
          )}

          {isLoading ? (
            <LoadingRow />
          ) : (
            <DataTable
              rows={filtered}
              columns={cols}
              rowKey={(r) => r.karyawanId}
              initialSort={{ key: "total", dir: "desc" }}
              empty={
                <EmptyState
                  title="Belum ada data gaji"
                  description="Tidak ada sesi tungku selesai pada periode Senin-Jumat ini."
                />
              }
            />
          )}
        </CardContent>
      </Card>

      {/* Konfirmasi bayar 1 karyawan */}
      <Dialog open={bayarTarget !== null} onOpenChange={(v) => !v && setBayarTarget(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Konfirmasi Pembayaran Gaji</DialogTitle>
          </DialogHeader>
          <p className="text-sm text-muted-foreground">
            Bayar gaji <span className="font-medium text-foreground">{bayarTarget?.nama}</span>{" "}
            sebesar{" "}
            <span className="font-medium text-foreground">
              {bayarTarget ? rupiah(bayarTarget.total) : ""}
            </span>{" "}
            untuk periode {rekap?.periode.label}? Gaji yang sudah ditandai dibayar tidak bisa
            dibatalkan dari sini.
          </p>
          <DialogFooter>
            <Button variant="outline" onClick={() => setBayarTarget(null)}>
              Batal
            </Button>
            <Button onClick={konfirmasiBayar} disabled={bayarGaji.isPending}>
              {bayarGaji.isPending && <Loader2 className="size-4 animate-spin" />}
              Ya, Bayar
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Konfirmasi bayar semua */}
      <Dialog open={konfirmasiBayarSemua} onOpenChange={setKonfirmasiBayarSemua}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Konfirmasi Bayar Semua Gaji</DialogTitle>
          </DialogHeader>
          <p className="text-sm text-muted-foreground">
            Bayar seluruh gaji yang belum dibayar untuk periode{" "}
            <span className="font-medium text-foreground">{rekap?.periode.label}</span>, total{" "}
            <span className="font-medium text-foreground">
              {rekap ? rupiah(rekap.ringkasan.belumDibayar) : ""}
            </span>
            ? Tindakan ini tidak bisa dibatalkan.
          </p>
          <DialogFooter>
            <Button variant="outline" onClick={() => setKonfirmasiBayarSemua(false)}>
              Batal
            </Button>
            <Button onClick={jalankanBayarSemua} disabled={bayarSemuaGaji.isPending}>
              {bayarSemuaGaji.isPending && <Loader2 className="size-4 animate-spin" />}
              Ya, Bayar Semua
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Slip gaji */}
      <PrintDialog
        open={slipKaryawanId !== null}
        onOpenChange={(v) => !v && setSlipKaryawanId(null)}
        title="Slip Gaji Karyawan"
        thermal={
          slip && (
            <>
              <JudulThermal judul="PT Nira Sari Murni" subjudul="Slip Gaji Mingguan" />
              <GarisThermal />
              <BarisThermal label="Nama" value={slip.baris.nama} />
              <BarisThermal label="Periode" value={slip.periode.label} />
              <BarisThermal label="Hari kerja" value={`${slip.baris.hariKerja} hari`} />
              <GarisThermal />
              <BarisThermal
                label={`Kristal ${angka(slip.baris.kgKristal, 1)} kg`}
                value={rupiah(slip.baris.upahKristal)}
              />
              <BarisThermal
                label={`Brondol ${angka(slip.baris.kgBrondol, 1)} kg`}
                value={rupiah(slip.baris.upahBrondol)}
              />
              <BarisThermal label="Uang makan" value={rupiah(slip.baris.uangMakan)} />
              {slip.baris.totalSebelumBulat !== slip.baris.total && (
                <BarisThermal label="Subtotal" value={rupiah(slip.baris.totalSebelumBulat)} />
              )}
              <GarisThermal />
              <BarisThermal label="TOTAL" value={rupiah(slip.baris.total)} tebal />
              <BarisThermal label="Status" value={slip.baris.dibayar ? "DIBAYAR" : "BELUM"} />
              <GarisThermal />
              <p className="mt-1 text-center text-[10px]">Terima kasih</p>
            </>
          )
        }
      >
        {slipLoading ? (
          <div className="flex items-center justify-center gap-2 py-8 text-sm text-muted-foreground">
            <Loader2 className="size-4 animate-spin" /> Memuat slip…
          </div>
        ) : (
          slip && (
            <div>
              <p className="mb-3 text-center text-sm font-semibold">SLIP GAJI MINGGUAN</p>
              <PrintRow label="Nama karyawan" value={slip.baris.nama} />
              <PrintRow label="Periode" value={slip.periode.label} />
              <PrintRow label="Hari kerja" value={`${slip.baris.hariKerja} hari`} />
              <div className="mt-2 border-t pt-2">
                <PrintRow
                  label={`Kg kristal × ${rupiah(slip.tarif.kristal)}`}
                  value={`${angka(slip.baris.kgKristal, 1)} kg`}
                />
                <PrintRow label="Upah kristal" value={rupiah(slip.baris.upahKristal)} />
                <PrintRow
                  label={`Kg brondol × ${rupiah(slip.tarif.brondol)}`}
                  value={`${angka(slip.baris.kgBrondol, 1)} kg`}
                />
                <PrintRow label="Upah brondol" value={rupiah(slip.baris.upahBrondol)} />
                <PrintRow
                  label={`Uang makan (${slip.baris.hariKerja} × ${rupiah(slip.tarif.uangMakan)})`}
                  value={rupiah(slip.baris.uangMakan)}
                />
              </div>
              <div className="mt-2 border-t pt-2">
                {slip.baris.totalSebelumBulat !== slip.baris.total && (
                  <PrintRow
                    label="Subtotal (sebelum pembulatan)"
                    value={rupiah(slip.baris.totalSebelumBulat)}
                  />
                )}
                <PrintRow label="Total gaji" value={rupiah(slip.baris.total)} strong />
                <PrintRow
                  label="Status"
                  value={slip.baris.dibayar ? "SUDAH DIBAYAR" : "BELUM DIBAYAR"}
                />
              </div>
            </div>
          )
        )}
      </PrintDialog>
    </>
  );
}
