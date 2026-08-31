import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import * as api from "@/lib/api/pengguna";

const keys = {
  list: (params: api.PenggunaListParams = {}) => ["pengguna", "list", params] as const,
  role: ["pengguna", "role"] as const,
};

export function usePenggunaList(params: api.PenggunaListParams = {}) {
  return useQuery({
    queryKey: keys.list(params),
    queryFn: () => api.getPenggunaList(params),
  });
}

/** Daftar role jarang berubah, jadi tidak perlu sering di-refetch. */
export function useDaftarRole() {
  return useQuery({
    queryKey: keys.role,
    queryFn: api.getDaftarRole,
    staleTime: 30 * 60 * 1000,
  });
}

export function useTambahPengguna() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: api.tambahPengguna,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["pengguna"] });
    },
  });
}

export function useUbahPengguna() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: api.PenggunaPayload }) =>
      api.ubahPengguna(id, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["pengguna"] });
    },
  });
}

export function useHapusPengguna() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: api.hapusPengguna,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["pengguna"] });
    },
  });
}
