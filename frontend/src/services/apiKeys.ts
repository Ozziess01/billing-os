import { api } from "@/lib/api";
import type { ApiKey } from "@/types";

export const apiKeys = {
  list: () => api.get<{ data: ApiKey[] }>("/api-keys"),
  create: (body: { name: string; expires_at?: string | null }) => api.post<{ data: ApiKey; plain_key: string }>("/api-keys", body),
  revoke: (id: string) => api.delete<void>(`/api-keys/${id}`),
};
