import { useEffect, useState } from "react";
import { createFileRoute } from "@tanstack/react-router";
import { Loader2, Pencil, Plus, ShieldCheck, Trash2 } from "lucide-react";
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
import { Switch } from "@/components/ui/switch";
import {
  DataTable,
  EmptyState,
  PageHeader,
  SearchInput,
  type Column,
} from "@/components/sigula/ui-bits";
import { ApiError } from "@/lib/api-client";
import { tanggalPendek } from "@/lib/format";
import type { Pengguna, PenggunaPayload, RoleKode } from "@/lib/api/pengguna";
import {
  useDaftarRole,
  useHapusPengguna,
  usePenggunaList,
  useTambahPengguna,
  useUbahPengguna,
} from "@/hooks/use-pengguna";

export const Route = createFileRoute("/_app/pengguna")({
  head: () => ({
    meta: [
      { title: "Kelola Pengguna — SIGULA" },
      {
        name: "description",
        content:
          "Kelola akun pengguna SIGULA: buat akun baru, atur role dan hak akses, nonaktifkan akun yang sudah tidak dipakai.",
      },
      { property: "og:title", content: "Kelola Pengguna — SIGULA" },
      {
        property: "og:description",
        content: "Manajemen akun dan hak akses pengguna sistem.",
      },
    ],
  }),
  component: PenggunaPage,
});

interface FormState {
  nama: string;
  email: string;
  password: string;
  role: RoleKode;
  aktif: boolean;
}

const emptyForm: FormState = {
  nama: "",
  email: "",
  password: "",
  role: "staff_gudang",
  aktif: true,
};

function apiErrorMessage(err: unknown, fallback: string): string {
  return err instanceof ApiError ? (err.firstFieldError ?? err.message) : fallback;
}

function RoleBadge({ role, label }: { role: RoleKode; label: string }) {
  if (role === "owner") {
    return <Badge className="bg-primary/15 text-primary hover:bg-primary/15">{label}</Badge>;
  }
  if (role === "admin") {
    return (
      <Badge className="bg-warning/25 text-warning-foreground hover:bg-warning/25">{label}</Badge>
    );
  }
  return <Badge variant="secondary">{label}</Badge>;
}

