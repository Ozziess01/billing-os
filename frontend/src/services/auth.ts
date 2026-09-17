import { api } from "@/lib/api";
import type { Organization, User } from "@/types";

export interface AuthResponse {
  token: string;
  user: User;
  organizations: Organization[];
}

export const auth = {
  register: (body: { name: string; email: string; password: string; password_confirmation: string; organization?: string }) =>
    api.post<AuthResponse>("/auth/register", body),
  login: (body: { email: string; password: string }) => api.post<AuthResponse>("/auth/login", body),
  logout: () => api.post<void>("/auth/logout"),
  me: () => api.get<{ data: User; organizations: Organization[] }>("/auth/me"),
};
