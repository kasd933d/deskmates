"use client";

import { useEffect, useMemo, useState } from "react";
import { formatCountdown, serverClock } from "@/lib/clock";
import type { TimerState } from "@/types";

const PHASE_LABELS: Record<TimerState["phase"], string> = {
  idle: "Waiting to start",
  focus: "Focus",
  short_break: "Short break",
  long_break: "Long break",
};

/**
 * The big room countdown. Renders from server time only: it re-renders
 * every 250 ms from `phase_ends_at_ms - (Date.now() + offset)`, so all
 * clients agree within clock resolution.
 *
 * Accessibility (PRD 12): the visible number is aria-hidden and updates
 * every tick; the aria-live region only changes text at phase switches and
 * minute milestones, never every second.
 */
export function Timer({
  timer,
  onResync,
}: {
  timer: TimerState;
  onResync?: () => void;
}) {
  const [, setTick] = useState(0);

  // Purely visual re-render cadence; the shared clock handles re-syncs.
  useEffect(() => {
    const id = setInterval(() => setTick((t) => t + 1), 250);
    return () => clearInterval(id);
  }, []);

  // Re-render immediately when the offset updates (visibility, reconnect).
  useEffect(() => serverClock.subscribe(() => setTick((t) => t + 1)), []);

  const remaining = serverClock.remainingMs(timer.phase_ends_at_ms);
  const serverNow = serverClock.serverNowMs();

  // Bucket the remaining time to whole minutes so the announcement text
  // changes once per minute (and on phase change), not every tick.
  const minuteBucket = remaining === null ? null : Math.ceil(remaining / 60000);

  const announcement = useMemo(() => {
    if (timer.phase === "idle") {
      return "Timer is idle";
    }
    const label = PHASE_LABELS[timer.phase];
    if (minuteBucket === null) {
      return `${label} started`;
    }
    if (minuteBucket <= 0) {
      return `${label} ended`;
    }
    return `${label}, ${minuteBucket} minute${minuteBucket === 1 ? "" : "s"} remaining`;
  }, [timer.phase, minuteBucket]);

  const displayed = remaining === null ? "--:--" : formatCountdown(remaining);

  return (
    <div className="flex flex-col items-center gap-3">
      <span
        data-testid="timer-phase"
        className={`rounded-full px-3 py-1 text-sm font-semibold ${
          timer.phase === "focus"
            ? "bg-emerald-500/15 text-emerald-600 dark:text-emerald-400"
            : timer.phase === "idle"
              ? "bg-zinc-500/15 text-zinc-500 dark:text-zinc-400"
              : "bg-amber-500/15 text-amber-600 dark:text-amber-400"
        }`}
      >
        {PHASE_LABELS[timer.phase]}
      </span>

      <span
        data-testid="timer-countdown"
        className="font-mono text-7xl font-bold tracking-tight tabular-nums sm:text-8xl"
        aria-hidden
      >
        {displayed}
      </span>

      {!serverClock.isSynced() && (
        <button
          onClick={() => onResync?.()}
          className="text-xs text-amber-600 underline dark:text-amber-400"
        >
          Clock not synced — tap to retry
        </button>
      )}

      {serverNow !== null && (
        <span className="text-xs text-zinc-400" aria-hidden>
          server time {new Date(serverNow).toLocaleTimeString()}
        </span>
      )}

      {/* Phase changes and minute milestones only — never per second. */}
      <span role="status" aria-live="polite" className="sr-only">
        {announcement}
      </span>
    </div>
  );
}
