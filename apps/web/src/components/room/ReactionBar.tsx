"use client";

import { useMemo, useRef } from "react";
import {
  OUTBOUND_LIMIT,
  OUTBOUND_WINDOW_MS,
  REACTION_EMOJIS,
  createBurstGate,
} from "@/lib/reactions";
import type { FloatingReaction, ReactionEmoji } from "@/types";

type Props = {
  /** Members only — whispers ride the presence channel you must join. */
  enabled: boolean;
  /** Why reactions are off (not a member), or null. */
  disabledReason: string | null;
  onReact: (emoji: ReactionEmoji) => void;
  /** Reactions currently floating up the screen (own + everyone else's). */
  floats: FloatingReaction[];
};

/**
 * PRD 4.7: the quick-reaction bar. Outbound sends pass a 5/s client
 * throttle; floats rise along the viewport with the emoji that caused
 * them. Under `prefers-reduced-motion` the float animation is replaced by
 * a small per-emoji counter next to each button (CSS picks, no JS media
 * query needed).
 */
export function ReactionBar({ enabled, disabledReason, onReact, floats }: Props) {
  const gateRef = useRef(createBurstGate(OUTBOUND_LIMIT, OUTBOUND_WINDOW_MS));

  // Active floats per emoji — the reduced-motion counter.
  const counts = useMemo(() => {
    const map = new Map<ReactionEmoji, number>();
    for (const f of floats) map.set(f.emoji, (map.get(f.emoji) ?? 0) + 1);
    return map;
  }, [floats]);

  function handleReact(emoji: ReactionEmoji) {
    if (!enabled) return;
    if (!gateRef.current()) return; // PRD 4.7: 5 per second, silently dropped
    onReact(emoji);
  }

  return (
    <>
      <div
        role="group"
        aria-label="Quick reactions"
        className="flex flex-wrap items-center gap-1"
      >
        {REACTION_EMOJIS.map((emoji) => {
          const count = counts.get(emoji) ?? 0;
          return (
            <button
              key={emoji}
              type="button"
              disabled={!enabled}
              title={enabled ? `React with ${emoji}` : (disabledReason ?? "Reactions unavailable")}
              aria-label={`React with ${emoji}`}
              data-testid={`reaction-${emoji}`}
              onClick={() => handleReact(emoji)}
              className="relative rounded-lg px-3 py-1.5 text-xl hover:bg-zinc-100 disabled:cursor-not-allowed disabled:opacity-50 dark:hover:bg-zinc-800"
            >
              <span aria-hidden>{emoji}</span>
              {count > 0 && (
                <span
                  aria-hidden
                  className="motion-safe:hidden absolute -right-1 -top-1 min-w-4 rounded-full bg-zinc-900 px-1 text-center text-[10px] leading-4 font-semibold text-white dark:bg-zinc-100 dark:text-zinc-900"
                >
                  {count}
                </span>
              )}
            </button>
          );
        })}
      </div>

      {/* Float layer: fixed so reactions rise over the whole page. */}
      <div
        aria-hidden
        data-testid="reaction-floats"
        className="pointer-events-none fixed inset-0 z-40 overflow-hidden"
      >
        {floats.map((f) => (
          <span
            key={f.id}
            className="absolute bottom-32 hidden text-3xl drop-shadow-sm motion-safe:block motion-safe:animate-reaction-float"
            style={{ left: `${f.x}%` }}
          >
            {f.emoji}
          </span>
        ))}
      </div>
    </>
  );
}
