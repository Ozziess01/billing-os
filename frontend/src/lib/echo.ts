import Echo from "laravel-echo";
import Pusher from "pusher-js";
import { session } from "@/lib/api";

declare global {
  interface Window {
    Pusher: typeof Pusher;
  }
}

const KEY = process.env.NEXT_PUBLIC_REVERB_KEY ?? "billingos-key";
const HOST = process.env.NEXT_PUBLIC_WS_HOST ?? "localhost";
const PORT = Number(process.env.NEXT_PUBLIC_WS_PORT ?? 8090);
const TLS = process.env.NEXT_PUBLIC_WS_TLS === "true";
const AUTH = (process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8090/api/v1").replace(/\/v1$/, "") + "/broadcasting/auth";

let instance: Echo<"reverb"> | null = null;

// один сокет на вкладку; создаётся при первом обращении и переживает навигацию
export function echo(): Echo<"reverb"> {
  if (instance) return instance;

  window.Pusher = Pusher;
  instance = new Echo({
    broadcaster: "reverb",
    key: KEY,
    wsHost: HOST,
    wsPort: PORT,
    wssPort: PORT,
    forceTLS: TLS,
    enabledTransports: ["ws", "wss"],
    authEndpoint: AUTH,
    auth: { headers: { Authorization: `Bearer ${session.token() ?? ""}`, Accept: "application/json" } },
  });

  return instance;
}

export function disconnectEcho() {
  instance?.disconnect();
  instance = null;
}
