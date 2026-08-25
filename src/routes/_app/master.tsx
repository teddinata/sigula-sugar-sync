import { useEffect, useState } from "react";
import { createFileRoute } from "@tanstack/react-router";
import { Loader2, Plus, Trash2, Wallet } from "lucide-react";
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
import { Switch } from "@/components/ui/switch";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  DataTable,
  EmptyState,
  PageHeader,
  SearchInput,
  type Column,
} from "@/components/sigula/ui-bits";
import { angka, rupiah, tanggalID } from "@/lib/format";
import { GRADES, type Grade } from "@/lib/sigula-types";
import { todayISO } from "@/lib/sigula-seed";
import { ApiError } from "@/lib/api-client";
import type { Eksportir, Karyawan, RiwayatHarga, RiwayatTarif, TarifKey } from "@/lib/api/master";
import type { Pengepul } from "@/lib/api/pengepul";
import {
  useHapusPengepul,
  usePengepulList,
  useTambahPengepul,
  useUbahPengepul,
} from "@/hooks/use-pengepul";
import {
  useEksportirList,
  useHapusEksportir,
  useHapusKaryawan,
  useHargaBeli,
  useKaryawanList,
  useTambahEksportir,
  useTambahKaryawan,
  useTarif,
  useUbahEksportir,
  useUbahHarga,
  useUbahKaryawan,
  useUbahTarif,
} from "@/hooks/use-master-data";

export const Route = createFileRoute("/_app/master")({
  head: () => ({
    meta: [
      { title: "Master Harga & Tarif — SIGULA" },
      {
        name: "description",
        content:
          "Atur harga beli bahan per grade (NS 1, NS 2, Kecap), tarif gaji per kg gula kristal dan brondol, uang makan harian, serta data karyawan dan eksportir.",
      },
      { property: "og:title", content: "Master Harga & Tarif — SIGULA" },
      {
        property: "og:description",
        content: "Pusat pengaturan harga beli, tarif produksi, dan data mitra.",
      },
    ],
  }),
  component: MasterPage,
});

function apiErrorMessage(err: unknown, fallback: string): string {
  return err instanceof ApiError ? (err.firstFieldError ?? err.message) : fallback;
}

function LoadingRow() {
  return (
    <div className="flex items-center justify-center gap-2 px-4 py-10 text-sm text-muted-foreground">
      <Loader2 className="size-4 animate-spin" /> Memuat data…
    </div>
  );
}

