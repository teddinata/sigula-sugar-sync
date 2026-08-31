import { apiClient } from "@/lib/api-client";
import type { RoleKode } from "@/lib/api/pengguna";

/**
 * Bentuk data persis seperti UserResource (backend/app/Http/Resources/UserResource.php)
 * dan folder "1. Autentikasi" di SIGULA.postman_collection.json.
 */
export interface AuthUser {
  id: string;
  nama: string;
  email: string;
  role: RoleKode;
  roleLabel: string;
  aktif: boolean;
  /** Kunci menu sidebar yang boleh diakses role ini, mis. ["dashboard", "petani", ...]. */
  menu: string[];
  abilities: string[];
  /** true bila baris ini akun yang sedang login (hanya relevan di menu Pengguna). */
  diriSendiri?: boolean;
  dibuatPada?: string | null;
}

export interface LoginPayload {
  email: string;
  password: string;
  /** Label token, opsional — default "sigula-web" di backend. */
  namaPerangkat?: string;
}

interface LoginResponse {
  message: string;
  data: {
    token: string;
    user: AuthUser;
  };
}

interface MeResponse {
  data: AuthUser;
}

export async function login(payload: LoginPayload): Promise<{ token: string; user: AuthUser }> {
  const res = await apiClient.post<LoginResponse>("auth/login", payload, { skipAuth: true });
  return res.data;
}

export async function me(): Promise<AuthUser> {
  const res = await apiClient.get<MeResponse>("auth/me");
  return res.data;
}

export async function logout(): Promise<void> {
  await apiClient.post("auth/logout");
}
