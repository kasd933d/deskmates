"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState, type FormEvent } from "react";
import { useAuth } from "@/hooks/useAuth";
import { api, ApiError } from "@/lib/api";
import type { User } from "@/types";

const EMOJIS = ["🧑‍💻", "📚", "🧠", "☕", "🌙", "🔥", "🌿", "⚡", "🦉", "🐱", "🐶", "🌱", "💻", "📝", "🎧", "😴"];
const COLORS = ["#ef4444", "#f97316", "#eab308", "#22c55e", "#14b8a6", "#06b6d4", "#3b82f6", "#8b5cf6", "#ec4899", "#64748b"];

/**
 * The form is a child keyed by user id so its state initializes straight from
 * props — no effect that copies user into state (which React discourages).
 */
function ProfileForm({ user, onSaved }: { user: User; onSaved: (user: User) => void }) {
  const [name, setName] = useState(user.name);
  const [emoji, setEmoji] = useState(user.avatar_emoji);
  const [color, setColor] = useState(user.avatar_color);
  const [showOnLeaderboard, setShowOnLeaderboard] = useState(user.show_on_leaderboard);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setSaved(false);
    setSaving(true);
    try {
      const data = await api<{ user: User }>("/api/v1/me", {
        method: "PATCH",
        body: {
          name,
          avatar_emoji: emoji,
          avatar_color: color,
          show_on_leaderboard: showOnLeaderboard,
        },
        csrf: true,
      });
      onSaved(data.user);
      setSaved(true);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Failed to save settings.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
      {error && (
        <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600 dark:bg-red-950/50 dark:text-red-400">
          {error}
        </p>
      )}
      {saved && (
        <p className="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-600 dark:bg-green-950/50 dark:text-green-400">
          Settings saved.
        </p>
      )}

      <label className="flex flex-col gap-1 text-sm font-medium">
        Display name
        <input
          type="text"
          required
          minLength={2}
          maxLength={24}
          value={name}
          onChange={(e) => setName(e.target.value)}
          className="rounded-lg border border-zinc-300 bg-transparent px-3 py-2 font-normal outline-none focus:border-zinc-500 dark:border-zinc-700"
        />
      </label>

      <fieldset className="flex flex-col gap-2 text-sm font-medium">
        <legend className="mb-1">Avatar</legend>
        <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Avatar emoji">
          {EMOJIS.map((e) => (
            <button
              key={e}
              type="button"
              role="radio"
              aria-checked={emoji === e}
              onClick={() => setEmoji(e)}
              className={`rounded-lg p-2 text-xl ${emoji === e ? "bg-zinc-200 ring-2 ring-zinc-900 dark:bg-zinc-700 dark:ring-zinc-100" : "hover:bg-zinc-100 dark:hover:bg-zinc-800"}`}
            >
              {e}
            </button>
          ))}
        </div>
        <div className="mt-2 flex flex-wrap gap-2" role="radiogroup" aria-label="Avatar color">
          {COLORS.map((c) => (
            <button
              key={c}
              type="button"
              role="radio"
              aria-checked={color === c}
              onClick={() => setColor(c)}
              style={{ backgroundColor: c }}
              aria-label={`Color ${c}`}
              className={`h-8 w-8 rounded-full ${color === c ? "ring-2 ring-zinc-900 ring-offset-2 dark:ring-zinc-100 dark:ring-offset-zinc-900" : ""}`}
            />
          ))}
        </div>
      </fieldset>

      <div className="flex items-center gap-3 rounded-xl bg-zinc-100 p-3 dark:bg-zinc-800">
        <span
          className="flex h-11 w-11 items-center justify-center rounded-full text-2xl"
          style={{ backgroundColor: color }}
          aria-hidden
        >
          {emoji}
        </span>
        <span className="text-sm text-zinc-600 dark:text-zinc-300">
          {name || "Your name"}
        </span>
      </div>

      <label className="flex items-center gap-2 text-sm font-medium">
        <input
          type="checkbox"
          checked={showOnLeaderboard}
          onChange={(e) => setShowOnLeaderboard(e.target.checked)}
          className="h-4 w-4"
        />
        Show me on the leaderboard
      </label>

      <button
        type="submit"
        disabled={saving}
        className="rounded-lg bg-zinc-900 py-2 font-semibold text-white hover:bg-zinc-700 disabled:opacity-50 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300"
      >
        {saving ? "Saving…" : "Save settings"}
      </button>
    </form>
  );
}

export default function SettingsPage() {
  const { user, loading: authLoading, setUser } = useAuth();
  const router = useRouter();

  // router.push in an effect is fine — only synchronous setState is flagged.
  useEffect(() => {
    if (!authLoading && !user) {
      router.push("/login");
    }
  }, [user, authLoading, router]);

  if (authLoading || !user) {
    return <p className="p-8 text-center text-zinc-500">Loading…</p>;
  }

  return (
    <div className="mx-auto flex max-w-md flex-col gap-6 px-4 py-12">
      <div>
        <h1 className="text-2xl font-bold">Settings</h1>
        <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
          Update how you appear in rooms.
        </p>
      </div>

      <ProfileForm key={user.id} user={user} onSaved={setUser} />

      <p className="text-center text-sm text-zinc-500 dark:text-zinc-400">
        Signed in as {user.email}
      </p>
    </div>
  );
}