function MasterPage() {
  const today = todayISO();

  // ---- Harga beli per grade -------------------------------------------------
  const { data: hargaData, isLoading: hargaLoading, isError: hargaError } = useHargaBeli();
  const ubahHarga = useUbahHarga();

  const [hargaDialog, setHargaDialog] = useState<Grade | null>(null);
  const [hargaBaru, setHargaBaru] = useState("");
  const [tanggalBerlaku, setTanggalBerlaku] = useState(today);
  const [errHarga, setErrHarga] = useState<string | null>(null);

  const bukaHarga = (g: Grade) => {
    setHargaDialog(g);
    setHargaBaru(String(hargaData?.hargaBeli[g] ?? ""));
    setTanggalBerlaku(today);
    setErrHarga(null);
  };

  const simpanHarga = async () => {
    const n = Number(hargaBaru);
    if (!hargaBaru.trim() || Number.isNaN(n)) return setErrHarga("Harga wajib diisi berupa angka.");
    if (n <= 0) return setErrHarga("Harga harus lebih besar dari 0.");
    if (!tanggalBerlaku) return setErrHarga("Tanggal berlaku wajib diisi.");
    try {
      await ubahHarga.mutateAsync({ grade: hargaDialog!, harga: n, berlakuDari: tanggalBerlaku });
      toast.success(`Harga ${hargaDialog} diperbarui`, { description: `${rupiah(n)} / kg` });
      setHargaDialog(null);
    } catch (err) {
      setErrHarga(apiErrorMessage(err, "Gagal menyimpan harga."));
    }
  };

  const riwayatHargaCols = (g: Grade): Column<RiwayatHarga>[] => [
    {
      key: "tgl",
      header: "Tanggal",
      sortValue: (r) => r.tanggal,
      cell: (r) => tanggalID(r.tanggal),
    },
    {
      key: "lama",
      header: "Harga Lama",
      align: "right",
      cell: (r) =>
        r.hargaLama === null ? (
          <span className="text-muted-foreground">—</span>
        ) : (
          rupiah(r.hargaLama)
        ),
    },
    {
      key: "baru",
      header: "Harga Baru",
      align: "right",
      cell: (r) => <span className="font-medium text-primary">{rupiah(r.hargaBaru)}</span>,
    },
  ];

  // ---- Tarif gaji & uang makan -----------------------------------------------
  const { data: tarifData, isLoading: tarifLoading, isError: tarifError } = useTarif();
  const ubahTarif = useUbahTarif();

  const [tarifDialog, setTarifDialog] = useState<TarifKey | null>(null);
  const [tarifValue, setTarifValue] = useState("");
  const [errTarif, setErrTarif] = useState<string | null>(null);

  const bukaTarif = (key: TarifKey) => {
    setTarifDialog(key);
    setTarifValue(String(tarifData?.tarif[key] ?? ""));
    setErrTarif(null);
  };

  const simpanTarif = async () => {
    const n = Number(tarifValue);
    if (!tarifValue.trim() || Number.isNaN(n) || n <= 0) {
      setErrTarif("Nilai tarif harus lebih besar dari 0.");
      return;
    }
    try {
      await ubahTarif.mutateAsync({ jenis: tarifDialog!, nilai: n });
      toast.success("Tarif berhasil diperbarui", { description: rupiah(n) });
      setTarifDialog(null);
    } catch (err) {
      setErrTarif(apiErrorMessage(err, "Gagal menyimpan tarif."));
    }
  };

  const riwayatTarifCols: Column<RiwayatTarif>[] = [
    {
      key: "tgl",
      header: "Tanggal",
      sortValue: (r) => r.tanggal,
      cell: (r) => tanggalID(r.tanggal),
    },
    {
      key: "lama",
      header: "Nilai Lama",
      align: "right",
      cell: (r) =>
        r.nilaiLama === null ? (
          <span className="text-muted-foreground">—</span>
        ) : (
          rupiah(r.nilaiLama)
        ),
    },
    {
      key: "baru",
      header: "Nilai Baru",
      align: "right",
      cell: (r) => <span className="font-medium text-primary">{rupiah(r.nilaiBaru)}</span>,
    },
  ];

  const tarifCards: { key: TarifKey; label: string; desc: string }[] = [
    {
      key: "kristal",
      label: "Tarif Gaji per Kg Gula Kristal",
      desc: "Dikalikan total kg kristal karyawan",
    },
    {
      key: "brondol",
      label: "Tarif Gaji per Kg Gula Brondol",
      desc: "Dikalikan total kg brondol karyawan",
    },
    {
      key: "uangMakan",
      label: "Uang Makan Harian",
      desc: "Dibayarkan per hari karyawan tercatat kerja",
    },
  ];

  // ---- Pengepul ---------------------------------------------------------------
  const [qPengepul, setQPengepul] = useState("");
  const [qPengepulDebounced, setQPengepulDebounced] = useState("");
  const [sertakanNonaktifPengepul, setSertakanNonaktifPengepul] = useState(false);

  useEffect(() => {
    const t = setTimeout(() => setQPengepulDebounced(qPengepul.trim()), 300);
    return () => clearTimeout(t);
  }, [qPengepul]);

  const { data: pengepulList = [], isLoading: pengepulLoading } = usePengepulList({
    q: qPengepulDebounced || undefined,
    sertakanNonaktif: sertakanNonaktifPengepul,
  });
  const tambahPengepul = useTambahPengepul();
  const ubahPengepul = useUbahPengepul();
  const hapusPengepul = useHapusPengepul();

  const [pForm, setPForm] = useState({ nama: "", kontak: "" });

  const submitPengepul = async () => {
    if (!pForm.nama.trim()) {
      toast.error("Nama pengepul wajib diisi");
      return;
    }
    try {
      await tambahPengepul.mutateAsync({
        nama: pForm.nama.trim(),
        kontak: pForm.kontak.trim() || undefined,
      });
      toast.success("Pengepul ditambahkan", { description: pForm.nama });
      setPForm({ nama: "", kontak: "" });
    } catch (err) {
      toast.error(apiErrorMessage(err, "Gagal menambah pengepul."));
    }
  };

  const toggleAktifPengepul = async (p: Pengepul) => {
    try {
      await ubahPengepul.mutateAsync({ id: p.id, payload: { aktif: !p.aktif } });
      toast.success(p.aktif ? "Pengepul dinonaktifkan" : "Pengepul diaktifkan kembali", {
        description: p.nama,
      });
    } catch (err) {
      toast.error(apiErrorMessage(err, "Gagal mengubah status pengepul."));
    }
  };

  const hapusPengepulHandler = async (p: Pengepul) => {
    try {
      const res = await hapusPengepul.mutateAsync(p.id);
      toast.success(res.message, { description: p.nama });
    } catch (err) {
      toast.error(apiErrorMessage(err, "Gagal menghapus pengepul."));
    }
  };

  const pengepulCols: Column<Pengepul>[] = [
    { key: "nama", header: "Nama Pengepul", sortValue: (r) => r.nama, cell: (r) => r.nama },
    { key: "kontak", header: "Kontak", cell: (r) => r.kontak || "-" },
    {
      key: "trx",
      header: "Transaksi",
      align: "right",
      cell: (r) => angka(r.totalTransaksi ?? 0),
    },
    {
      key: "status",
      header: "Status",
      cell: (r) =>
        r.aktif ? (
          <Badge className="bg-success/15 text-success hover:bg-success/15">Aktif</Badge>
        ) : (
          <Badge variant="secondary">Nonaktif</Badge>
        ),
    },
    {
      key: "aksi",
      header: "Aksi",
      align: "right",
      cell: (r) => (
        <div className="flex justify-end gap-1">
          <Button variant="ghost" size="sm" onClick={() => toggleAktifPengepul(r)}>
            {r.aktif ? "Nonaktifkan" : "Aktifkan"}
          </Button>
          <Button
            variant="ghost"
            size="icon"
            aria-label="Hapus pengepul"
            onClick={() => hapusPengepulHandler(r)}
          >
            <Trash2 className="size-4 text-destructive" />
          </Button>
        </div>
      ),
    },
  ];

  // ---- Karyawan ---------------------------------------------------------------
  const [qKaryawan, setQKaryawan] = useState("");
  const [qKaryawanDebounced, setQKaryawanDebounced] = useState("");
  const [sertakanNonaktifKaryawan, setSertakanNonaktifKaryawan] = useState(false);

  useEffect(() => {
    const t = setTimeout(() => setQKaryawanDebounced(qKaryawan.trim()), 300);
    return () => clearTimeout(t);
  }, [qKaryawan]);

  const { data: karyawanList = [], isLoading: karyawanLoading } = useKaryawanList({
    q: qKaryawanDebounced || undefined,
    sertakanNonaktif: sertakanNonaktifKaryawan,
  });
  const tambahKaryawan = useTambahKaryawan();
  const ubahKaryawan = useUbahKaryawan();
  const hapusKaryawan = useHapusKaryawan();

  const [kForm, setKForm] = useState({ nama: "", kontak: "" });

  const submitKaryawan = async () => {
    if (!kForm.nama.trim()) {
      toast.error("Nama karyawan wajib diisi");
      return;
    }
    try {
      // Kontak opsional — banyak karyawan client tidak punya nomor terdaftar.
      await tambahKaryawan.mutateAsync({
        nama: kForm.nama.trim(),
        kontak: kForm.kontak.trim() || undefined,
      });
      toast.success("Karyawan ditambahkan", { description: kForm.nama });
      setKForm({ nama: "", kontak: "" });
    } catch (err) {
      toast.error(apiErrorMessage(err, "Gagal menambah karyawan."));
    }
  };

  const toggleAktifKaryawan = async (k: Karyawan) => {
    try {
      await ubahKaryawan.mutateAsync({ id: k.id, payload: { aktif: !k.aktif } });
      toast.success(k.aktif ? "Karyawan dinonaktifkan" : "Karyawan diaktifkan kembali", {
        description: k.nama,
      });
    } catch (err) {
      toast.error(apiErrorMessage(err, "Gagal mengubah status karyawan."));
    }
  };

  const hapusKaryawanHandler = async (k: Karyawan) => {
    try {
      const res = await hapusKaryawan.mutateAsync(k.id);
      toast.success(res.message, { description: k.nama });
    } catch (err) {
      toast.error(apiErrorMessage(err, "Gagal menghapus karyawan."));
    }
  };

  const karyawanCols: Column<Karyawan>[] = [
    { key: "nama", header: "Nama Karyawan", sortValue: (r) => r.nama, cell: (r) => r.nama },
    { key: "kontak", header: "Kontak", cell: (r) => r.kontak || "-" },
    {
      key: "status",
      header: "Status",
      cell: (r) => (
        <Badge variant={r.aktif ? "secondary" : "outline"}>{r.aktif ? "Aktif" : "Nonaktif"}</Badge>
      ),
    },
    {
      key: "aksi",
      header: "Aksi",
      align: "right",
      cell: (r) => (
        <div className="flex justify-end gap-1">
          <Button variant="ghost" size="sm" onClick={() => toggleAktifKaryawan(r)}>
            {r.aktif ? "Nonaktifkan" : "Aktifkan"}
          </Button>
          <Button
            variant="ghost"
            size="icon"
            aria-label="Hapus"
            onClick={() => hapusKaryawanHandler(r)}
          >
            <Trash2 className="size-4 text-destructive" />
          </Button>
        </div>
      ),
    },
  ];

  // ---- Eksportir ----------------------------------------------------------------
  const [sertakanNonaktifEksportir, setSertakanNonaktifEksportir] = useState(false);
  const { data: eksportirList = [], isLoading: eksportirLoading } = useEksportirList({
    sertakanNonaktif: sertakanNonaktifEksportir,
  });
  const tambahEksportir = useTambahEksportir();
  const ubahEksportir = useUbahEksportir();
  const hapusEksportir = useHapusEksportir();

  const [eForm, setEForm] = useState({ nama: "", kontak: "", alamat: "" });

  const submitEksportir = async () => {
    if (!eForm.nama.trim() || !eForm.kontak.trim()) {
      toast.error("Nama perusahaan dan kontak wajib diisi");
      return;
    }
    try {
      await tambahEksportir.mutateAsync({
        nama: eForm.nama.trim(),
        kontak: eForm.kontak.trim(),
        alamat: eForm.alamat.trim() || undefined,
      });
      toast.success("Eksportir ditambahkan", { description: eForm.nama });
      setEForm({ nama: "", kontak: "", alamat: "" });
    } catch (err) {
      toast.error(apiErrorMessage(err, "Gagal menambah eksportir."));
    }
  };

  const toggleAktifEksportir = async (e: Eksportir) => {
    try {
      await ubahEksportir.mutateAsync({ id: e.id, payload: { aktif: !e.aktif } });
      toast.success(e.aktif ? "Eksportir dinonaktifkan" : "Eksportir diaktifkan kembali", {
        description: e.nama,
      });
    } catch (err) {
      toast.error(apiErrorMessage(err, "Gagal mengubah status eksportir."));
    }
  };

  const hapusEksportirHandler = async (e: Eksportir) => {
    try {
      await hapusEksportir.mutateAsync(e.id);
      toast.success("Eksportir dihapus", { description: e.nama });
    } catch (err) {
      toast.error(apiErrorMessage(err, "Gagal menghapus eksportir."));
    }
  };

  const eksportirCols: Column<Eksportir>[] = [
    { key: "nama", header: "Nama Perusahaan", sortValue: (r) => r.nama, cell: (r) => r.nama },
    { key: "kontak", header: "Kontak", cell: (r) => r.kontak || "-" },
    {
      key: "alamat",
      header: "Alamat",
      cell: (r) => r.alamat || "-",
      className: "max-w-[220px] truncate",
    },
    {
      key: "status",
      header: "Status",
      cell: (r) => (
        <Badge variant={r.aktif ? "secondary" : "outline"}>{r.aktif ? "Aktif" : "Nonaktif"}</Badge>
      ),
    },
    {
      key: "aksi",
      header: "Aksi",
      align: "right",
      cell: (r) => (
        <div className="flex justify-end gap-1">
          <Button variant="ghost" size="sm" onClick={() => toggleAktifEksportir(r)}>
            {r.aktif ? "Nonaktifkan" : "Aktifkan"}
          </Button>
          <Button
            variant="ghost"
            size="icon"
            aria-label="Hapus"
            onClick={() => hapusEksportirHandler(r)}
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
        title="Master Harga & Tarif"
        subtitle="Acuan harga beli, tarif produksi, dan data mitra"
      />

      <Tabs defaultValue="harga">
        <TabsList className="mb-4 flex-wrap">
          <TabsTrigger value="harga">Harga Beli per Grade</TabsTrigger>
          <TabsTrigger value="tarif">Tarif Produksi</TabsTrigger>
          <TabsTrigger value="data">Karyawan & Eksportir</TabsTrigger>
          <TabsTrigger value="pengepul">Pengepul</TabsTrigger>
        </TabsList>

        <TabsContent value="harga" className="space-y-4">
          {hargaError && (
            <p className="text-sm text-destructive">
              Gagal memuat data harga. Coba muat ulang halaman.
            </p>
          )}
          <div className="grid gap-4 md:grid-cols-3">
            {GRADES.map((g) => (
              <Card key={g} className="shadow-card">
                <CardHeader className="pb-2">
                  <CardTitle className="text-base">Grade {g}</CardTitle>
                </CardHeader>
                <CardContent>
                  <p className="text-3xl font-semibold tracking-tight">
                    {hargaLoading ? "…" : rupiah(hargaData?.hargaBeli[g] ?? 0)}
                  </p>
                  <p className="text-xs text-muted-foreground">per kilogram</p>
                  <Button
                    className="mt-4 w-full"
                    variant="outline"
                    disabled={hargaLoading}
                    onClick={() => bukaHarga(g)}
                  >
                    Ubah Harga
                  </Button>
                </CardContent>
              </Card>
            ))}
          </div>

          <div className="grid gap-4 md:grid-cols-3">
            {GRADES.map((g) => {
              const rows = (hargaData?.riwayat ?? []).filter((r) => r.grade === g);
              return (
                <Card key={g} className="overflow-hidden shadow-card">
                  <CardHeader className="pb-2">
                    <CardTitle className="text-sm">Riwayat Harga {g}</CardTitle>
                  </CardHeader>
                  <CardContent className="px-0 pb-0">
                    {hargaLoading ? (
                      <LoadingRow />
                    ) : (
                      <DataTable
                        rows={rows}
                        columns={riwayatHargaCols(g)}
                        rowKey={(r) => r.id}
                        empty={
                          <EmptyState
                            title="Belum ada perubahan harga"
                            description={`Harga ${g} belum pernah diubah.`}
                          />
                        }
                      />
                    )}
                  </CardContent>
                </Card>
              );
            })}
          </div>
        </TabsContent>

        <TabsContent value="tarif" className="space-y-4">
          {tarifError && (
            <p className="text-sm text-destructive">
              Gagal memuat data tarif. Coba muat ulang halaman.
            </p>
          )}
          <div className="grid gap-4 md:grid-cols-3">
            {tarifCards.map((t) => (
              <Card key={t.key} className="shadow-card">
                <CardHeader className="pb-2">
                  <CardTitle className="flex items-center gap-2 text-base">
                    <Wallet className="size-4 text-primary" /> {t.label}
                  </CardTitle>
                </CardHeader>
                <CardContent>
                  <p className="text-3xl font-semibold tracking-tight">
                    {tarifLoading ? "…" : rupiah(tarifData?.tarif[t.key] ?? 0)}
                  </p>
                  <p className="text-xs text-muted-foreground">{t.desc}</p>
                  <Button
                    className="mt-4 w-full"
                    variant="outline"
                    disabled={tarifLoading}
                    onClick={() => bukaTarif(t.key)}
                  >
                    Ubah Tarif
                  </Button>
                </CardContent>
              </Card>
            ))}
          </div>

          <div className="grid gap-4 md:grid-cols-3">
            {tarifCards.map((t) => {
              const rows = (tarifData?.riwayat ?? []).filter(
                (r) => r.jenis === (t.key === "uangMakan" ? "uang_makan" : t.key),
              );
              return (
                <Card key={t.key} className="overflow-hidden shadow-card">
                  <CardHeader className="pb-2">
                    <CardTitle className="text-sm">Riwayat {t.label}</CardTitle>
                  </CardHeader>
                  <CardContent className="px-0 pb-0">
                    {tarifLoading ? (
                      <LoadingRow />
                    ) : (
                      <DataTable
                        rows={rows}
                        columns={riwayatTarifCols}
                        rowKey={(r) => r.id}
                        empty={
                          <EmptyState
                            title="Belum ada perubahan tarif"
                            description={`${t.label} belum pernah diubah.`}
                          />
                        }
                      />
                    )}
                  </CardContent>
                </Card>
              );
            })}
          </div>
        </TabsContent>

        <TabsContent value="pengepul" className="space-y-4">
          <Card className="overflow-hidden shadow-card">
            <CardHeader>
              <CardTitle className="text-base">Data Pengepul ({pengepulList.length})</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4 px-0">
              <div className="flex flex-wrap items-center gap-3 px-4">
                <SearchInput
                  value={qPengepul}
                  onChange={setQPengepul}
                  placeholder="Cari nama pengepul…"
                  className="flex-1"
                />
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                  <Switch
                    id="nonaktif-pengepul"
                    checked={sertakanNonaktifPengepul}
                    onCheckedChange={setSertakanNonaktifPengepul}
                  />
                  <Label htmlFor="nonaktif-pengepul" className="cursor-pointer font-normal">
                    Tampilkan nonaktif
                  </Label>
                </div>
              </div>
              <div className="flex flex-wrap gap-2 px-4">
                <Input
                  placeholder="Nama pengepul"
                  value={pForm.nama}
                  onChange={(e) => setPForm({ ...pForm, nama: e.target.value })}
                  className="flex-1"
                />
                <Input
                  placeholder="Kontak (opsional)"
                  value={pForm.kontak}
                  onChange={(e) => setPForm({ ...pForm, kontak: e.target.value })}
                  className="flex-1"
                />
                <Button onClick={submitPengepul} disabled={tambahPengepul.isPending}>
                  {tambahPengepul.isPending ? (
                    <Loader2 className="size-4 animate-spin" />
                  ) : (
                    <Plus className="size-4" />
                  )}
                </Button>
              </div>
              <p className="px-4 text-xs text-muted-foreground">
                Pengepul yang sudah punya transaksi tidak dihapus, hanya dinonaktifkan, supaya
                riwayat pembelian tetap utuh.
              </p>
              {pengepulLoading ? (
                <LoadingRow />
              ) : (
                <DataTable
                  rows={pengepulList}
                  columns={pengepulCols}
                  rowKey={(r) => r.id}
                  initialSort={{ key: "nama", dir: "asc" }}
                  empty={
                    <EmptyState
                      title="Belum ada pengepul"
                      description="Tambahkan pengepul pertama lewat form di atas."
                    />
                  }
                />
              )}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="data" className="grid gap-4 lg:grid-cols-2">
          <Card className="overflow-hidden shadow-card">
            <CardHeader>
              <CardTitle className="text-base">Data Karyawan ({karyawanList.length})</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4 px-0">
              <div className="flex flex-wrap items-center gap-3 px-4">
                <SearchInput
                  value={qKaryawan}
                  onChange={setQKaryawan}
                  placeholder="Cari nama karyawan…"
                  className="flex-1"
                />
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                  <Switch
                    id="nonaktif-karyawan"
                    checked={sertakanNonaktifKaryawan}
                    onCheckedChange={setSertakanNonaktifKaryawan}
                  />
                  <Label htmlFor="nonaktif-karyawan" className="cursor-pointer font-normal">
                    Tampilkan nonaktif
                  </Label>
                </div>
              </div>
              <div className="flex flex-wrap gap-2 px-4">
                <Input
                  placeholder="Nama karyawan"
                  value={kForm.nama}
                  onChange={(e) => setKForm({ ...kForm, nama: e.target.value })}
                  className="flex-1"
                />
                <Input
                  placeholder="Kontak"
                  value={kForm.kontak}
                  onChange={(e) => setKForm({ ...kForm, kontak: e.target.value })}
                  className="flex-1"
                />
                <Button onClick={submitKaryawan} disabled={tambahKaryawan.isPending}>
                  {tambahKaryawan.isPending ? (
                    <Loader2 className="size-4 animate-spin" />
                  ) : (
                    <Plus className="size-4" />
                  )}
                </Button>
              </div>
              <div className="max-h-[420px] overflow-y-auto">
                {karyawanLoading ? (
                  <LoadingRow />
                ) : (
                  <DataTable
                    rows={karyawanList}
                    columns={karyawanCols}
                    rowKey={(r) => r.id}
                    empty={
                      <EmptyState
                        title="Belum ada karyawan"
                        description="Tambahkan karyawan pertama lewat form di atas."
                      />
                    }
                  />
                )}
              </div>
            </CardContent>
          </Card>

          <Card className="overflow-hidden shadow-card">
            <CardHeader>
              <CardTitle className="text-base">Data Eksportir ({eksportirList.length})</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4 px-0">
              <div className="flex flex-wrap items-center gap-3 px-4">
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                  <Switch
                    id="nonaktif-eksportir"
                    checked={sertakanNonaktifEksportir}
                    onCheckedChange={setSertakanNonaktifEksportir}
                  />
                  <Label htmlFor="nonaktif-eksportir" className="cursor-pointer font-normal">
                    Tampilkan nonaktif
                  </Label>
                </div>
              </div>
              <div className="flex flex-wrap gap-2 px-4">
                <Input
                  placeholder="Nama perusahaan"
                  value={eForm.nama}
                  onChange={(e) => setEForm({ ...eForm, nama: e.target.value })}
                  className="flex-1"
                />
                <Input
                  placeholder="Kontak"
                  value={eForm.kontak}
                  onChange={(e) => setEForm({ ...eForm, kontak: e.target.value })}
                  className="flex-1"
                />
                <Input
                  placeholder="Alamat (opsional)"
                  value={eForm.alamat}
                  onChange={(e) => setEForm({ ...eForm, alamat: e.target.value })}
                  className="flex-1"
                />
                <Button onClick={submitEksportir} disabled={tambahEksportir.isPending}>
                  {tambahEksportir.isPending ? (
                    <Loader2 className="size-4 animate-spin" />
                  ) : (
                    <Plus className="size-4" />
                  )}
                </Button>
              </div>
              {eksportirLoading ? (
                <LoadingRow />
              ) : (
                <DataTable
                  rows={eksportirList}
                  columns={eksportirCols}
                  rowKey={(r) => r.id}
                  empty={
                    <EmptyState
                      title="Belum ada eksportir"
                      description="Tambahkan eksportir pertama lewat form di atas."
                    />
                  }
                />
              )}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      <Dialog open={hargaDialog !== null} onOpenChange={(v) => !v && setHargaDialog(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Ubah Harga Beli — {hargaDialog}</DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label>Harga Baru per Kg</Label>
              <Input
                type="number"
                min={0}
                value={hargaBaru}
                onChange={(e) => setHargaBaru(e.target.value)}
              />
            </div>
            <div className="space-y-2">
              <Label>Tanggal Berlaku</Label>
              <Input
                type="date"
                value={tanggalBerlaku}
                onChange={(e) => setTanggalBerlaku(e.target.value)}
              />
            </div>
            {errHarga && <p className="text-sm text-destructive">{errHarga}</p>}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setHargaDialog(null)}>
              Batal
            </Button>
            <Button onClick={simpanHarga} disabled={ubahHarga.isPending}>
              {ubahHarga.isPending && <Loader2 className="size-4 animate-spin" />}
              Simpan Harga
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={tarifDialog !== null} onOpenChange={(v) => !v && setTarifDialog(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Ubah Tarif</DialogTitle>
          </DialogHeader>
          <div className="space-y-2">
            <Label>Nilai Baru (Rp)</Label>
            <Input
              type="number"
              min={0}
              value={tarifValue}
              onChange={(e) => setTarifValue(e.target.value)}
            />
            {errTarif && <p className="text-sm text-destructive">{errTarif}</p>}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setTarifDialog(null)}>
              Batal
            </Button>
            <Button onClick={simpanTarif} disabled={ubahTarif.isPending}>
              {ubahTarif.isPending && <Loader2 className="size-4 animate-spin" />}
              Simpan
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
