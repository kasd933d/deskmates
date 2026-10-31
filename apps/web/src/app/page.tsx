"use client";

import { useEffect, useRef, useState } from "react";
import { getEcho } from "@/lib/echo";

const API_BASE_URL = process.env.NEXT_PUBLIC_API_BASE_URL ?? "http://localhost:8000";

type PingEvent = {
  id: number;
  message: string;
  client_time_ms: number;
  server_now_ms: number;
  receivedAt: number;
  roundTripMs: number | null;
};

type ConnectionState = "connecting" | "connected" | "disconnected";

export default function Home() {
  const [connection, setConnection] = useState<ConnectionState>("connecting");
  const [pings, setPings] = useState<PingEvent[]>([]);
  const [sending, setSending] = useState(false);
  const nextId = useRef(0);

  useEffect(() => {
    const echo = getEcho();
    let pingTimer: ReturnType<typeof setInterval> | null = null;

    const channel = echo
      .channel("ping")
      .subscribed(() => setConnection("connected"))
      .error(() => setConnection("disconnected"));

    channel.listen("PingReceived", (payload: Omit<PingEvent, "id" | "receivedAt" | "roundTripMs">) => {
      const receivedAt = Date.now();
      setPings((prev) =>
        [
          {
            id: nextId.current++,
            message: payload.message,
            client_time_ms: payload.client_time_ms,
            server_now_ms: payload.server_now_ms,
            receivedAt,
            roundTripMs: payload.client_time_ms > 0 ? receivedAt - payload.client_time_ms : null,
          },
          ...prev,
        ].slice(0, 20),
      );
    });

    // Reverb connection state hooks (pusher-js based).
    const pusher = echo.connector.pusher as unknown as {
      connection: { state: string; bind: (e: string, cb: () => void) => void };
    };
    const updateState = () => {
      const state = pusher.connection.state;
      setConnection(state === "connected" ? "connected" : state === "connecting" ? "connecting" : "disconnected");
    };
    pusher.connection.bind("state_change", updateState);

    pingTimer = setInterval(() => {
      void sendPing(false);
    }, 5000);

    return () => {
      if (pingTimer) clearInterval(pingTimer);
      pusher.connection.unbind("state_change", updateState);
      echo.leave("ping");
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function sendPing(manual: boolean) {
    setSending(true);
    try {
      const clientTimeMs = Date.now();
      await fetch(`${API_BASE_URL}/api/v1/ping`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ client_time_ms: clientTimeMs }),
      });
    } catch {
      if (manual) setConnection("disconnected");
    } finally {
      setSending(false);
    }
  }

  const statusStyles: Record<ConnectionState, string> = {
    connected: "bg-emerald-500/15 text-emerald-600 dark:text-emerald-400",
    connecting: "bg-amber-500/15 text-amber-600 dark:text-amber-400",
    disconnected: "bg-red-500/15 text-red-600 dark:text-red-400",
  };

  return (
    <main className="flex min-h-screen flex-col items-center justify-center gap-8 bg-zinc-50 p-8 text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100">
      <div className="text-center">
        <h1 className="text-4xl font-bold tracking-tight">DeskMates</h1>
        <p className="mt-2 text-zinc-500 dark:text-zinc-400">
          Realtime proof: a message broadcast from Laravel appears here live.
        </p>
      </div>

      <div className="flex items-center gap-3">
        <span
          className={`inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-medium ${statusStyles[connection]}`}
          role="status"
          aria-live="polite"
        >
          <span
            className={`h-2 w-2 rounded-full ${
              connection === "connected"
                ? "bg-emerald-500"
                : connection === "connecting"
                  ? "bg-amber-500"
                  : "bg-red-500"
            }`}
            aria-hidden
          />
          {connection === "connected"
            ? "Live — subscribed to ping channel"
            : connection === "connecting"
              ? "Connecting to Reverb…"
              : "Disconnected"}
        </span>
        <button
          onClick={() => sendPing(true)}
          disabled={sending}
          className="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-zinc-700 disabled:opacity-50 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300"
        >
          {sending ? "Sending…" : "Send ping"}
        </button>
      </div>

      <section className="w-full max-w-2xl">
        <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
          Broadcasts received ({pings.length})
        </h2>
        {pings.length === 0 ? (
          <p className="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-zinc-400 dark:border-zinc-700 dark:text-zinc-500">
            No pings yet — click &quot;Send ping&quot; or wait for the auto ping.
          </p>
        ) : (
          <ul className="space-y-2">
            {pings.map((ping) => (
              <li
                key={ping.id}
                className="flex items-center justify-between rounded-xl border border-zinc-200 bg-white px-4 py-3 text-sm dark:border-zinc-800 dark:bg-zinc-900"
              >
                <span>{ping.message}</span>
                <span className="font-mono text-xs text-zinc-500 dark:text-zinc-400">
                  server {new Date(ping.server_now_ms).toLocaleTimeString()}
                  {ping.roundTripMs !== null && ` · RTT ${ping.roundTripMs} ms`}
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>
    </main>
  );
}
