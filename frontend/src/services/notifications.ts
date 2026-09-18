import { api, query } from "@/lib/api";
import type { AppNotification, NotificationPreference } from "@/types";

export const notifications = {
  list: (params: { page?: number } = {}) =>
    api.get<{ data: AppNotification[]; meta: { current_page: number; last_page: number; total: number; unread: number } }>(`/notifications${query(params)}`),
  read: (id: string) => api.post<{ read_at: string }>(`/notifications/${id}/read`),
  readAll: () => api.post<void>("/notifications/read-all"),
  preferences: () => api.get<{ data: NotificationPreference[] }>("/notifications/preferences"),
  updatePreferences: (preferences: Pick<NotificationPreference, "event" | "in_app" | "mail">[]) =>
    api.patch<{ data: NotificationPreference[] }>("/notifications/preferences", { preferences }),
};
