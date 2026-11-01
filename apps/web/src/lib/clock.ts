/**
 * Server clock synchronization (PRD 4.3 "Client sync").
 *
 * offset = server_now_ms - client_now_ms, using the median of several round
 * trips to reject network noise. The room countdown renders from
 * phase_ends_at_ms - (Date.now() + offset) so every client shows the same
 * time regardless of local clock skew.
 */

const API_BASE_URL = process.env.NEXT_PUBLIC_API_BASE_URL ?? "http://localhost:8000";

/** Samples per sync; the median round trip wins. */
const SAMPLES = 3;

/** A sync older than this is considered stale. */
const STALE_AFTER_MS = 90_000;

/** Periodic re-sync interval (PRD: every 60 seconds). */
const RESYNC_INTERVAL_MS = 60_000;

export type ClockSample = {
  offsetMs: number;
  roundTripMs: number;
};

/**
 * Median offset by round-trip quality: we take the samples with the
 * smallest round-trip times, since a large RTT means the server time was
 * measured further from the exchange.
 */
export function medianOffset(samples: ClockSample[]): number | null {
  if (samples.length === 0) {
    return null;
  }

  const sorted = [...samples].sort((a, b) => a.roundTripMs - b.roundTripMs);
  const middle = sorted[Math.floor(sorted.length / 2)];

  return middle.offsetMs;
}

async function sampleOnce(): Promise<ClockSample> {
  const sentAt = Date.now();
  const response = await fetch(`${API_BASE_URL}/api/v1/time`, {
    headers: { Accept: "application/json" },
    cache: "no-store",
  });
  const receivedAt = Date.now();

  if (!response.ok) {
    throw new Error(`Clock sync failed: ${response.status}`);
  }

  const data = (await response.json()) as { server_now_ms: number };
  const roundTripMs = receivedAt - sentAt;

  return {
    // Midpoint estimate: server time as measured at the middle of the trip.
    offsetMs: data.server_now_ms + roundTripMs / 2 - receivedAt,
    roundTripMs,
  };
}

/**
 * Shared clock state. One instance per app; the room timer subscribes for
 * ticks and re-sync triggers.
 */
class ServerClock {
  private offsetMs: number | null = null;

  private syncedAt = 0;

  private syncing: Promise<number | null> | null = null;

  private listeners = new Set<() => void>();

  private visibilityHandler: (() => void) | null = null;

  private intervalId: ReturnType<typeof setInterval> | null = null;

  /**
   * Recompute the offset from fresh samples. Concurrent callers share one
   * in-flight sync. Resolves to the new offset (or null when offline).
   */
  sync(): Promise<number | null> {
    if (this.syncing) {
      return this.syncing;
    }

    this.syncing = (async () => {
      const samples: ClockSample[] = [];
      for (let i = 0; i < SAMPLES; i++) {
        try {
          samples.push(await sampleOnce());
        } catch {
          // Keep whatever samples succeeded; a single failure is fine.
        }
      }

      const offset = medianOffset(samples);
      if (offset !== null) {
        this.offsetMs = offset;
        this.syncedAt = Date.now();
      }

      this.notify();
      return offset;
    })().finally(() => {
      this.syncing = null;
    });

    return this.syncing;
  }

  /** Current offset in ms (server - client), or null before first sync. */
  getOffset(): number | null {
    return this.offsetMs;
  }

  /** True when a sync has happened and it is not yet stale. */
  isSynced(): boolean {
    return this.offsetMs !== null && Date.now() - this.syncedAt < STALE_AFTER_MS;
  }

  /** Server "now" in epoch ms, from the last offset. */
  serverNowMs(): number | null {
    return this.offsetMs === null ? null : Date.now() + this.offsetMs;
  }

  /** Milliseconds remaining until a server-side deadline (0 when past). */
  remainingMs(phaseEndsAtMs: number | null): number | null {
    if (phaseEndsAtMs === null || this.offsetMs === null) {
      return null;
    }
    return Math.max(0, phaseEndsAtMs - (Date.now() + this.offsetMs));
  }

  /** Subscribe to offset changes and periodic re-syncs. */
  subscribe(listener: () => void): () => void {
    this.listeners.add(listener);
    this.ensureLifecycle();

    return () => {
      this.listeners.delete(listener);
      if (this.listeners.size === 0) {
        this.teardownLifecycle();
      }
    };
  }

  private notify(): void {
    for (const listener of this.listeners) {
      listener();
    }
  }

  /**
   * Re-sync on tab visibility (PRD: recompute on resume from background)
   * and every 60 seconds while anyone is listening.
   */
  private ensureLifecycle(): void {
    if (this.intervalId !== null) {
      return;
    }

    this.visibilityHandler = () => {
      if (document.visibilityState === "visible") {
        void this.sync();
      }
    };
    document.addEventListener("visibilitychange", this.visibilityHandler);

    this.intervalId = setInterval(() => {
      void this.sync();
    }, RESYNC_INTERVAL_MS);
  }

  private teardownLifecycle(): void {
    if (this.visibilityHandler) {
      document.removeEventListener("visibilitychange", this.visibilityHandler);
      this.visibilityHandler = null;
    }
    if (this.intervalId !== null) {
      clearInterval(this.intervalId);
      this.intervalId = null;
    }
  }
}

export const serverClock = new ServerClock();

/** Format ms as M:SS (or H:MM:SS above an hour). */
export function formatCountdown(ms: number): string {
  const totalSeconds = Math.max(0, Math.ceil(ms / 1000));
  const hours = Math.floor(totalSeconds / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;
  const pad = (n: number) => String(n).padStart(2, "0");

  return hours > 0
    ? `${hours}:${pad(minutes)}:${pad(seconds)}`
    : `${minutes}:${pad(seconds)}`;
}
