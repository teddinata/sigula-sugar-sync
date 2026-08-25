/**
 * Ringkasan keuangan yang ditulis AI.
 *
 * Angkanya dihitung backend lebih dulu lalu disuapkan ke model sebagai fakta,
 * jadi ringkasan tidak mengarang nominal. Hasilnya di-cache 30 menit di server;
 * tombol "Buat ulang" memaksa panggilan baru ke model.
 *
 * Dibuat hanya saat diminta (bukan otomatis saat halaman dibuka) karena tiap
 * panggilan model berbiaya.
 */
import { useState } from "react";
import { AlertCircle, RefreshCw, Sparkles } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { ApiError } from "@/lib/api-client";
import { getRingkasanAi, type RingkasanAi } from "@/lib/api/ringkasan-ai";

export function RingkasanAiCard({ dari, sampai }: { dari: string; sampai: string }) {
  const [hasil, setHasil] = useState<RingkasanAi | null>(null);
  const [memuat, setMemuat] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const buat = async (segarkan = false) => {
    setMemuat(true);
    setError(null);
    try {
      setHasil(await getRingkasanAi({ dari, sampai, segarkan }));
    } catch (e) {
      setError(
        e instanceof ApiError
          ? (e.firstFieldError ?? e.message)
          : "Tidak bisa terhubung ke server.",
      );
    } finally {
      setMemuat(false);
    }
  };

  return (
    <Card className="shadow-card">
      <CardHeader className="flex-row items-start justify-between gap-4 space-y-0">
        <div className="min-w-0">
          <CardTitle className="flex items-center gap-2 text-base">
            <Sparkles className="size-4 text-primary" /> Ringkasan Keuangan AI
          </CardTitle>
          <p className="mt-1 text-xs text-muted-foreground">
            Analisis singkat performa periode ini, ditulis dari angka laporan yang sama.
          </p>
        </div>
        <Button
          variant={hasil ? "outline" : "default"}
          size="sm"
          onClick={() => void buat(hasil !== null)}
          disabled={memuat}
          className="shrink-0"
        >
          <RefreshCw className={`mr-2 size-4 ${memuat ? "animate-spin" : ""}`} />
          {memuat ? "Menganalisis…" : hasil ? "Buat ulang" : "Buat ringkasan"}
        </Button>
      </CardHeader>

      <CardContent>
        {error && (
          <div className="flex gap-3 rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-sm">
            <AlertCircle className="mt-0.5 size-4 shrink-0 text-destructive" />
            <div>
              <p className="font-medium text-destructive">Ringkasan gagal dibuat</p>
              <p className="mt-1 text-muted-foreground">{error}</p>
            </div>
          </div>
        )}

        {!error && !hasil && !memuat && (
          <p className="rounded-xl bg-cream px-4 py-6 text-center text-sm text-muted-foreground">
            Belum ada ringkasan untuk periode ini. Klik <b>Buat ringkasan</b> untuk membuatnya.
          </p>
        )}

        {memuat && !hasil && (
          <div className="space-y-2">
            <div className="h-4 w-3/4 animate-pulse rounded bg-muted" />
            <div className="h-4 w-full animate-pulse rounded bg-muted" />
            <div className="h-4 w-5/6 animate-pulse rounded bg-muted" />
          </div>
        )}

        {hasil && (
          <div className="space-y-3">
            {/* whitespace-pre-line: model menulis dalam beberapa paragraf. */}
            <p className="whitespace-pre-line text-sm leading-relaxed">{hasil.ringkasan}</p>

            <div className="flex flex-wrap items-center gap-2 border-t pt-3 text-xs text-muted-foreground">
              <Badge variant="secondary" className="font-mono text-[10px]">
                {hasil.model}
              </Badge>
              <span>Periode {hasil.periode.label}</span>
              {hasil.dariCache && <span>· dari cache 30 menit terakhir</span>}
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
