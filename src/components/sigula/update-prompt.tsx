/**
 * Popup "versi baru tersedia" — pola yang sama seperti pengingat update
 * WhatsApp: muncul sendiri saat ada rilis baru, menampilkan apa yang berubah,
 * dan menawarkan "Nanti saja" atau "Perbarui sekarang".
 *
 * Kalau backend menandai versi web yang berjalan sudah tidak kompatibel
 * (`minimalWeb`), pembaruan jadi wajib dan tombol tunda disembunyikan.
 */
import { useState } from "react";
import { Loader2, RefreshCw, Sparkles } from "lucide-react";

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { useUpdateChecker } from "@/hooks/use-update-checker";
import { muatUlangAplikasi } from "@/lib/versi";

export function UpdatePrompt() {
  const { tersedia, wajib, versiBaru, catatan, versiSekarang, tunda } = useUpdateChecker();
  const [memuat, setMemuat] = useState(false);

  const perbarui = () => {
    setMemuat(true);
    void muatUlangAplikasi();
  };

  return (
    <AlertDialog open={tersedia}>
      <AlertDialogContent
        className="max-w-md"
        // Update wajib tidak boleh dilewati dengan Esc atau klik di luar.
        onEscapeKeyDown={(e) => {
          if (wajib) e.preventDefault();
        }}
      >
        <AlertDialogHeader>
          <div className="mb-1 flex size-11 items-center justify-center rounded-full bg-primary/10">
            <Sparkles className="size-5 text-primary" />
          </div>
          <AlertDialogTitle>
            {wajib ? "Pembaruan wajib" : "Versi baru SIGULA tersedia"}
          </AlertDialogTitle>
          <AlertDialogDescription>
            {wajib
              ? "Versi yang sedang kamu buka sudah tidak didukung server. Muat ulang untuk melanjutkan."
              : "Ada perbaikan dan fitur baru. Muat ulang halaman untuk memakai versi terbaru."}
          </AlertDialogDescription>
        </AlertDialogHeader>

        {catatan.length > 0 && (
          <div className="rounded-lg border bg-muted/40 p-3">
            <p className="mb-2 text-xs font-medium text-muted-foreground">Yang baru</p>
            <ul className="space-y-1.5 text-sm">
              {catatan.slice(0, 6).map((baris) => (
                <li key={baris} className="flex gap-2">
                  <span aria-hidden className="mt-1.5 size-1.5 shrink-0 rounded-full bg-primary" />
                  <span>{baris}</span>
                </li>
              ))}
            </ul>
          </div>
        )}

        <p className="text-xs text-muted-foreground">
          Versi terpasang {versiSekarang}
          {versiBaru && versiBaru !== versiSekarang ? ` → versi baru ${versiBaru}` : null}
        </p>

        <AlertDialogFooter>
          {!wajib && (
            <AlertDialogCancel onClick={tunda} disabled={memuat}>
              Nanti saja
            </AlertDialogCancel>
          )}
          <AlertDialogAction onClick={perbarui} disabled={memuat}>
            {memuat ? (
              <Loader2 className="size-4 animate-spin" />
            ) : (
              <RefreshCw className="size-4" />
            )}
            Perbarui sekarang
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
