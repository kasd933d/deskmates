"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useMemo, useState, type FormEvent } from "react";
import { useAuth } from "@/hooks/useAuth";
import { ApiError } from "@/lib/api";

const EMOJIS = ["🧑‍💻", "📚", "🧠", "☕", "🌙", "🔥", "🌿", "⚡", "🦉", "🐱", "🐶", "🌱", "💻", "📝", "🎧", "😴"];
const COLORS = ["#ef4444", "#f97316", "#eab308", "#22c55e", "#14b8a6", "#06b6d4", "#3b82f6", "#8b5cf6", "#ec4899", "#64748b"];

export default function RegisterPage() {
  const { register } = useAuth();
  const router = useRouter();
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [emoji, setEmoji] = useState(EMOJIS[0]);
  const [color, setColor] = useState(COLORS[6]);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  const nameError = useMemo(() => {
    if (name.length === 0) return null;
    if (name.length < 2 || name.length > 24) return "Display name must be 2–24 characters.";
    return null;
  }, [name]);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setFieldErrors({});
    setSubmitting(true);
    try {
      await register({
        name,
        email,
        password,
        password_confirmation: password,
        avatar_emoji: emoji,
        avatar_color: color,
      });
      router.push("/rooms");
    } catch (err) {
      if (err instanceof ApiError) {
        setError(err.message);
        setFieldErrors(err.errors ?? {});
      } else {
        setError("Something went wrong. Try again.");
      }
      setSubmitting(false);
    }
  }

  return (
    <div className="mx-auto flex max-w-md flex-col gap-6 px-4 py-16">
      <div className="text-center">
        <h1 className="text-2xl font-bold">Create your account</h1>
        <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
          Pick a display name and avatar — they show up next to you in rooms.
        </p>
      </div>

      <form onSubmit={handleSubmit} className="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
        {error && (
          <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600 dark:bg-red-950/50 dark:text-red-400">
            {error}
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
            placeholder="e.g. Sana"
            className="rounded-lg border border-zinc-300 bg-transparent px-3 py-2 font-normal outline-none focus:border-zinc-500 dark:border-zinc-700"
          />
          {nameError && <span className="text-xs font-normal text-red-500">{nameError}</span>}
          {fieldErrors.name && <span className="text-xs font-normal text-red-500">{fieldErrors.name[0]}</span>}
        </label>

        <label className="flex flex-col gap-1 text-sm font-medium">
          Email
          <input
            type="email"
            required
            autoComplete="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            className="rounded-lg border border-zinc-300 bg-transparent px-3 py-2 font-normal outline-none focus:border-zinc-500 dark:border-zinc-700"
          />
          {fieldErrors.email && <span className="text-xs font-normal text-red-500">{fieldErrors.email[0]}</span>}
        </label>

        <label className="flex flex-col gap-1 text-sm font-medium">
          Password
          <input
            type="password"
            required
            minLength={8}
            autoComplete="new-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            className="rounded-lg border border-zinc-300 bg-transparent px-3 py-2 font-normal outline-none focus:border-zinc-500 dark:border-zinc-700"
          />
          {fieldErrors.password && <span className="text-xs font-normal text-red-500">{fieldErrors.password[0]}</span>}
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
            {name || "Your name"} — looking good!
          </span>
        </div>

        <button
          type="submit"
          disabled={submitting || nameError !== null}
          className="rounded-lg bg-zinc-900 py-2 font-semibold text-white hover:bg-zinc-700 disabled:opacity-50 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300"
        >
          {submitting ? "Creating account…" : "Sign up"}
        </button>
      </form>

      <p className="text-center text-sm text-zinc-500 dark:text-zinc-400">
        Already have an account?{" "}
        <Link href="/login" className="font-medium text-zinc-900 underline dark:text-zinc-100">
          Log in
        </Link>
      </p>
    </div>
  );
}
