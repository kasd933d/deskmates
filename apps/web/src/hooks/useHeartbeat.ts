"use client";

import { useEffect, useRef } from "react";
import { api, ApiError, ensureCsrfCookie } from "@/lib/api";
import type { MemberStatus } from "@/types";

const HEARTBEAT_INTERVAL_MS = 20_000;

/**
 * Client-side spacing between heartbeats: the server allows 4 per minute
 * (PRD 6), so rapid Page Visibility flips must not burst past it.
 */
const MIN_GAP_MS = 15_000;

export type HeartbeatHandlers = {
  /** Display status computed by the server for this beat. */
  onStatus: (status: MemberStatus) => void;
  /** The server re-created our membership (we were reaped while asleep). */
  onRejoined: () => void;
  /** One-room rule: membership now lives in another room — stop beating. */
  onKicked: () => void;
  /** No longer welcome here (archived / private without invite) — stop. */
  onGone: () => void;
};

/**
 * PRD 4.4: heartbeat every 20 seconds carrying the Page Visibility flag;
 * also fires immediately when visibility flips so the 2-minute "away"
 * clock starts and clears promptly. Runs only while `active` (membership
 * held and not explicitly stopped).
 */
export function useHeartbeat(
  roomId: number | null,
  active: boolean,
  handlers: HeartbeatHandlers,
): void {
  const handlersRef = useRef(handlers);
  const lastSentAtRef = useRef(0);
  const stoppedRef = useRef(false);

  // Keep the latest handlers without re-subscribing the interval.
  useEffect(() => {
    handlersRef.current = handlers;
  });

  useEffect(() => {
    if (!active || roomId === null) {
      return;
    }

    stoppedRef.current = false;

    const send = async (): Promise<void> => {
      if (stoppedRef.current) return;

      const now = Date.now();
      if (now - lastSentAtRef.current < MIN_GAP_MS) return;
      lastSentAtRef.current = now;

      try {
        const data = await api<{ status: MemberStatus; rejoined: boolean }>(
          `/api/v1/rooms/${roomId}/heartbeat`,
          {
            method: "POST",
            body: { visible: document.visibilityState === "visible" },
          },
        );

        handlersRef.current.onStatus(data.status);
        if (data.rejoined) handlersRef.current.onRejoined();
      } catch (err) {
        if (!(err instanceof ApiError)) {
          return; // network blip: the next tick retries
        }

        if (err.status === 409) {
          stoppedRef.current = true;
          handlersRef.current.onKicked();
        } else if (err.status === 401 || err.status === 403 || err.status === 404) {
          stoppedRef.current = true;
          handlersRef.current.onGone();
        } else if (err.status === 419) {
          // Expired/missing CSRF cookie: refresh it so the next tick lands.
          void ensureCsrfCookie();
        }
        // 429 (limiter): keep the interval running; the window rolls over.
      }
    };

    void send(); // establish last_seen right away

    const interval = window.setInterval(() => void send(), HEARTBEAT_INTERVAL_MS);

    const onVisibilityChange = () => void send();
    document.addEventListener("visibilitychange", onVisibilityChange);

    return () => {
      window.clearInterval(interval);
      document.removeEventListener("visibilitychange", onVisibilityChange);
    };
  }, [roomId, active]);
}