function PenggunaPage() {
  const [q, setQ] = useState("");
  const [qDebounced, setQDebounced] = useState("");
  const [sertakanNonaktif, setSertakanNonaktif] = useState(false);

  useEffect(() => {
    const t = setTimeout(() => setQDebounced(q.trim()), 300);
    return () => clearTimeout(t);
  }, [q]);

  const {
    data: rows = [],
    isLoading,
    isError,
  } = usePenggunaList({ q: qDebounced || undefined, sertakanNonaktif });
  const { data: daftarRole = [] } = useDaftarRole();

  const tambah = useTambahPengguna();
  const ubah = useUbahPengguna();
  const hapus = useHapusPengguna();

  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState<Pengguna | null>(null);
  const [form, setForm] = useState<FormState>(emptyForm);
  const [err, setErr] = useState<string | null>(null);

  const openTambah = () => {
    setEditing(null);
    setForm(emptyForm);
    setErr(null);
    setOpen(true);
  };

  const openEdit = (p: Pengguna) => {
    setEditing(p);
    // Password sengaja kosong: diisi hanya bila memang mau diganti.
    setForm({ nama: p.nama, email: p.email, password: "", role: p.role, aktif: p.aktif });
    setErr(null);
    setOpen(true);
  };

  const simpan = async () => {
    if (!form.nama.trim()) return setErr("Nama wajib diisi.");
    if (!form.email.trim()) return setErr("Email wajib diisi.");
    if (!editing && form.password.length < 8) return setErr("Password minimal 8 karakter.");
    if (editing && form.password !== "" && form.password.length < 8)
      return setErr("Password baru minimal 8 karakter.");

    const payload: PenggunaPayload = {
      nama: form.nama.trim(),
      email: form.email.trim(),
      role: form.role,
      aktif: form.aktif,
      ...(form.password ? { password: form.password } : {}),
    };

    try {
      if (editing) {
        await ubah.mutateAsync({ id: editing.id, payload });
        toast.success("Akun diperbarui", {
          description: form.password ? "Password diganti, sesi lama dicabut." : form.nama,
        });
      } else {
        await tambah.mutateAsync(payload);
        toast.success("Akun dibuat", { description: `${form.nama} — ${form.email}` });
      }
      setOpen(false);
    } catch (e) {
      setErr(apiErrorMessage(e, editing ? "Gagal memperbarui akun." : "Gagal membuat akun."));
    }
  };

  const hapusAkun = async (p: Pengguna) => {
    try {
      const res = await hapus.mutateAsync(p.id);
      toast.success(res.message, { description: p.email });
    } catch (e) {
      toast.error(apiErrorMessage(e, "Gagal menghapus akun."));
    }
  };

  const saving = tambah.isPending || ubah.isPending;
  const rolePilihan = daftarRole.find((r) => r.kode === form.role);

  const cols: Column<Pengguna>[] = [
    {
      key: "nama",
      header: "Nama",
      sortValue: (r) => r.nama,
      cell: (r) => (
        <div>
          <span className="font-medium">{r.nama}</span>
          {r.diriSendiri && (
            <Badge variant="outline" className="ml-2 align-middle text-[10px]">
              Kamu
            </Badge>
          )}
          <p className="text-xs text-muted-foreground">{r.email}</p>
        </div>
      ),
    },
    {
      key: "role",
      header: "Role",
      sortValue: (r) => r.roleLabel,
      cell: (r) => <RoleBadge role={r.role} label={r.roleLabel} />,
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
      key: "dibuat",
      header: "Dibuat",
      cell: (r) => (
        <span className="text-xs text-muted-foreground">
          {r.dibuatPada ? tanggalPendek(r.dibuatPada.slice(0, 10)) : "—"}
        </span>
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
            aria-label="Hapus"
            // Menghapus akun sendiri akan langsung mengunci diri keluar.
            disabled={r.diriSendiri}
            title={r.diriSendiri ? "Tidak bisa menghapus akun sendiri" : "Hapus"}
            onClick={() => hapusAkun(r)}
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
        title="Kelola Pengguna"
        subtitle={
          isLoading
            ? "Memuat…"
            : `${rows.length} akun${sertakanNonaktif ? " (termasuk nonaktif)" : " aktif"}`
        }
        action={
          <Button onClick={openTambah}>
            <Plus className="mr-2 size-4" /> Tambah Pengguna
          </Button>
        }
      />

      <Card className="overflow-hidden shadow-card">
        <CardContent className="space-y-4 px-0 py-4">
          <div className="flex flex-wrap items-center gap-3 px-4">
            <SearchInput
              value={q}
              onChange={setQ}
              placeholder="Cari nama atau email…"
              className="flex-1"
            />
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <Switch
                id="nonaktif-pengguna"
                checked={sertakanNonaktif}
                onCheckedChange={setSertakanNonaktif}
              />
              <Label htmlFor="nonaktif-pengguna" className="cursor-pointer font-normal">
                Tampilkan nonaktif
              </Label>
            </div>
          </div>

          {isError && (
            <p className="px-4 text-sm text-destructive">
              Gagal memuat daftar pengguna. Coba muat ulang halaman.
            </p>
          )}

          {isLoading ? (
            <div className="flex items-center justify-center gap-2 px-4 py-14 text-sm text-muted-foreground">
              <Loader2 className="size-4 animate-spin" /> Memuat data…
            </div>
          ) : (
            <DataTable
              rows={rows}
              columns={cols}
              rowKey={(r) => r.id}
              initialSort={{ key: "nama", dir: "asc" }}
              empty={
                <EmptyState
                  title="Pengguna tidak ditemukan"
                  description="Coba kata kunci lain, atau tambahkan akun baru."
                />
              }
            />
          )}
        </CardContent>
      </Card>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="max-h-[90vh] max-w-lg overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{editing ? "Ubah Akun" : "Tambah Pengguna"}</DialogTitle>
          </DialogHeader>

          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="nama-pengguna">Nama</Label>
              <Input
                id="nama-pengguna"
                value={form.nama}
                onChange={(e) => setForm({ ...form, nama: e.target.value })}
                placeholder="Contoh: Admin Kantor"
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="email-pengguna">Email</Label>
              <Input
                id="email-pengguna"
                type="email"
                autoComplete="off"
                value={form.email}
                onChange={(e) => setForm({ ...form, email: e.target.value })}
                placeholder="nama@nirasarimurni.com"
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="password-pengguna">
                {editing ? "Password baru (opsional)" : "Password"}
              </Label>
              <Input
                id="password-pengguna"
                type="password"
                autoComplete="new-password"
                value={form.password}
                onChange={(e) => setForm({ ...form, password: e.target.value })}
                placeholder={editing ? "Kosongkan bila tidak diganti" : "Minimal 8 karakter"}
              />
              {editing && (
                <p className="text-xs text-muted-foreground">
                  Mengganti password otomatis mencabut sesi login lama akun ini.
                </p>
              )}
            </div>

            <div className="space-y-2">
              <Label>Role</Label>
              <Select
                value={form.role}
                onValueChange={(v: RoleKode) => setForm({ ...form, role: v })}
                disabled={editing?.diriSendiri ?? false}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {daftarRole.map((r) => (
                    <SelectItem key={r.kode} value={r.kode}>
                      {r.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {editing?.diriSendiri && (
                <p className="text-xs text-muted-foreground">
                  Role akun sendiri tidak bisa diubah — mencegah terkunci keluar dari sistem.
                </p>
              )}
            </div>

            {rolePilihan && (
              <div className="rounded-xl bg-cream px-4 py-3">
                <p className="flex items-center gap-2 text-sm font-medium">
                  <ShieldCheck className="size-4 text-primary" /> {rolePilihan.keterangan}
                </p>
                <p className="mt-2 text-xs text-muted-foreground">Menu yang bisa dibuka:</p>
                <div className="mt-1 flex flex-wrap gap-1">
                  {rolePilihan.menu.map((m) => (
                    <Badge key={m} variant="outline" className="text-[10px] capitalize">
                      {m}
                    </Badge>
                  ))}
                </div>
              </div>
            )}

            <div className="flex items-center justify-between rounded-lg border p-3">
              <div>
                <Label htmlFor="aktif-pengguna" className="cursor-pointer">
                  Akun aktif
                </Label>
                <p className="text-xs text-muted-foreground">
                  Akun nonaktif tidak bisa login, tapi riwayat aksinya tetap tersimpan.
                </p>
              </div>
              <Switch
                id="aktif-pengguna"
                checked={form.aktif}
                onCheckedChange={(v) => setForm({ ...form, aktif: v })}
                disabled={editing?.diriSendiri ?? false}
              />
            </div>

            {err && <p className="text-sm text-destructive">{err}</p>}
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)}>
              Batal
            </Button>
            <Button onClick={simpan} disabled={saving}>
              {saving && <Loader2 className="mr-2 size-4 animate-spin" />}
              Simpan
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
