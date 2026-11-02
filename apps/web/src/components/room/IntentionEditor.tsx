"use client";

import { useState } from "react";

type Props = {
  /** The intention the server currently has for this user. */
  value: string | null;
  /** Called with the trimmed text ("" means clear). */
  onSave: (intention: string) => void;
};

/**
 * PRD 4.5: one-line intention (max 80 chars), visible to the room. Saved on
 * Enter or blur. While unfocused the field mirrors the server value (so
 * broadcasts from our other tabs show through); while focused it keeps a
 * local draft that typing never clobbers.
 */
export function IntentionEditor({ value, onSave }: Props) {
  const [draft, setDraft] = useState("");
  const [focused, setFocused] = useState(false);

  const shown = focused ? draft : (value ?? "");

  const commit = () => {
    const trimmed = shown.trim();
    setFocused(false);
    if (trimmed !== (value ?? "").trim()) onSave(trimmed);
  };

  return (
    <div className="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
      <label
        htmlFor="intention"
        className="block text-xs font-medium text-zinc-500 dark:text-zinc-400"
      >
        Your intention <span className="text-zinc-400">(visible to the room)</span>
      </label>
      <div className="mt-2 flex items-center gap-3">
        <input
          id="intention"
          type="text"
          maxLength={80}
          value={shown}
          placeholder="What are you working on?"
          onChange={(e) => setDraft(e.target.value)}
          onFocus={() => {
            setDraft(value ?? "");
            setFocused(true);
          }}
          onBlur={commit}
          onKeyDown={(e) => {
            if (e.key === "Enter") {
              e.preventDefault();
              commit();
            }
          }}
          className="min-w-0 flex-1 rounded-lg border border-zinc-300 bg-transparent px-3 py-2 text-sm outline-none focus:border-zinc-500 dark:border-zinc-700"
        />
        <span aria-hidden className="text-xs tabular-nums text-zinc-400">
          {shown.length}/80
        </span>
      </div>
    </div>
  );
}
