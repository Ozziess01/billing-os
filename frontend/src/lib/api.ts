const BASE = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8090/api/v1";
const TOKEN_KEY = "billingos_token";
const ORG_KEY = "billingos_org";

export class ApiError extends Error {
  status: number;
  errors: Record<string, string[]>;

  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message);
    this.status = status;
    this.errors = errors;
  }

  // первая ошибка валидации по полю, чтобы подсветить инпут
  field(name: string): string | undefined {
    return this.errors[name]?.[0];
  }
}

function storage(): Storage | null {
  return typeof window === "undefined" ? null : window.localStorage;
}

export const session = {
  token: () => storage()?.getItem(TOKEN_KEY) ?? null,
  setToken(token: string | null) {
    if (token) storage()?.setItem(TOKEN_KEY, token);
    else storage()?.removeItem(TOKEN_KEY);
  },
  organization: () => storage()?.getItem(ORG_KEY) ?? null,
  setOrganization(id: string | null) {
    if (id) storage()?.setItem(ORG_KEY, id);
    else storage()?.removeItem(ORG_KEY);
  },
};

const PUBLIC_PATHS = ["/login", "/register"];

// токен протух или отозван: чистим сессию и уводим на вход
function handleUnauthorized() {
  session.setToken(null);
  if (typeof window === "undefined") return;
  if (PUBLIC_PATHS.includes(window.location.pathname)) return;
  window.location.replace("/login");
}

type Method = "GET" | "POST" | "PATCH" | "DELETE";

export interface RequestOptions {
  idempotencyKey?: string;
}

async function request<T>(method: Method, path: string, body?: unknown, options: RequestOptions = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: "application/json" };
  if (options.idempotencyKey) headers["Idempotency-Key"] = options.idempotencyKey;
  const token = session.token();
  const organization = session.organization();
  if (token) headers.Authorization = `Bearer ${token}`;
  if (organization) headers["X-Organization"] = organization;
  if (body !== undefined) headers["Content-Type"] = "application/json";

  const res = await fetch(`${BASE}${path}`, {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  });

  if (res.status === 204) return undefined as T;

  const data = await res.json().catch(() => ({}));

  if (!res.ok) {
    if (res.status === 401) handleUnauthorized();
    throw new ApiError(res.status, data.message ?? res.statusText, data.errors ?? {});
  }

  return data as T;
}

export const api = {
  get: <T>(path: string) => request<T>("GET", path),
  post: <T>(path: string, body?: unknown, options?: RequestOptions) => request<T>("POST", path, body, options),
  patch: <T>(path: string, body?: unknown) => request<T>("PATCH", path, body),
  delete: <T>(path: string) => request<T>("DELETE", path),
};

/** Ключ идемпотентности на одну попытку пользователя: повтор клика не создаст второй платёж. */
export function idempotencyKey(): string {
  return typeof crypto !== "undefined" && "randomUUID" in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

export function query(params: Record<string, string | number | boolean | undefined | null>): string {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === "") continue;
    search.set(key, String(value));
  }
  const s = search.toString();
  return s ? `?${s}` : "";
}
