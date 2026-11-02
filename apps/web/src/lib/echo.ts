import Echo from "laravel-echo";
import Pusher from "pusher-js";
import { api } from "@/lib/api";

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
    // Private/presence channels authorize through the API with the SPA's
    // cookies + XSRF token (pusher-js 8: customHandler replaces the old
    // cross-origin authEndpoint flow, which cannot carry either).
    channelAuthorization: {
      customHandler: ({ channelName, socketId }, callback) => {
        api<{ auth: string; channel_data?: string }>("/broadcasting/auth", {
          method: "POST",
          body: { channel_name: channelName, socket_id: socketId },
          csrf: true,
        })
          .then((data) => callback(null, data))
          .catch((err: unknown) =>
            callback(
              err instanceof Error ? err : new Error("Channel authorization failed."),
              null,
            ),
          );
      },
    },
  });

  return echoInstance;
}
