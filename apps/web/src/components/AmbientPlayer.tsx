"use client";

import { useEffect, useRef, useState, useSyncExternalStore } from "react";
import {
  AMBIENT_CHOICES,
  ambient,
  subscribeAmbient,
  type AmbientKind,
} from "@/lib/ambient";

/**
 * PRD 4.9: the room header's ambient sound button. Opens a small popover
 * with the four generated scenes plus the volume slider; kind and volume
 * persist in localStorage (the engine owns both) and flow back through
 * useSyncExternalStore. Local-only by design — nothing here syncs between
 * devices or roommates.
 */
export function AmbientPlayer() {
  const [open, setOpen] = useState(false);
  // Server snapshot: "off"/0.5 matches the prerendered placeholder, then
  // React swaps in the stored values right after hydration.
  const kind = useSyncExternalStore(
    subscribeAmbient,
    () => ambient.current(),
    () => "off" as AmbientKind,
  );
  const volume = useSyncExternalStore(
    subscribeAmbient,
    () => ambient.currentVolume(),
    () => 0.5,
  );
  const panelRef = useRef<HTMLDivElement>(null);

  // Click-away closes the popover.
  useEffect(() => {
    if (!open) return;
    function onPointerDown(event: MouseEvent) {
      if (panelRef.current && !panelRef.current.contains(event.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener("mousedown", onPointerDown);
    return () => document.removeEventListener("mousedown", onPointerDown);
  }, [open]);

  function choose(next: AmbientKind) {
    // The engine persists and notifies subscribers; state follows the store.
    ambient.setKind(next);
    if (next === "off") setOpen(false);
  }

  return (
    <div ref={panelRef} className="relative">
      <button
        type="button"
        onClick={() => setOpen((prev) => !prev)}
        data-testid="ambient-toggle"
        aria-label="Ambient sound"
        aria-expanded={open}
        className="rounded-lg border border-zinc-300 px-2.5 py-1.5 text-sm hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
      >
        {kind === "off" ? "🎧" : "🔊"}
      </button>

      {open && (
        <div
          data-testid="ambient-panel"
          className="absolute right-0 z-20 mt-2 w-52 rounded-xl border border-zinc-200 bg-white p-3 shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
        >
          <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
            Ambient sound
          </p>

          <div role="radiogroup" aria-label="Ambient sound" className="flex flex-col gap-1">
            {AMBIENT_CHOICES.map((choice) => (
              <button
                key={choice.kind}
                type="button"
                role="radio"
                aria-checked={kind === choice.kind}
                data-testid={`ambient-option-${choice.kind}`}
                onClick={() => choose(choice.kind)}
                className={`rounded-lg px-2 py-1.5 text-left text-sm ${
                  kind === choice.kind
                    ? "bg-zinc-900 font-medium text-white dark:bg-zinc-100 dark:text-zinc-900"
                    : "hover:bg-zinc-100 dark:hover:bg-zinc-800"
                }`}
              >
                {choice.label}
              </button>
            ))}
          </div>

          <label className="mt-3 flex items-center gap-2 text-xs text-zinc-600 dark:text-zinc-300">
            Volume
            <input
              type="range"
              min={0}
              max={100}
              value={Math.round(volume * 100)}
              data-testid="ambient-volume"
              onChange={(event) => {
                ambient.setVolume(Number(event.target.value) / 100);
              }}
              className="flex-1"
            />
          </label>
        </div>
      )}
    </div>
  );
}
