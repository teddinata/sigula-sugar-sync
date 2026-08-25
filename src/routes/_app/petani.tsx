import { useEffect, useState } from "react";
import { createFileRoute } from "@tanstack/react-router";
import { Columns3, Loader2, Pencil, Plus, PowerOff, RotateCcw, Trash2 } from "lucide-react";
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
  DialogTrigger,
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
  type Column,
} from "@/components/sigula/ui-bits";
import { angka, rupiah } from "@/lib/format";
import { ApiError } from "@/lib/api-client";
import {
  DAFTAR_STATUS_PENDERES,
  type Petani,
  type PetaniPayload,
  type StatusPenderesKode,
} from "@/lib/api/petani";
import { Checkbox } from "@/components/ui/checkbox";
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Switch } from "@/components/ui/switch";
import { useHapusPetani, usePetaniList, useTambahPetani, useUbahPetani } from "@/hooks/use-petani";

export const Route = createFileRoute("/_app/petani")({
  head: () => ({
    meta: [
      { title: "Data Petani — SIGULA" },
      {
        name: "description",
        content:
          "Kelola data petani mitra PT Nira Sari Murni: status member, nomor member, kontak, alamat, dan total transaksi pembelian bahan.",
      },
      { property: "og:title", content: "Data Petani — SIGULA" },
      {
        property: "og:description",
        content: "Database petani mitra beserta riwayat nilai transaksi.",
      },
    ],
  }),
  component: PetaniPage,
});

interface FormState {
  nama: string;
  /** Kode lahan sekaligus nomor member: terisi = Member. */
  kodeLahan: string;
  rtRw: string;
  aktif: boolean;
  kontak: string;
  alamat: string;
  /** Satu petani bisa punya lebih dari satu status, mis. PMS + PLMD. */
  statusPenderes: StatusPenderesKode[];
}

const emptyForm: FormState = {
  nama: "",
  kodeLahan: "",
  rtRw: "",
  aktif: true,
  kontak: "",
  alamat: "",
  statusPenderes: [],
};

/**
 * Kolom yang bisa disembunyikan. Alamat, Kontak, dan Status Member dimatikan
 * secara default karena data client tidak mengisinya — Status bisa disimpulkan
 * dari ada tidaknya kode lahan.
 */
const KOLOM_OPSIONAL = {
  status: "Status Member",
  kontak: "Kontak",
  alamat: "Alamat",
  trx: "Total Transaksi",
} as const;

type KolomOpsional = keyof typeof KOLOM_OPSIONAL;

const KOLOM_DEFAULT: Record<KolomOpsional, boolean> = {
  status: false,
  kontak: false,
  alamat: false,
  trx: true,
};

const KUNCI_KOLOM = "sigula.petani.kolom";

function bacaPilihanKolom(): Record<KolomOpsional, boolean> {
  if (typeof window === "undefined") return KOLOM_DEFAULT;

  try {
    const mentah = window.localStorage.getItem(KUNCI_KOLOM);
    return mentah ? { ...KOLOM_DEFAULT, ...JSON.parse(mentah) } : KOLOM_DEFAULT;
  } catch {
    return KOLOM_DEFAULT;
  }
}

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

