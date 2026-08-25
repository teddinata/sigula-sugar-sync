import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import * as api from "@/lib/api/pengepul";

/**
 * Hooks React Query untuk master data Pengepul. `usePengepulList` dipakai ulang
 * sebagai sumber dropdown perantara di modul Pembelian.
 */

const keys = {
  list: (params: api.PengepulListParams = {}) => ["pengepul", "list", params] as const,
};

export function usePengepulList(params: api.PengepulListParams = {}) {
  return useQuery({
    queryKey: keys.list(params),
    queryFn: () => api.getPengepulList(params),
  });
}

export function useTambahPengepul() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: api.tambahPengepul,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["pengepul"] });
    },
  });
}

export function useUbahPengepul() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: api.PengepulPayload }) =>
      api.ubahPengepul(id, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["pengepul"] });
    },
  });
}

export function useHapusPengepul() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: api.hapusPengepul,
    onSuccess: () => {
      // Pembelian ikut di-refresh: nama pengepul tampil di daftar transaksi.
      queryClient.invalidateQueries({ queryKey: ["pengepul"] });
      queryClient.invalidateQueries({ queryKey: ["pembelian"] });
    },
  });
}
