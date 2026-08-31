import { useEffect } from "react";
import { Outlet, createFileRoute, useLocation, useNavigate } from "@tanstack/react-router";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";
import { AppShell } from "@/components/sigula/app-shell";
import { useAuth } from "@/store/auth-store";

export const Route = createFileRoute("/_app")({
  component: AppLayout,
});

/**
 * Segmen pertama URL dipetakan ke kunci menu dari `/auth/me`.
 *
 * Menyembunyikan menu di sidebar saja tidak cukup: URL-nya masih bisa diketik
 * langsung. Backend tetap menolak dengan 403, tapi tanpa guard ini pengguna
 * disuguhi halaman yang gagal memuat, bukan penolakan yang jelas.
 */
function kunciMenu(pathname: string): string | null {
  const segmen = pathname.split("/").filter(Boolean)[0];
  return segmen ?? null;
}

function AppLayout() {
  const { status, user } = useAuth();
  const navigate = useNavigate();
  const { pathname } = useLocation();

  useEffect(() => {
    if (status === "unauthenticated") navigate({ to: "/" });
  }, [status, navigate]);

  useEffect(() => {
    if (status !== "authenticated" || !user) return;

    const kunci = kunciMenu(pathname);
    if (kunci === null || user.menu.includes(kunci)) return;

    toast.error("Halaman ini tidak tersedia untuk role kamu", {
      description: `Role ${user.roleLabel} tidak punya akses ke menu tersebut.`,
    });
    navigate({ to: "/dashboard", replace: true });
  }, [status, user, pathname, navigate]);

  if (status !== "authenticated") {
    return (
      <div className="flex min-h-screen items-center justify-center bg-background">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    );
  }

  return (
    <AppShell>
      <Outlet />
    </AppShell>
  );
}
