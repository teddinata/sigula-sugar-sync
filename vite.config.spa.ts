/**
 * Konfigurasi build SPA untuk self-hosting (VPS + Nginx).
 *
 * Sengaja terpisah dari `vite.config.ts` supaya alur Lovable (TanStack Start +
 * Nitro, target Cloudflare) tidak terganggu. Config ini tidak memakai plugin
 * TanStack Start sama sekali — hanya React + Tailwind + alias path — dan
 * memakai `src/routeTree.gen.ts` yang sudah ada di repo.
 */
import { readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { defineConfig, type Plugin } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
import tsConfigPaths from "vite-tsconfig-paths";

const pkg = JSON.parse(readFileSync(new URL("./package.json", import.meta.url), "utf8")) as {
  version?: string;
};

const versi = pkg.version ?? "0.0.0";
const dibangunPada = new Date().toISOString();
// Build id berubah tiap build, jadi deploy ulang tanpa naik versi pun terdeteksi.
const buildId = `${versi}+${Date.now().toString(36)}`;

/**
 * Menulis `version.json` ke hasil build.
 *
 * Aplikasi yang sedang jalan mengambil file ini berkala dan membandingkan
 * buildId-nya dengan yang tertanam di bundel. Kalau berbeda, artinya ada deploy
 * baru dan pengguna diminta memuat ulang (lihat src/lib/versi.ts).
 */
function tulisVersionJson(): Plugin {
  return {
    name: "sigula-version-json",
    apply: "build",
    closeBundle() {
      const tujuan = join(process.cwd(), "dist-spa", "version.json");

      writeFileSync(
        tujuan,
        JSON.stringify({ versi, buildId, dibangunPada }, null, 2) + "\n",
        "utf8",
      );
    },
  };
}

export default defineConfig({
  plugins: [react(), tailwindcss(), tsConfigPaths(), tulisVersionJson()],
  define: {
    // Ditanam saat build supaya aplikasi tahu versi dirinya sendiri.
    "import.meta.env.VITE_APP_VERSION": JSON.stringify(versi),
    "import.meta.env.VITE_BUILD_ID": JSON.stringify(buildId),
    "import.meta.env.VITE_BUILD_TIME": JSON.stringify(dibangunPada),
  },
  build: {
    outDir: "dist-spa",
    emptyOutDir: true,
    sourcemap: false,
  },
});
