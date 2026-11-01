import Echo from "laravel-echo";
import Pusher from "pusher-js";

let echoInstance: Echo<"reverb"> | null = null;

/**
 * Returns the shared Laravel Echo client, configured for Laravel Reverb.
 * Only create this on the client (browser) — never during SSR.
 */
export function getEcho(): Echo<"reverb"> {
  if (echoInstance) {
    return echoInstance;
  }

  const appKey = process.env.NEXT_PUBLIC_REVERB_APP_KEY ?? "deskmateskey";
  const host = process.env.NEXT_PUBLIC_REVERB_HOST ?? "localhost";
  const port = process.env.NEXT_PUBLIC_REVERB_PORT ?? "8080";
  const scheme = process.env.NEXT_PUBLIC_REVERB_SCHEME ?? "http";

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  (globalThis as any).Pusher = Pusher;

  echoInstance = new Echo<"reverb">({
    broadcaster: "reverb",
    key: appKey,
    wsHost: host,
    wsPort: Number(port),
    wssPort: Number(port),
    forceTLS: scheme === "secure",
    enabledTransports: ["ws", "wss"],
    disableStats: true,
  });

  return echoInstance;
}