function PetaniPage() {
  const [q, setQ] = useState("");
  const [qDebounced, setQDebounced] = useState("");

  useEffect(() => {
    const t = setTimeout(() => setQDebounced(q.trim()), 300);
    return () => clearTimeout(t);
  }, [q]);

  // Filter status penderes: kosong = tampilkan semua.
  const [fStatusPenderes, setFStatusPenderes] = useState<StatusPenderesKode[]>([]);
  const [sertakanNonaktif, setSertakanNonaktif] = useState(false);
  const [kolom, setKolom] = useState<Record<KolomOpsional, boolean>>(bacaPilihanKolom);

  // Pilihan kolom disimpan per browser supaya tidak perlu diatur ulang tiap buka.
  useEffect(() => {
    try {
      window.localStorage.setItem(KUNCI_KOLOM, JSON.stringify(kolom));
    } catch {
      // Mode privat / storage penuh — cukup abaikan.
    }
  }, [kolom]);

  const {
    data: rows = [],
    isLoading,
    isError,
  } = usePetaniList({
    q: qDebounced || undefined,
    statusPenderes: fStatusPenderes.length > 0 ? fStatusPenderes : undefined,
    sertakanNonaktif,
  });
  const tambahPetani = useTambahPetani();
  const ubahPetani = useUbahPetani();
  const hapusPetani = useHapusPetani();

  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState<Petani | null>(null);
  const [form, setForm] = useState<FormState>(emptyForm);
  const [err, setErr] = useState<string | null>(null);

  const openTambah = () => {
    setEditing(null);
    setForm(emptyForm);
    setErr(null);
    setOpen(true);
  };

  const openEdit = (p: Petani) => {
    setEditing(p);
    setForm({
      nama: p.nama,
      kodeLahan: p.kodeLahan ?? "",
      rtRw: p.rtRw ?? "",
      aktif: p.aktif,
      kontak: p.kontak,
      alamat: p.alamat,
      statusPenderes: (p.statusPenderes ?? []).map((s) => s.kode),
    });
    setErr(null);
    setOpen(true);
  };

  const simpan = async () => {
    if (!form.nama.trim()) return setErr("Nama petani wajib diisi.");
    const payload: PetaniPayload = {
      nama: form.nama.trim(),
      kodeLahan: form.kodeLahan.trim() || undefined,
      rtRw: form.rtRw.trim() || undefined,
      aktif: form.aktif,
      kontak: form.kontak.trim() || undefined,
      alamat: form.alamat.trim() || undefined,
      // Selalu dikirim (walau kosong) supaya status yang dilepas ikut terhapus.
      statusPenderes: form.statusPenderes,
    };
    try {
      if (editing) {
        await ubahPetani.mutateAsync({ id: editing.id, payload });
        toast.success("Data petani diperbarui", { description: payload.nama });
      } else {
        await tambahPetani.mutateAsync(payload);
        toast.success("Petani baru ditambahkan", { description: payload.nama });
      }
      setOpen(false);
    } catch (e) {
      setErr(
        apiErrorMessage(e, editing ? "Gagal mengubah data petani." : "Gagal menambah petani."),
      );
    }
  };

  const hapus = async (p: Petani) => {
    try {
      await hapusPetani.mutateAsync(p.id);
      toast.success("Data petani dihapus", { description: p.nama });
    } catch (e) {
      toast.error(apiErrorMessage(e, "Gagal menghapus petani."));
    }
  };

  const saving = tambahPetani.isPending || ubahPetani.isPending;

  /**
   * Petani yang berhenti menderes cukup dinonaktifkan — menghapusnya akan ikut
   * membawa riwayat pembelian yang sudah tercatat atas namanya.
   */
  const toggleAktif = async (p: Petani) => {
    try {
      await ubahPetani.mutateAsync({
        id: p.id,
        payload: { nama: p.nama, aktif: !p.aktif },
      });
      toast.success(p.aktif ? "Petani dinonaktifkan" : "Petani diaktifkan kembali", {
        description: p.nama,
      });
    } catch (e) {
      toast.error(apiErrorMessage(e, "Gagal mengubah status aktif petani."));
    }
  };

  const toggleStatusForm = (kode: StatusPenderesKode) =>
    setForm((f) => ({
      ...f,
      statusPenderes: f.statusPenderes.includes(kode)
        ? f.statusPenderes.filter((k) => k !== kode)
        : [...f.statusPenderes, kode],
    }));

  const toggleStatusFilter = (kode: StatusPenderesKode) =>
    setFStatusPenderes((daftar) =>
      daftar.includes(kode) ? daftar.filter((k) => k !== kode) : [...daftar, kode],
    );

  const semuaKolom: Column<Petani>[] = [
    {
      key: "nama",
      header: "Nama",
      sortValue: (r) => r.nama,
      cell: (r) => (
        <div>
          <span className="font-medium">{r.nama}</span>
          {!r.aktif && (
            <Badge variant="secondary" className="ml-2 align-middle text-[10px]">
              Nonaktif
            </Badge>
          )}
        </div>
      ),
    },
    {
      // Kode lahan sekaligus nomor member — satu kolom, bukan dua.
      key: "lahan",
      header: "Kode Lahan / No. Member",
      sortValue: (r) => r.kodeLahan ?? "",
      cell: (r) =>
        r.kodeLahan ? (
          <span className="font-mono text-xs">{r.kodeLahan}</span>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
    {
      key: "rtrw",
      header: "RT/RW",
      sortValue: (r) => r.rtRw ?? "",
      cell: (r) => r.rtRw ?? <span className="text-muted-foreground">—</span>,
    },
    {
      key: "penderes",
      header: "Status Penderes",
      cell: (r) =>
        r.statusPenderes && r.statusPenderes.length > 0 ? (
          <div className="flex flex-wrap gap-1">
            {r.statusPenderes.map((s) => (
              <Badge key={s.kode} variant="outline" title={s.keterangan}>
                {s.label}
              </Badge>
            ))}
          </div>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
    {
      key: "status",
      header: "Status Member",
      sortValue: (r) => r.status,
      cell: (r) =>
        r.status === "Member" ? (
          <Badge className="bg-success/15 text-success hover:bg-success/15">Member</Badge>
        ) : (
          <Badge variant="secondary">Non-Member</Badge>
        ),
    },
    { key: "kontak", header: "Kontak", cell: (r) => r.kontak || "-" },
    {
      key: "alamat",
      header: "Alamat",
      cell: (r) => <span className="text-muted-foreground">{r.alamat || "-"}</span>,
    },
    {
      key: "trx",
      header: "Total Transaksi",
      align: "right",
      sortValue: (r) => r.totalNilai,
      cell: (r) => (
        <div>
          <p className="font-medium">{rupiah(r.totalNilai)}</p>
          <p className="text-xs text-muted-foreground">{angka(r.totalTransaksi)} transaksi</p>
        </div>
      ),
    },
    {
      key: "aksi",
      header: "Aksi",
      align: "right",
      cell: (r) => (
        <div className="flex justify-end gap-1">
          <Button variant="ghost" size="icon" aria-label="Ubah" onClick={() => openEdit(r)}>
            <Pencil className="size-4" />
          </Button>
          <Button
            variant="ghost"
            size="icon"
            aria-label={r.aktif ? "Nonaktifkan" : "Aktifkan kembali"}
            title={r.aktif ? "Nonaktifkan" : "Aktifkan kembali"}
            onClick={() => toggleAktif(r)}
          >
            {r.aktif ? <PowerOff className="size-4" /> : <RotateCcw className="size-4" />}
          </Button>
          <Button variant="ghost" size="icon" aria-label="Hapus" onClick={() => hapus(r)}>
            <Trash2 className="size-4 text-destructive" />
          </Button>
        </div>
      ),
    },
  ];

  // Kolom opsional disaring sesuai pilihan pengguna; sisanya selalu tampil.
  const cols = semuaKolom.filter(
    (c) => !(c.key in KOLOM_OPSIONAL) || kolom[c.key as KolomOpsional],
  );

  return (
    <>
      <PageHeader
        title="Data Petani"
        subtitle={
          isLoading
            ? "Memuat…"
            : `${rows.length} petani${sertakanNonaktif ? " (termasuk nonaktif)" : " aktif"}`
        }
        action={
          <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
              <Button onClick={openTambah}>
                <Plus className="mr-2 size-4" /> Tambah Petani
              </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
              <DialogHeader>
                <DialogTitle>{editing ? "Ubah Data Petani" : "Tambah Petani"}</DialogTitle>
              </DialogHeader>
              <div className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="nama">Nama Petani</Label>
                  <Input
                    id="nama"
                    value={form.nama}
                    onChange={(e) => setForm({ ...form, nama: e.target.value })}
                    placeholder="Contoh: Sukirman"
                  />
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                  <div className="space-y-2">
                    <Label htmlFor="kodeLahan">Kode Lahan / No. Member</Label>
                    <Input
                      id="kodeLahan"
                      value={form.kodeLahan}
                      onChange={(e) => setForm({ ...form, kodeLahan: e.target.value })}
                      placeholder="Contoh: BA-002"
                    />
                    <p className="text-xs text-muted-foreground">
                      {form.kodeLahan.trim()
                        ? "Terisi → petani terdaftar sebagai Member."
                        : "Kosongkan bila petani belum jadi member."}
                    </p>
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="rtRw">RT/RW</Label>
                    <Input
                      id="rtRw"
                      value={form.rtRw}
                      onChange={(e) => setForm({ ...form, rtRw: e.target.value })}
                      placeholder="02/05"
                    />
                  </div>
                </div>
                <div className="flex items-center justify-between rounded-lg border p-3">
                  <div>
                    <Label htmlFor="aktif" className="cursor-pointer">
                      Petani aktif
                    </Label>
                    <p className="text-xs text-muted-foreground">
                      Matikan bila sudah berhenti menderes — riwayat transaksinya tetap tersimpan.
                    </p>
                  </div>
                  <Switch
                    id="aktif"
                    checked={form.aktif}
                    onCheckedChange={(v) => setForm({ ...form, aktif: v })}
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="kontak">Kontak (opsional)</Label>
                  <Input
                    id="kontak"
                    value={form.kontak}
                    onChange={(e) => setForm({ ...form, kontak: e.target.value })}
                    placeholder="0812-3456-7890"
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="alamat">Alamat</Label>
                  <Input
                    id="alamat"
                    value={form.alamat}
                    onChange={(e) => setForm({ ...form, alamat: e.target.value })}
                    placeholder="Desa, Kecamatan"
                  />
                </div>
                <div className="space-y-2">
                  <Label>Status Penderes / Pemilik Lahan</Label>
                  <p className="text-xs text-muted-foreground">
                    Boleh lebih dari satu, mis. PMS + PLMD.
                  </p>
                  <div className="grid gap-2 sm:grid-cols-2">
                    {DAFTAR_STATUS_PENDERES.map((s) => (
                      <label
                        key={s.kode}
                        htmlFor={`status-${s.kode}`}
                        className="flex cursor-pointer items-start gap-2 rounded-lg border p-2 text-sm hover:bg-accent"
                      >
                        <Checkbox
                          id={`status-${s.kode}`}
                          checked={form.statusPenderes.includes(s.kode)}
                          onCheckedChange={() => toggleStatusForm(s.kode)}
                          className="mt-0.5"
                        />
                        <span className="min-w-0">
                          <span className="font-medium">{s.label}</span>
                          <span className="block text-xs text-muted-foreground">
                            {s.keterangan}
                          </span>
                        </span>
                      </label>
                    ))}
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
                  Simpan
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        }
      />

      <Card className="overflow-hidden shadow-card">
        <CardContent className="space-y-4 px-0 py-4">
          <div className="space-y-3 px-4">
            <SearchInput
              value={q}
              onChange={setQ}
              placeholder="Cari nama, nomor member, atau kontak..."
            />
            <div className="flex flex-wrap items-center gap-3">
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Switch
                  id="nonaktif-petani"
                  checked={sertakanNonaktif}
                  onCheckedChange={setSertakanNonaktif}
                />
                <Label htmlFor="nonaktif-petani" className="cursor-pointer font-normal">
                  Tampilkan nonaktif
                </Label>
              </div>

              {/* Kolom bisa disembunyikan supaya tabel tetap terbaca di layar sempit. */}
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="outline" size="sm" className="h-8">
                    <Columns3 className="mr-2 size-4" /> Kolom
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" className="w-52">
                  <DropdownMenuLabel>Tampilkan kolom</DropdownMenuLabel>
                  <DropdownMenuSeparator />
                  {(Object.keys(KOLOM_OPSIONAL) as KolomOpsional[]).map((k) => (
                    <DropdownMenuCheckboxItem
                      key={k}
                      checked={kolom[k]}
                      onCheckedChange={(v) => setKolom((c) => ({ ...c, [k]: Boolean(v) }))}
                      onSelect={(e) => e.preventDefault()}
                    >
                      {KOLOM_OPSIONAL[k]}
                    </DropdownMenuCheckboxItem>
                  ))}
                  <DropdownMenuSeparator />
                  <button
                    type="button"
                    onClick={() => setKolom(KOLOM_DEFAULT)}
                    className="w-full rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent"
                  >
                    Kembalikan ke bawaan
                  </button>
                </DropdownMenuContent>
              </DropdownMenu>
            </div>

            <div className="flex flex-wrap items-center gap-1.5">
              <span className="mr-1 text-xs text-muted-foreground">Status penderes:</span>
              {DAFTAR_STATUS_PENDERES.map((s) => {
                const aktif = fStatusPenderes.includes(s.kode);
                return (
                  <Button
                    key={s.kode}
                    type="button"
                    size="sm"
                    variant={aktif ? "default" : "outline"}
                    className="h-7 px-2 text-xs"
                    title={s.keterangan}
                    onClick={() => toggleStatusFilter(s.kode)}
                  >
                    {s.label}
                  </Button>
                );
              })}
              {fStatusPenderes.length > 0 && (
                <Button
                  type="button"
                  size="sm"
                  variant="ghost"
                  className="h-7 px-2 text-xs"
                  onClick={() => setFStatusPenderes([])}
                >
                  Reset
                </Button>
              )}
            </div>
          </div>
          {isError && (
            <p className="px-4 text-sm text-destructive">
              Gagal memuat data petani. Coba muat ulang halaman.
            </p>
          )}
          {isLoading ? (
            <LoadingRow />
          ) : (
            <DataTable
              rows={rows}
              columns={cols}
              rowKey={(r) => r.id}
              initialSort={{ key: "nama", dir: "asc" }}
              empty={
                <EmptyState
                  title="Petani tidak ditemukan"
                  description="Coba kata kunci lain, atau tambahkan petani baru."
                  action={
                    <Button onClick={openTambah}>
                      <Plus className="mr-2 size-4" /> Tambah Petani
                    </Button>
                  }
                />
              }
            />
          )}
        </CardContent>
      </Card>
    </>
  );
}
