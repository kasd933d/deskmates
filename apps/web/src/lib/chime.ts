"use client";

/**
 * Milestone 6 (PRD 4.10): the in-tab phase-change cue — a soft two-note
 * chime synthesized with the Web Audio API (no assets, no licensing).
 *
 * Policy: default ON, only audible on a visible tab (a background tab's
 * audio would be the push notification's job), and only the room view
 * decides *when* to call it — transitions into focus/break, never idle.
 */

const CHIME_KEY = "deskmates.chime";

/** The toggle's persisted state; anything but an explicit "0" is on. */
export function chimeEnabled(): boolean {
  try {
    return localStorage.getItem(CHIME_KEY) !== "0";
  } catch {
    return true;
  }
}

export function setChimeEnabled(enabled: boolean): void {
  try {
    localStorage.setItem(CHIME_KEY, enabled ? "1" : "0");
  } catch {
    // Storage unavailable — the cue stays at its default for this session.
  }
  for (const listener of listeners) {
    listener();
  }
}

const listeners = new Set<() => void>();

/** React-facing subscription for the toggle (useSyncExternalStore). */
export function subscribeChime(onStoreChange: () => void): () => void {
  listeners.add(onStoreChange);

  const onStorage = (event: StorageEvent) => {
    if (event.key === CHIME_KEY || event.key === null) {
      onStoreChange();
    }
  };
  window.addEventListener("storage", onStorage);

  return () => {
    listeners.delete(onStoreChange);
    window.removeEventListener("storage", onStorage);
  };
}

let context: AudioContext | null = null;

function audioContext(): AudioContext | null {
  try {
    context ??= new AudioContext();
    if (context.state === "suspended") {
      void context.resume();
    }
    return context;
  } catch {
    return null;
  }
}

/** Play the cue if the toggle allows it and the tab is actually visible. */
export function playChime(): void {
  if (!chimeEnabled()) return;
  if (typeof document !== "undefined" && document.visibilityState !== "visible") return;

  const ctx = audioContext();
  if (!ctx) return;

  try {
    const start = ctx.currentTime;
    // Two soft sine notes a fifth apart, gentle attack/decay each.
    [880, 1174.66].forEach((frequency, index) => {
      const at = start + index * 0.18;
      const oscillator = ctx.createOscillator();
      const gain = ctx.createGain();

      oscillator.type = "sine";
      oscillator.frequency.value = frequency;

      gain.gain.setValueAtTime(0.0001, at);
      gain.gain.exponentialRampToValueAtTime(0.14, at + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, at + 0.9);

      oscillator.connect(gain);
      gain.connect(ctx.destination);
      oscillator.start(at);
      oscillator.stop(at + 1);
    });
  } catch {
    // Audio refused (autoplay policy, no output device) — the cue is a
    // nicety; a failed chime must never break the room view.
  }
}
