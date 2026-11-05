import type { ReactionEmoji } from "@/types";

/** PRD 4.7: the five quick reactions that float up the screen. */
export const REACTION_EMOJIS: readonly ReactionEmoji[] = ["👏", "🔥", "💪", "☕", "🎉"];

export function isReactionEmoji(value: unknown): value is ReactionEmoji {
  return typeof value === "string" && (REACTION_EMOJIS as readonly string[]).includes(value);
}

/** How long a float stays on screen — matches the CSS keyframe duration. */
export const FLOAT_DURATION_MS = 2_600;

/** PRD 4.7: outgoing reactions are throttled to 5 per second, client-side. */
export const OUTBOUND_LIMIT = 5;
export const OUTBOUND_WINDOW_MS = 1_000;

/**
 * PRD 4.7: incoming bursts above a limit are ignored to protect the UI.
 * 10 per second is well past what a human sends and still lets a whole
 * room cheer at once without flooding the screen.
 */
export const INCOMING_LIMIT = 10;
export const INCOMING_WINDOW_MS = 1_000;

/**
 * A sliding-window gate: allow at most `limit` events per `windowMs`.
 * Used for both the outbound 5/s throttle and the inbound burst filter.
 */
export function createBurstGate(limit: number, windowMs: number): () => boolean {
  let recent: number[] = [];
  return () => {
    const now = Date.now();
    recent = recent.filter((t) => now - t < windowMs);
    if (recent.length >= limit) return false;
    recent.push(now);
    return true;
  };
}
