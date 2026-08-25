/**
 * Cetak struk untuk printer thermal 58mm.
 *
 * Printer thermal tidak mengenal ukuran A4: kertasnya selebar 58mm dengan
 * panjang mengikuti isi. Karena aturan `@page` tidak bisa dikondisikan oleh
 * class CSS, ukuran kertas disisipkan sebagai <style> sementara tepat sebelum
 * window.print() dan dilepas lagi setelah dialog cetak ditutup.
 *
 * Aturan tampilannya ada di src/styles.css (blok `html.cetak-thermal`).
 */
import type { ReactNode } from "react";

const ID_GAYA = "gaya-cetak-thermal";

export function cetakThermal(): void {
  const html = document.documentElement;

  const gaya = document.createElement("style");
  gaya.id = ID_GAYA;
  gaya.textContent = "@page { size: 58mm auto; margin: 0; }";
  document.head.appendChild(gaya);
  html.classList.add("cetak-thermal");

  // Idempoten: afterprint dan timeout pengaman boleh sama-sama memanggilnya.
  const bersihkan = () => {
    html.classList.remove("cetak-thermal");
    document.getElementById(ID_GAYA)?.remove();
    window.removeEventListener("afterprint", bersihkan);
  };

  window.addEventListener("afterprint", bersihkan);
  window.print();

  // Sebagian browser (Safari lama) tidak memicu afterprint.
  window.setTimeout(bersihkan, 1000);
}

/**
 * Wadah struk thermal. Selalu ada di DOM tapi tersembunyi; hanya muncul saat
 * cetakThermal() aktif, sehingga isinya tidak mengganggu tampilan layar.
 */
export function StrukThermal({ children }: { children: ReactNode }) {
  return (
    <div
      id="struk-thermal"
      aria-hidden
      className="hidden font-mono text-[11px] leading-tight text-black"
    >
      {children}
    </div>
  );
}

export function JudulThermal({ judul, subjudul }: { judul: string; subjudul?: string }) {
  return (
    <div className="mb-1 text-center">
      <p className="text-[12px] font-bold uppercase">{judul}</p>
      {subjudul && <p className="text-[10px]">{subjudul}</p>}
    </div>
  );
}

export function GarisThermal() {
  // Garis putus-putus khas struk kasir; dibuat dari karakter agar pasti tercetak.
  return <p className="my-1 overflow-hidden whitespace-nowrap">--------------------------------</p>;
}

export function BarisThermal({
  label,
  value,
  tebal,
}: {
  label: string;
  value: ReactNode;
  tebal?: boolean;
}) {
  return (
    <div className={`flex justify-between gap-2 ${tebal ? "font-bold" : ""}`}>
      <span>{label}</span>
      <span className="text-right">{value}</span>
    </div>
  );
}
