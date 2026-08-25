/**
 * Klien HTTP terpusat untuk API SIGULA (Laravel + Sanctum, lihat SIGULA.postman_collection.json).
 *
 * - Base URL dari VITE_API_BASE_URL (fallback ke backend lokal default).
 * - Token Sanctum disimpan di localStorage dan otomatis dilampirkan sebagai
 *   header Authorization pada tiap request ("request interceptor").
 * - Response 401 otomatis mencabut token tersimpan dan memicu handler yang
 *   didaftarkan lewat onUnauthorized (dipakai auth-store untuk redirect ke
 *   halaman login) — pola ini menggantikan axios response interceptor.
 */

const DEFAULT_BASE_URL = "https://api.nirasarimurni.com/api/v1";

export const API_BASE_URL =
  (import.meta.env["VITE_API_BASE_URL"] as string | undefined)?.replace(/\/+$/, "") ||
  DEFAULT_BASE_URL;

const TOKEN_KEY = "sigula.token";

const isBrowser = typeof window !== "undefined";

export function getToken(): string | null {
  if (!isBrowser) return null;
  return window.localStorage.getItem(TOKEN_KEY);
}

export function setToken(token: string): void {
  if (!isBrowser) return;
  window.localStorage.setItem(TOKEN_KEY, token);
}

export function clearToken(): void {
  if (!isBrowser) return;
  window.localStorage.removeItem(TOKEN_KEY);
}

type UnauthorizedHandler = () => void;
let unauthorizedHandler: UnauthorizedHandler | null = null;

/** Didaftarkan oleh auth-store supaya api-client bisa memicu redirect saat token ditolak. */
export function onUnauthorized(handler: UnauthorizedHandler): void {
  unauthorizedHandler = handler;
}

/** Bentuk error validasi 422 dari backend: `{ message, errors: { field: [...] } }`. */
export interface ApiValidationErrors {
  [field: string]: string[];
}

export class ApiError extends Error {
  status: number;
  errors: ApiValidationErrors | null;

  constructor(message: string, status: number, errors: ApiValidationErrors | null = null) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.errors = errors;
  }

  /** Pesan error field pertama, dipakai untuk toast singkat. */
  get firstFieldError(): string | null {
    if (!this.errors) return null;
    const first = Object.values(this.errors)[0];
    return first?.[0] ?? null;
  }
}

interface RequestOptions {
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE" | undefined;
  body?: unknown;
  query?: Record<string, string | number | undefined> | undefined;
  /** Lewati lampiran Authorization header (dipakai untuk /auth/login). */
  skipAuth?: boolean | undefined;
}

function buildUrl(path: string, query?: RequestOptions["query"]): string {
  const url = new URL(`${API_BASE_URL}/${path.replace(/^\/+/, "")}`);
  if (query) {
    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined) url.searchParams.set(key, String(value));
    }
  }
  return url.toString();
}

async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = "GET", body, query, skipAuth } = options;

  const headers: Record<string, string> = {
    Accept: "application/json",
  };
  if (body !== undefined) headers["Content-Type"] = "application/json";

  if (!skipAuth) {
    const token = getToken();
    if (token) headers["Authorization"] = `Bearer ${token}`;
  }

  const response = await fetch(buildUrl(path, query), {
    method,
    headers,
    ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
  });

  const isJson = response.headers.get("content-type")?.includes("application/json");
  const payload = isJson ? await response.json().catch(() => null) : null;

  if (!response.ok) {
    if (response.status === 401 && !skipAuth) {
      clearToken();
      unauthorizedHandler?.();
    }

    const message: string = payload?.message ?? `Permintaan gagal (${response.status}).`;
    throw new ApiError(message, response.status, payload?.errors ?? null);
  }

  return payload as T;
}

/**
 * Mengunduh file dari endpoint export.
 *
 * Tidak lewat request() biasa karena responsnya bukan JSON melainkan file
 * (CSV/XLSX/PDF), dan token Sanctum harus tetap dilampirkan — jadi tidak bisa
 * sekadar mengarahkan browser ke URL-nya.
 */
async function unduh(path: string, query?: RequestOptions["query"]): Promise<void> {
  const headers: Record<string, string> = {};
  const token = getToken();
  if (token) headers["Authorization"] = `Bearer ${token}`;

  const response = await fetch(buildUrl(path, query), { method: "GET", headers });

  if (!response.ok) {
    if (response.status === 401) {
      clearToken();
      unauthorizedHandler?.();
    }

    // Endpoint export tetap mengirim error dalam bentuk JSON.
    const payload = await response.json().catch(() => null);
    throw new ApiError(
      payload?.message ?? `Gagal mengunduh laporan (${response.status}).`,
      response.status,
      payload?.errors ?? null,
    );
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");

  link.href = url;
  link.download = namaFileDariHeader(response) ?? "laporan";
  document.body.appendChild(link);
  link.click();
  link.remove();

  // Ditunda sebentar: Safari membatalkan unduhan bila URL langsung dicabut.
  window.setTimeout(() => URL.revokeObjectURL(url), 10_000);
}

/** Mengambil nama file dari header Content-Disposition yang dikirim backend. */
function namaFileDariHeader(response: Response): string | null {
  const disposition = response.headers.get("content-disposition");
  if (!disposition) return null;

  const utf8 = /filename\*=UTF-8''([^;]+)/i.exec(disposition);
  if (utf8?.[1]) return decodeURIComponent(utf8[1]);

  const biasa = /filename="?([^";]+)"?/i.exec(disposition);
  return biasa?.[1] ?? null;
}

export const apiClient = {
  get: <T>(path: string, query?: RequestOptions["query"]) =>
    request<T>(path, { method: "GET", query }),
  unduh,
  post: <T>(path: string, body?: unknown, options: Omit<RequestOptions, "method" | "body"> = {}) =>
    request<T>(path, { ...options, method: "POST", body }),
  put: <T>(path: string, body?: unknown) => request<T>(path, { method: "PUT", body }),
  patch: <T>(path: string, body?: unknown) => request<T>(path, { method: "PATCH", body }),
  delete: <T>(path: string, body?: unknown) => request<T>(path, { method: "DELETE", body }),
};
