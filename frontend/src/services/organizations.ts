import { api } from "@/lib/api";
import type { Member, Organization, Role, Wrapped } from "@/types";

export const organizations = {
  list: () => api.get<{ data: Organization[] }>("/organizations"),
  create: (body: { name: string; default_currency?: string }) => api.post<Wrapped<Organization>>("/organizations", body),
  update: (id: number, body: { name?: string; default_currency?: string }) => api.patch<Wrapped<Organization>>(`/organizations/${id}`, body),
  members: (id: number) => api.get<{ data: Member[] }>(`/organizations/${id}/members`),
  addMember: (id: number, body: { email: string; role: Role }) => api.post<Wrapped<Member>>(`/organizations/${id}/members`, body),
  changeRole: (id: number, memberId: number, role: Role) => api.patch<Wrapped<Member>>(`/organizations/${id}/members/${memberId}`, { role }),
  removeMember: (id: number, memberId: number) => api.delete<void>(`/organizations/${id}/members/${memberId}`),
};
