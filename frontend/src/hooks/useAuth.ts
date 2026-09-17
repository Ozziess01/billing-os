"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useSyncExternalStore } from "react";
import { session } from "@/lib/api";
import { auth, type AuthResponse } from "@/services/auth";
import type { Organization } from "@/types";

export function useUser() {
  return useQuery({
    queryKey: ["me"],
    queryFn: auth.me,
    enabled: !!session.token(),
    staleTime: 60_000,
  });
}

// выбранная организация живёт в localStorage; подписчики - все хуки useOrganization на странице
const listeners = new Set<() => void>();
const organizationStore = {
  get: () => session.organization(),
  server: () => null,
  set(id: string | null) {
    session.setOrganization(id);
    listeners.forEach((listener) => listener());
  },
  subscribe(listener: () => void) {
    listeners.add(listener);
    return () => listeners.delete(listener);
  },
};

const none: Organization[] = [];

/** Выбранная организация: из localStorage, иначе первая из списка пользователя. */
export function useOrganization() {
  const { data } = useUser();
  const organizations = data?.organizations ?? none;
  const selected = useSyncExternalStore(organizationStore.subscribe, organizationStore.get, organizationStore.server);
  const client = useQueryClient();

  const current: Organization | undefined = organizations.find((o) => String(o.id) === selected);
  const ready = organizations.length === 0 || current !== undefined;

  // сохранённой организации нет среди доступных - берём первую
  useEffect(() => {
    if (organizations.length > 0 && !organizations.some((o) => String(o.id) === selected)) {
      organizationStore.set(String(organizations[0].id));
    }
  }, [organizations, selected]);

  const select = useCallback(
    (id: number) => {
      organizationStore.set(String(id));
      // все данные были для другой организации
      client.removeQueries({ predicate: (q) => q.queryKey[0] !== "me" });
    },
    [client],
  );

  return { organizations, current, ready, select };
}

function useAuthenticated() {
  const client = useQueryClient();
  const router = useRouter();

  return (response: AuthResponse) => {
    session.setToken(response.token);
    organizationStore.set(response.organizations[0] ? String(response.organizations[0].id) : null);
    client.clear();
    router.replace("/dashboard");
  };
}

export function useLogin() {
  const done = useAuthenticated();
  return useMutation({ mutationFn: auth.login, onSuccess: done });
}

export function useRegister() {
  const done = useAuthenticated();
  return useMutation({ mutationFn: auth.register, onSuccess: done });
}

export function useLogout() {
  const client = useQueryClient();
  const router = useRouter();

  return useMutation({
    mutationFn: auth.logout,
    onSettled: () => {
      session.setToken(null);
      organizationStore.set(null);
      client.clear();
      router.replace("/login");
    },
  });
}
