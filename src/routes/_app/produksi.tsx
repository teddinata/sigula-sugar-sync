import { useEffect, useState } from "react";
import { createFileRoute } from "@tanstack/react-router";
import {
  CartesianGrid,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { CheckCircle2, Eye, Flame, Loader2, Plus, Trash2 } from "lucide-react";
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
import { Textarea } from "@/components/ui/textarea";
import {
  DataTable,
  EmptyState,
  PageHeader,
  SearchInput,
  StatCard,
  type Column,
} from "@/components/sigula/ui-bits";
import { angka, kg, tanggalPendek } from "@/lib/format";
import { GRADES, type Grade } from "@/lib/sigula-types";
import { todayISO } from "@/lib/sigula-seed";
import { ApiError } from "@/lib/api-client";
import { getSesi, type SesiTungku, type StatusSesi } from "@/lib/api/produksi";
import {
  useBatalkanSesi,
  useMulaiSesi,
  useSelesaikanSesi,
  useSesiList,
  useTrenRendemen,
} from "@/hooks/use-produksi";
import { useKaryawanList, useTambahKaryawan } from "@/hooks/use-master-data";
import { useStokPosisi } from "@/hooks/use-stok";

export const Route = createFileRoute("/_app/produksi")({
  head: () => ({
    meta: [
      { title: "Produksi Sesi Tungku — SIGULA" },
      {
        name: "description",
        content:
          "Kelola sesi tungku dengan dua karyawan per tungku, catat hasil gula kristal & brondol, hitung rendemen, dan bagi hasil otomatis untuk penggajian.",
      },
      { property: "og:title", content: "Produksi Sesi Tungku — SIGULA" },
      {
        property: "og:description",
        content: "Sesi tungku paralel, rendemen otomatis, dan pembagian hasil dua karyawan.",
      },
    ],
  }),
  component: ProduksiPage,
});

const PER_PAGE = 50;

/** Radix Select melarang value string kosong, jadi "tanpa karyawan 2" perlu sentinel. */
const KARYAWAN_KOSONG = "__sendirian__";

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

/** Replikasi persis pembagian backend: porsi kedua = sisa, supaya jumlah 2 porsi selalu pas. */
function bagiDua(total: number): [number, number] {
  const pertama = Math.round((total / 2) * 100) / 100;
  const kedua = Math.round((total - pertama) * 100) / 100;
  return [pertama, kedua];
}

function namaKaryawanSesi(sesi: SesiTungku, index: number): string {
  return sesi.karyawan?.[index]?.nama ?? "-";
}

/** "Pardi & Asep", atau hanya "Pardi" kalau tungkunya dikerjakan sendirian. */
function daftarNamaKaryawan(sesi: SesiTungku): string {
  const nama = (sesi.karyawan ?? []).map((k) => k.nama).filter(Boolean);
  return nama.length > 0 ? nama.join(" & ") : "-";
}

/** Ringkasan bahan: "60 kg NS 1 + 40,5 kg NS 2". */
function ringkasBahan(sesi: SesiTungku): string {
  if (!sesi.bahan || sesi.bahan.length === 0) return sesi.grade;
  return sesi.bahan.map((b) => `${angka(b.kg)} kg ${b.grade}`).join(" + ");
}

function StatusBadge({ status }: { status: StatusSesi }) {
  return status === "Selesai" ? (
    <Badge className="bg-success/15 text-success hover:bg-success/15">Selesai</Badge>
  ) : (
    <Badge className="bg-warning/25 text-warning-foreground hover:bg-warning/25">
      Sedang Diproses
    </Badge>
  );
}

/** Satu baris bahan pada form; satu tungku boleh memakai beberapa grade. */
interface BarisBahanForm {
  grade: Grade;
  kg: string;
}

interface MulaiFormState {
  tanggal: string;
  kodeTungku: string;
  bahan: BarisBahanForm[];
  k1: string;
  /** Boleh kosong: ada tungku yang dikerjakan satu orang saja. */
  k2: string;
}

const emptyMulaiForm: MulaiFormState = {
  tanggal: todayISO(),
  kodeTungku: "",
  bahan: [{ grade: "NS 1", kg: "" }],
  k1: "",
  k2: "",
};

/** Membagi hasil sesuai jumlah karyawan: 1 orang dapat utuh, 2 orang dibagi rata. */
function bagiPorsi(total: number, jumlahOrang: number): number[] {
  if (jumlahOrang <= 1) return [Math.round(total * 100) / 100];
  const [a, b] = bagiDua(total);
  return [a, b];
}

function ProduksiPage() {
  // ---- Data referensi (karyawan, stok) --------------------------------------------
  const { data: karyawanList = [] } = useKaryawanList();
  const { data: posisi } = useStokPosisi();

  // ---- Filter & pagination ---------------------------------------------------
  const [q, setQ] = useState("");
  const [qDebounced, setQDebounced] = useState("");
  const [fTanggal, setFTanggal] = useState("");
  const [fStatus, setFStatus] = useState<"semua" | StatusSesi>("semua");
  const [fGrade, setFGrade] = useState<"semua" | Grade>("semua");
  const [fKaryawanId, setFKaryawanId] = useState("semua");
  const [page, setPage] = useState(1);

  useEffect(() => {
    const t = setTimeout(() => setQDebounced(q.trim()), 300);
    return () => clearTimeout(t);
  }, [q]);

  useEffect(() => {
    setPage(1);
  }, [qDebounced, fTanggal, fStatus, fGrade, fKaryawanId]);

  const {
    data: listResult,
    isLoading,
    isError,
  } = useSesiList({
    tanggal: fTanggal || undefined,
    status: fStatus === "semua" ? undefined : fStatus,
    grade: fGrade === "semua" ? undefined : fGrade,
    karyawanId: fKaryawanId === "semua" ? undefined : fKaryawanId,
    q: qDebounced || undefined,
    page,
    perPage: PER_PAGE,
  });

  const rows = listResult?.data ?? [];
  const meta = listResult?.meta;
  const ringkasan = listResult?.ringkasan;
  const ringkasanHariIni = ringkasan?.tanggal === todayISO();
  const filterAktif = Boolean(
    fTanggal || fStatus !== "semua" || fGrade !== "semua" || fKaryawanId !== "semua" || q,
  );

  const resetFilter = () => {
    setFTanggal("");
    setFStatus("semua");
    setFGrade("semua");
    setFKaryawanId("semua");
    setQ("");
  };

  const totalBahanTersedia = posisi ? posisi.totalBahanMentah : null;

  // ---- Tren rendemen -------------------------------------------------------------
  const { data: tren = [] } = useTrenRendemen(14);
  const trenChart = tren.map((t) => ({
    ...t,
    label: tanggalPendek(t.tanggal).replace(/ \d{4}$/, ""),
  }));

  // ---- Mulai sesi ---------------------------------------------------------------
  const mulaiSesi = useMulaiSesi();
  const [openMulai, setOpenMulai] = useState(false);
  const [form, setForm] = useState<MulaiFormState>(emptyMulaiForm);
  const [err, setErr] = useState<string | null>(null);

  const bukaMulai = () => {
    setForm(emptyMulaiForm);
    setErr(null);
    setOpenMulai(true);
  };

  const simpanMulai = async () => {
    if (!form.tanggal) return setErr("Tanggal wajib diisi.");

    const bahan = form.bahan.map((b) => ({ grade: b.grade, kg: Number(b.kg) }));

    if (bahan.some((b) => !Number.isFinite(b.kg) || b.kg <= 0))
      return setErr("Kg bahan mentah setiap grade harus lebih dari 0.");

    const gradeUnik = new Set(bahan.map((b) => b.grade));
    if (gradeUnik.size !== bahan.length)
      return setErr("Grade yang sama tidak boleh dipilih dua kali dalam satu tungku.");

    if (!form.k1) return setErr("Karyawan 1 wajib dipilih.");
    if (form.k2 && form.k1 === form.k2)
      return setErr("Karyawan 1 dan Karyawan 2 tidak boleh orang yang sama.");

    try {
      const res = await mulaiSesi.mutateAsync({
        tanggal: form.tanggal,
        kodeTungku: form.kodeTungku.trim() || undefined,
        bahan,
        karyawan1Id: form.k1,
        karyawan2Id: form.k2 || undefined,
      });
      toast.success(res.message, { description: daftarNamaKaryawan(res.sesi) });
      setOpenMulai(false);
    } catch (e) {
      setErr(apiErrorMessage(e, "Gagal memulai sesi tungku."));
    }
  };

  const ubahBaris = (index: number, patch: Partial<BarisBahanForm>) =>
    setForm((f) => ({
      ...f,
      bahan: f.bahan.map((b, i) => (i === index ? { ...b, ...patch } : b)),
    }));

  const tambahBaris = () =>
    setForm((f) => {
      const terpakai = new Set(f.bahan.map((b) => b.grade));
      const berikutnya = GRADES.find((g) => !terpakai.has(g));
      return berikutnya ? { ...f, bahan: [...f.bahan, { grade: berikutnya, kg: "" }] } : f;
    });

  const hapusBaris = (index: number) =>
    setForm((f) =>
      f.bahan.length <= 1 ? f : { ...f, bahan: f.bahan.filter((_, i) => i !== index) },
    );

  const totalKgForm = form.bahan.reduce((t, b) => t + (Number(b.kg) || 0), 0);

  // ---- Tambah karyawan cepat dari form produksi -------------------------------
  // Karyawan baru sering muncul saat sesi mau dimulai; tanpa ini operator harus
  // pindah ke halaman Master dulu dan kehilangan isian formnya.
  const tambahKaryawan = useTambahKaryawan();
  const [openKaryawan, setOpenKaryawan] = useState(false);
  const [formKaryawan, setFormKaryawan] = useState({ nama: "", kontak: "" });
  const [errKaryawan, setErrKaryawan] = useState<string | null>(null);

  const simpanKaryawan = async () => {
    const nama = formKaryawan.nama.trim();
    if (!nama) return setErrKaryawan("Nama karyawan wajib diisi.");

    try {
      const baru = await tambahKaryawan.mutateAsync({
        nama,
        kontak: formKaryawan.kontak.trim() || undefined,
      });

      // Langsung isikan ke slot yang masih kosong supaya alurnya tidak terputus.
      setForm((f) => (f.k1 ? (f.k2 ? f : { ...f, k2: baru.id }) : { ...f, k1: baru.id }));
      toast.success("Karyawan ditambahkan", { description: baru.nama });
      setFormKaryawan({ nama: "", kontak: "" });
      setErrKaryawan(null);
      setOpenKaryawan(false);
    } catch (e) {
      setErrKaryawan(apiErrorMessage(e, "Gagal menambah karyawan."));
    }
  };

  // ---- Selesaikan sesi ---------------------------------------------------------
  const selesaikanSesi = useSelesaikanSesi();
  const [selesai, setSelesai] = useState<SesiTungku | null>(null);
  const [hasil, setHasil] = useState({ kristal: "", brondol: "" });
  const [errHasil, setErrHasil] = useState<string | null>(null);

  const kgKristalNum = Number(hasil.kristal) || 0;
  const kgBrondolNum = Number(hasil.brondol) || 0;
  const totalHasil = kgKristalNum + kgBrondolNum;
  const rendemenPreview = selesai?.kgBahan ? (totalHasil / selesai.kgBahan) * 100 : 0;
  const jumlahPekerja = selesai ? Math.max(selesai.karyawanIds.length, 1) : 2;
  const porsiBahan = bagiPorsi(selesai?.kgBahan ?? 0, jumlahPekerja);
  const porsiKristal = bagiPorsi(kgKristalNum, jumlahPekerja);
  const porsiBrondol = bagiPorsi(kgBrondolNum, jumlahPekerja);

  const bukaSelesai = (sesi: SesiTungku) => {
    setSelesai(sesi);
    setHasil({ kristal: "", brondol: "" });
    setErrHasil(null);
  };

  const simpanSelesai = async () => {
    if (!selesai) return;
    if (!hasil.kristal.trim() && !hasil.brondol.trim())
      return setErrHasil("Isi hasil produksi tungku ini (kristal dan/atau brondol).");
    if (kgKristalNum < 0 || kgBrondolNum < 0) return setErrHasil("Nilai tidak boleh negatif.");
    if (totalHasil <= 0) return setErrHasil("Total hasil harus lebih dari 0.");
    // Tidak ada batas atas: di lapangan kadang ada penambahan gula di luar
    // sistem, sehingga rendemen bisa melebihi 100%.
    try {
      const res = await selesaikanSesi.mutateAsync({
        id: selesai.id,
        payload: { kgKristal: kgKristalNum, kgBrondol: kgBrondolNum },
      });
      toast.success(res.message);
      setSelesai(null);
    } catch (e) {
      setErrHasil(apiErrorMessage(e, "Gagal menyelesaikan sesi tungku."));
    }
  };

  // ---- Detail sesi ---------------------------------------------------------------
  const [detail, setDetail] = useState<SesiTungku | null>(null);
  const [detailLoadingId, setDetailLoadingId] = useState<string | null>(null);

  const lihatDetail = async (id: string) => {
    setDetailLoadingId(id);
    try {
      const sesi = await getSesi(id);
      setDetail(sesi);
    } catch (e) {
      toast.error(apiErrorMessage(e, "Gagal memuat detail sesi."));
    } finally {
      setDetailLoadingId(null);
    }
  };

  // ---- Batalkan sesi ---------------------------------------------------------------
  const batalkanSesi = useBatalkanSesi();
  const [batalTarget, setBatalTarget] = useState<SesiTungku | null>(null);
  const [alasanBatal, setAlasanBatal] = useState("");

  const konfirmasiBatal = async () => {
    if (!batalTarget) return;
    try {
      const res = await batalkanSesi.mutateAsync({
        id: batalTarget.id,
        alasan: alasanBatal.trim() || undefined,
      });
      toast.success(res.message, { description: batalTarget.kodeTungku });
      setBatalTarget(null);
      setAlasanBatal("");
    } catch (e) {
      toast.error(apiErrorMessage(e, "Gagal membatalkan sesi tungku."));
    }
  };

  const cols: Column<SesiTungku>[] = [
    { key: "tgl", header: "Tanggal", cell: (r) => tanggalPendek(r.tanggal) },
    {
      key: "kode",
      header: "Kode Tungku",
      cell: (r) => <span className="font-medium">{r.kodeTungku}</span>,
    },
    {
      key: "grade",
      header: "Grade",
      cell: (r) => (
        <div className="flex flex-wrap gap-1">
          {(r.bahan && r.bahan.length > 0 ? r.bahan.map((b) => b.grade) : [r.grade]).map((g) => (
            <Badge key={g} variant="secondary">
              {g}
            </Badge>
          ))}
        </div>
      ),
    },
    { key: "bahan", header: "Kg Bahan", align: "right", cell: (r) => `${angka(r.kgBahan)} kg` },
    {
      key: "kar",
      header: "Karyawan Bertugas",
      cell: (r) => (
        <div className="text-xs">
          <p>{namaKaryawanSesi(r, 0)}</p>
          <p className="text-muted-foreground">
            {r.karyawanIds.length > 1 ? namaKaryawanSesi(r, 1) : "Dikerjakan sendirian"}
          </p>
        </div>
      ),
    },
    {
      key: "kristal",
      header: "Kg Kristal",
      align: "right",
      cell: (r) => (r.kgKristal === null ? "—" : `${angka(r.kgKristal)} kg`),
    },
    {
      key: "brondol",
      header: "Kg Brondol",
      align: "right",
      cell: (r) => (r.kgBrondol === null ? "—" : `${angka(r.kgBrondol)} kg`),
    },
    {
      key: "rend",
      header: "Rendemen",
      align: "right",
      cell: (r) =>
        r.rendemen === null ? "—" : <span className="font-medium">{r.rendemen.toFixed(1)}%</span>,
    },
    { key: "status", header: "Status", cell: (r) => <StatusBadge status={r.status} /> },
    {
      key: "aksi",
      header: "Aksi",
      align: "right",
      cell: (r) => (
        <div className="flex justify-end gap-1">
          {r.statusKode === "sedang_diproses" && (
            <Button size="sm" onClick={() => bukaSelesai(r)}>
              <CheckCircle2 className="mr-1 size-4" /> Selesaikan
            </Button>
          )}
          <Button
            variant="ghost"
            size="icon"
            aria-label="Lihat detail"
            disabled={detailLoadingId === r.id}
            onClick={() => lihatDetail(r.id)}
          >
            {detailLoadingId === r.id ? (
              <Loader2 className="size-4 animate-spin" />
            ) : (
              <Eye className="size-4" />
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

  return (
    <>
      <PageHeader
        title="Produksi (Sesi Tungku)"
        subtitle="Setiap tungku dikerjakan tepat 2 karyawan; kristal & brondol keluar dari proses masak yang sama"
        action={
          <Button onClick={bukaMulai}>
            <Plus className="mr-2 size-4" /> Mulai Sesi Tungku Baru
          </Button>
        }
      />

      <div className="grid gap-4 sm:grid-cols-3">
        <StatCard
          label={
            ringkasanHariIni
              ? "Tungku Aktif Hari Ini"
              : `Tungku Aktif — ${tanggalPendek(ringkasan?.tanggal ?? todayISO())}`
          }
          value={ringkasan ? `${ringkasan.tungkuAktif} tungku` : "…"}
          icon={<Flame className="size-5" />}
          tone="warning"
          hint={ringkasan ? `${ringkasan.jumlahSesi} sesi tercatat` : undefined}
        />
        <StatCard
          label={ringkasanHariIni ? "Produksi Hari Ini" : "Produksi (tanggal terpilih)"}
          value={ringkasan ? kg(ringkasan.totalProduksi) : "…"}
          tone="success"
          hint={
            ringkasan?.rendemen !== null && ringkasan?.rendemen !== undefined
              ? `Rendemen ${angka(ringkasan.rendemen, 1)}%`
              : undefined
          }
        />
        <StatCard
          label="Stok Bahan Tersedia"
          value={totalBahanTersedia !== null ? kg(totalBahanTersedia) : "…"}
          hint={
            posisi
              ? `NS 1 ${angka(posisi.saldo["NS 1"])} · NS 2 ${angka(posisi.saldo["NS 2"])} · Kecap ${angka(posisi.saldo["Kecap"])}`
              : undefined
          }
        />
      </div>

      <Card className="mt-6 shadow-card">
        <CardHeader>
          <CardTitle className="text-base">Tren Rendemen 14 Hari Terakhir</CardTitle>
        </CardHeader>
        <CardContent className="h-[220px]">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={trenChart} margin={{ left: 8, right: 8 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
              <XAxis dataKey="label" tickLine={false} axisLine={false} fontSize={11} />
              <YAxis domain={[0, 100]} tickLine={false} axisLine={false} fontSize={11} unit="%" />
              <Tooltip formatter={(v) => `${v}%`} />
              <Line
                type="monotone"
                dataKey="rendemen"
                name="Rendemen"
                stroke="var(--chart-1)"
                strokeWidth={3}
                dot={{ r: 3 }}
              />
            </LineChart>
          </ResponsiveContainer>
        </CardContent>
      </Card>

      <Card className="mt-6 overflow-hidden shadow-card">
        <CardHeader>
          <CardTitle className="text-base">Daftar Sesi Tungku</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4 px-0">
          <div className="flex flex-wrap items-end gap-3 px-4">
            <SearchInput value={q} onChange={setQ} placeholder="Cari kode tungku / karyawan..." />
            <div className="space-y-1">
              <Label className="text-xs">Tanggal</Label>
              <Input
                type="date"
                value={fTanggal}
                onChange={(e) => setFTanggal(e.target.value)}
                className="w-[170px]"
              />
            </div>
            <div className="space-y-1">
              <Label className="text-xs">Status</Label>
              <Select value={fStatus} onValueChange={(v: "semua" | StatusSesi) => setFStatus(v)}>
                <SelectTrigger className="w-[180px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="semua">Semua Status</SelectItem>
                  <SelectItem value="Sedang Diproses">Sedang Diproses</SelectItem>
                  <SelectItem value="Selesai">Selesai</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <Label className="text-xs">Grade</Label>
              <Select value={fGrade} onValueChange={(v: "semua" | Grade) => setFGrade(v)}>
                <SelectTrigger className="w-[140px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="semua">Semua Grade</SelectItem>
                  {GRADES.map((g) => (
                    <SelectItem key={g} value={g}>
                      {g}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <Label className="text-xs">Karyawan</Label>
              <Select value={fKaryawanId} onValueChange={setFKaryawanId}>
                <SelectTrigger className="w-[180px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="semua">Semua Karyawan</SelectItem>
                  {karyawanList.map((k) => (
                    <SelectItem key={k.id} value={k.id}>
                      {k.nama}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <Button variant="ghost" onClick={() => setFTanggal(todayISO())}>
              Lihat hari ini
            </Button>
            {filterAktif && (
              <Button variant="ghost" onClick={resetFilter}>
                Reset filter
              </Button>
            )}
          </div>

          {isError && (
            <p className="px-4 text-sm text-destructive">
              Gagal memuat data sesi tungku. Coba muat ulang halaman.
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
                    title="Belum ada sesi tungku"
                    description="Mulai sesi tungku baru untuk mencatat produksi."
                    action={
                      <Button onClick={bukaMulai}>
                        <Plus className="mr-2 size-4" /> Mulai Sesi Tungku Baru
                      </Button>
                    }
                  />
                }
              />
              {meta && meta.total > 0 && (
                <div className="flex flex-wrap items-center justify-between gap-3 px-4 text-sm text-muted-foreground">
                  <span>
                    Menampilkan {meta.from ?? 0}–{meta.to ?? 0} dari {angka(meta.total)} sesi
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

      {/* Modal mulai sesi */}
      <Dialog open={openMulai} onOpenChange={setOpenMulai}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>Mulai Sesi Tungku Baru</DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label>Tanggal</Label>
                <Input
                  type="date"
                  value={form.tanggal}
                  onChange={(e) => setForm({ ...form, tanggal: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label>Kode Tungku (opsional)</Label>
                <Input
                  value={form.kodeTungku}
                  onChange={(e) => setForm({ ...form, kodeTungku: e.target.value })}
                  placeholder="Kosongkan → otomatis TGK-01, TGK-02, …"
                />
              </div>
            </div>
            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <Label>Bahan Mentah</Label>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={tambahBaris}
                  disabled={form.bahan.length >= GRADES.length}
                >
                  <Plus className="size-4" /> Tambah grade
                </Button>
              </div>

              {/* Satu tungku boleh dimasak dari beberapa grade sekaligus. */}
              {form.bahan.map((baris, i) => (
                <div key={i} className="flex gap-2">
                  <Select
                    value={baris.grade}
                    onValueChange={(v: Grade) => ubahBaris(i, { grade: v })}
                  >
                    <SelectTrigger className="flex-1">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {GRADES.map((g) => (
                        <SelectItem
                          key={g}
                          value={g}
                          disabled={form.bahan.some((b, j) => j !== i && b.grade === g)}
                        >
                          {g}
                          {posisi ? ` — stok ${angka(posisi.saldo[g])} kg` : ""}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <Input
                    type="number"
                    min={0}
                    step="0.01"
                    inputMode="decimal"
                    className="w-32"
                    value={baris.kg}
                    onChange={(e) => ubahBaris(i, { kg: e.target.value })}
                    placeholder="kg"
                    aria-label={`Kg bahan ${baris.grade}`}
                  />
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    onClick={() => hapusBaris(i)}
                    disabled={form.bahan.length <= 1}
                    aria-label={`Hapus baris ${baris.grade}`}
                  >
                    <Trash2 className="size-4" />
                  </Button>
                </div>
              ))}

              {form.bahan.length > 1 && (
                <p className="text-xs text-muted-foreground">
                  Total bahan mentah: <span className="font-medium">{angka(totalKgForm)} kg</span>
                </p>
              )}
            </div>
            <div className="flex items-center justify-between">
              <Label className="text-sm">Karyawan Bertugas</Label>
              <Button type="button" variant="ghost" size="sm" onClick={() => setOpenKaryawan(true)}>
                <Plus className="size-4" /> Tambah Karyawan
              </Button>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label>Karyawan 1 (wajib)</Label>
                <Select value={form.k1} onValueChange={(v) => setForm({ ...form, k1: v })}>
                  <SelectTrigger>
                    <SelectValue placeholder="Pilih karyawan" />
                  </SelectTrigger>
                  <SelectContent>
                    {karyawanList.map((k) => (
                      <SelectItem key={k.id} value={k.id} disabled={k.id === form.k2}>
                        {k.nama}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Karyawan 2 (opsional)</Label>
                <Select
                  value={form.k2 || KARYAWAN_KOSONG}
                  onValueChange={(v) => setForm({ ...form, k2: v === KARYAWAN_KOSONG ? "" : v })}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Pilih karyawan" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={KARYAWAN_KOSONG}>Dikerjakan sendirian</SelectItem>
                    {karyawanList.map((k) => (
                      <SelectItem key={k.id} value={k.id} disabled={k.id === form.k1}>
                        {k.nama}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>
            <p className="rounded-xl bg-cream px-4 py-3 text-xs text-muted-foreground">
              {form.k2
                ? "Hasil produksi tungku ini nanti dibagi rata otomatis ke kedua karyawan untuk perhitungan gaji."
                : "Tanpa rekan kerja, seluruh hasil tungku ini jadi porsi Karyawan 1 untuk perhitungan gaji."}{" "}
              Stok bahan mentah baru dipotong saat sesi ini diselesaikan.
            </p>
            {err && <p className="text-sm text-destructive">{err}</p>}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpenMulai(false)}>
              Batal
            </Button>
            <Button onClick={simpanMulai} disabled={mulaiSesi.isPending}>
              {mulaiSesi.isPending && <Loader2 className="size-4 animate-spin" />}
              Mulai Sesi
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Modal selesaikan sesi */}
      <Dialog open={selesai !== null} onOpenChange={(v) => !v && setSelesai(null)}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>Selesaikan Sesi {selesai?.kodeTungku}</DialogTitle>
          </DialogHeader>
          {selesai && (
            <div className="space-y-4">
              <div className="rounded-xl bg-cream px-4 py-3 text-sm">
                <p>
                  Bahan mentah: <span className="font-medium">{ringkasBahan(selesai)}</span>
                  {selesai.bahan && selesai.bahan.length > 1
                    ? ` = ${angka(selesai.kgBahan)} kg`
                    : ""}
                </p>
                <p className="text-muted-foreground">Karyawan: {daftarNamaKaryawan(selesai)}</p>
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <Label>Kg Kristal Total (tungku ini)</Label>
                  <Input
                    type="number"
                    min={0}
                    step="0.01"
                    inputMode="decimal"
                    value={hasil.kristal}
                    onChange={(e) => setHasil({ ...hasil, kristal: e.target.value })}
                    placeholder="0"
                  />
                </div>
                <div className="space-y-2">
                  <Label>Kg Brondol Total (tungku ini)</Label>
                  <Input
                    type="number"
                    min={0}
                    step="0.01"
                    inputMode="decimal"
                    value={hasil.brondol}
                    onChange={(e) => setHasil({ ...hasil, brondol: e.target.value })}
                    placeholder="0"
                  />
                </div>
              </div>
              <div className="rounded-xl border bg-card px-4 py-3">
                <p className="text-xs uppercase tracking-wide text-muted-foreground">
                  Rendemen otomatis
                </p>
                <p className="text-2xl font-semibold">{rendemenPreview.toFixed(1)}%</p>
                <p className="text-xs text-muted-foreground">
                  ({angka(kgKristalNum)} + {angka(kgBrondolNum)}) ÷ {angka(selesai.kgBahan)} × 100%
                  {rendemenPreview > 100
                    ? " — di atas 100%, wajar bila ada penambahan gula di luar sistem"
                    : ""}
                </p>
              </div>
              <div className="rounded-xl bg-secondary px-4 py-3 text-sm">
                <p className="mb-1 font-medium">
                  {jumlahPekerja > 1 ? "Pembagian otomatis per karyawan" : "Porsi karyawan"}
                </p>
                <div className="grid gap-1 text-muted-foreground">
                  {porsiBahan.map((kgBahanPorsi, i) => (
                    <p key={i}>
                      {namaKaryawanSesi(selesai, i)} — {angka(kgBahanPorsi)} kg bahan,{" "}
                      {angka(porsiKristal[i] ?? 0)} kg kristal, {angka(porsiBrondol[i] ?? 0)} kg
                      brondol
                    </p>
                  ))}
                </div>
              </div>
              {errHasil && <p className="text-sm text-destructive">{errHasil}</p>}
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setSelesai(null)}>
              Batal
            </Button>
            <Button onClick={simpanSelesai} disabled={selesaikanSesi.isPending}>
              {selesaikanSesi.isPending && <Loader2 className="size-4 animate-spin" />}
              Simpan & Selesaikan
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Modal tambah karyawan cepat (dipanggil dari form Mulai Sesi) */}
      <Dialog open={openKaryawan} onOpenChange={setOpenKaryawan}>
        <DialogContent className="max-w-sm">
          <DialogHeader>
            <DialogTitle>Tambah Karyawan</DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label>Nama Karyawan</Label>
              <Input
                autoFocus
                value={formKaryawan.nama}
                onChange={(e) => setFormKaryawan({ ...formKaryawan, nama: e.target.value })}
                placeholder="Nama lengkap"
              />
            </div>
            <div className="space-y-2">
              <Label>Kontak (opsional)</Label>
              <Input
                value={formKaryawan.kontak}
                onChange={(e) => setFormKaryawan({ ...formKaryawan, kontak: e.target.value })}
                placeholder="08xx-xxxx-xxxx"
              />
            </div>
            <p className="text-xs text-muted-foreground">
              Karyawan baru langsung dipilih ke slot yang masih kosong pada form sesi tungku.
            </p>
            {errKaryawan && <p className="text-sm text-destructive">{errKaryawan}</p>}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpenKaryawan(false)}>
              Batal
            </Button>
            <Button onClick={simpanKaryawan} disabled={tambahKaryawan.isPending}>
              {tambahKaryawan.isPending && <Loader2 className="size-4 animate-spin" />}
              Simpan
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Modal detail sesi */}
      <Dialog open={detail !== null} onOpenChange={(v) => !v && setDetail(null)}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>Detail Sesi {detail?.kodeTungku}</DialogTitle>
          </DialogHeader>
          {detail && (
            <div className="space-y-4 text-sm">
              <div className="grid grid-cols-2 gap-3 rounded-xl bg-cream px-4 py-3">
                <div>
                  <p className="text-xs text-muted-foreground">Tanggal</p>
                  <p className="font-medium">{detail.tanggalLabel}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Status</p>
                  <StatusBadge status={detail.status} />
                </div>
                <div className="col-span-2">
                  <p className="text-xs text-muted-foreground">Bahan Mentah</p>
                  <p className="font-medium">{ringkasBahan(detail)}</p>
                  {detail.bahan && detail.bahan.length > 1 && (
                    <p className="text-xs text-muted-foreground">
                      Total {angka(detail.kgBahan)} kg
                    </p>
                  )}
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Kg Kristal</p>
                  <p className="font-medium">
                    {detail.kgKristal === null ? "—" : `${angka(detail.kgKristal)} kg`}
                  </p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Kg Brondol</p>
                  <p className="font-medium">
                    {detail.kgBrondol === null ? "—" : `${angka(detail.kgBrondol)} kg`}
                  </p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Rendemen</p>
                  <p className="font-medium">
                    {detail.rendemen === null ? "—" : `${detail.rendemen.toFixed(1)}%`}
                  </p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Selesai pada</p>
                  <p className="font-medium">
                    {detail.selesaiPada
                      ? new Date(detail.selesaiPada).toLocaleString("id-ID")
                      : "—"}
                  </p>
                </div>
              </div>

              <div>
                <p className="mb-2 font-medium">Porsi Karyawan</p>
                {detail.porsiKaryawan && detail.porsiKaryawan.length > 0 ? (
                  <div className="space-y-2">
                    {detail.porsiKaryawan.map((p, i) => (
                      <div key={p.karyawanId} className="rounded-xl border px-4 py-3">
                        <p className="font-medium">{namaKaryawanSesi(detail, i)}</p>
                        <p className="text-xs text-muted-foreground">
                          {angka(p.kgBahan)} kg bahan · {angka(p.kgKristal)} kg kristal ·{" "}
                          {angka(p.kgBrondol)} kg brondol
                        </p>
                      </div>
                    ))}
                  </div>
                ) : (
                  <p className="text-xs text-muted-foreground">
                    Belum ada porsi — sesi ini belum diselesaikan.
                  </p>
                )}
              </div>

              {detail.catatan && (
                <div>
                  <p className="text-xs text-muted-foreground">Catatan</p>
                  <p>{detail.catatan}</p>
                </div>
              )}
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setDetail(null)}>
              Tutup
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Modal batalkan sesi */}
      <Dialog open={batalTarget !== null} onOpenChange={(v) => !v && setBatalTarget(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Batalkan Sesi Tungku</DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
              Sesi <span className="font-medium text-foreground">{batalTarget?.kodeTungku}</span>{" "}
              akan dibatalkan.
              {batalTarget?.statusKode === "selesai"
                ? " Sesi ini sudah selesai — efek stoknya (bahan kembali, kristal & brondol keluar) akan dibalik dan porsi karyawan dihapus."
                : " Sesi ini masih berjalan, belum ada efek stok yang perlu dibalik."}{" "}
              Tindakan ini tidak bisa dibatalkan.
            </p>
            <div className="space-y-2">
              <Label>Alasan pembatalan (opsional)</Label>
              <Textarea
                value={alasanBatal}
                onChange={(e) => setAlasanBatal(e.target.value)}
                placeholder="Contoh: Salah input hasil masak"
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
              disabled={batalkanSesi.isPending}
            >
              {batalkanSesi.isPending && <Loader2 className="size-4 animate-spin" />}
              Ya, Batalkan
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
