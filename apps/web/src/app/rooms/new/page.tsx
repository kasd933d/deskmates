"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState, type FormEvent } from "react";
import { useAuth } from "@/hooks/useAuth";
import { api, ApiError } from "@/lib/api";
import type { Room } from "@/types";

type FormState = {
  name: string;
  type: "public" | "private";
  focus_minutes: number;
  short_break_minutes: number;
  long_break_minutes: number;
  long_break_every: number;
  max_members: number;
};

const TYPE_MAX_MEMBERS: Record<FormState["type"], number> = {
  public: 25,
  private: 12,
};

const DEFAULTS: FormState = {
  name: "",
  type: "public",
  focus_minutes: 25,
  short_break_minutes: 5,
  long_break_minutes: 15,
  long_break_every: 4,
  max_members: 12,
};

function NumberField({
  label,
  hint,
  value,
  min,
  max,
  onChange,
}: {
  label: string;
  hint: string;
  value: number;
  min: number;
  max: number;
  onChange: (value: number) => void;
}) {
  return (
    <label className="flex flex-col gap-1 text-sm font-medium">
      {label}
      <input
        type="number"
        required
        min={min}
        max={max}
        value={value}
        onChange={(e) => onChange(Number(e.target.value))}
        className="rounded-lg border border-zinc-300 bg-transparent px-3 py-2 font-normal outline-none focus:border-zinc-500 dark:border-zinc-700"
      />
      <span className="text-xs font-normal text-zinc-400">{hint}</span>
    </label>
  );
}

export default function NewRoomPage() {
  const { user, loading: authLoading } = useAuth();
  const router = useRouter();
  const [form, setForm] = useState<FormState>(DEFAULTS);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!authLoading && !user) {
      router.push("/login");
    }
  }, [user, authLoading, router]);

  function update<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((prev) => ({ ...prev, [key]: value }));
  }

  function handleTypeChange(type: FormState["type"]) {
    setForm((prev) => ({
      ...prev,
      type,
      // Keep max_members inside the new type's limit.
      max_members: Math.min(prev.max_members, TYPE_MAX_MEMBERS[type]),
    }));
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setFieldErrors({});
    setSubmitting(true);
    try {
      const data = await api<{ room: Room }>("/api/v1/rooms", {
        method: "POST",
        body: form,
        csrf: true,
      });
      router.push(`/room/${data.room.slug}`);
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

  if (authLoading || !user) {
    return <p className="p-8 text-center text-zinc-500">Loading…</p>;
  }

  return (
    <div className="mx-auto flex max-w-md flex-col gap-6 px-4 py-12">
      <div>
        <h1 className="text-2xl font-bold">Create a room</h1>
        <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
          Set the shared timer everyone in the room follows.
        </p>
      </div>

      <form onSubmit={handleSubmit} className="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
        {error && (
          <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600 dark:bg-red-950/50 dark:text-red-400">
            {error}
          </p>
        )}

        <label className="flex flex-col gap-1 text-sm font-medium">
          Room name
          <input
            type="text"
            required
            minLength={2}
            maxLength={60}
            value={form.name}
            onChange={(e) => update("name", e.target.value)}
            placeholder="e.g. Exam Grind"
            className="rounded-lg border border-zinc-300 bg-transparent px-3 py-2 font-normal outline-none focus:border-zinc-500 dark:border-zinc-700"
          />
          {fieldErrors.name && <span className="text-xs font-normal text-red-500">{fieldErrors.name[0]}</span>}
        </label>

        <fieldset className="flex flex-col gap-2 text-sm font-medium">
          <legend className="mb-1">Room type</legend>
          <div className="flex gap-3">
            <button
              type="button"
              onClick={() => handleTypeChange("public")}
              className={`flex-1 rounded-xl border p-3 text-left ${
                form.type === "public"
                  ? "border-zinc-900 ring-2 ring-zinc-900 dark:border-zinc-100 dark:ring-zinc-100"
                  : "border-zinc-200 hover:border-zinc-400 dark:border-zinc-700"
              }`}
            >
              <span className="block font-semibold">Public</span>
              <span className="block text-xs font-normal text-zinc-500">
                Anyone in the lobby can join (up to 25).
              </span>
            </button>
            <button
              type="button"
              onClick={() => handleTypeChange("private")}
              className={`flex-1 rounded-xl border p-3 text-left ${
                form.type === "private"
                  ? "border-zinc-900 ring-2 ring-zinc-900 dark:border-zinc-100 dark:ring-zinc-100"
                  : "border-zinc-200 hover:border-zinc-400 dark:border-zinc-700"
              }`}
            >
              <span className="block font-semibold">Private</span>
              <span className="block text-xs font-normal text-zinc-500">
                Invite-code only (up to 12).
              </span>
            </button>
          </div>
          {fieldErrors.type && <span className="text-xs font-normal text-red-500">{fieldErrors.type[0]}</span>}
        </fieldset>

        <div className="grid grid-cols-2 gap-4">
          <NumberField
            label="Focus (min)"
            hint="10–90"
            value={form.focus_minutes}
            min={10}
            max={90}
            onChange={(v) => update("focus_minutes", v)}
          />
          <NumberField
            label="Short break (min)"
            hint="3–30"
            value={form.short_break_minutes}
            min={3}
            max={30}
            onChange={(v) => update("short_break_minutes", v)}
          />
          <NumberField
            label="Long break (min)"
            hint="10–45"
            value={form.long_break_minutes}
            min={10}
            max={45}
            onChange={(v) => update("long_break_minutes", v)}
          />
          <NumberField
            label="Long break every"
            hint="2–6 cycles"
            value={form.long_break_every}
            min={2}
            max={6}
            onChange={(v) => update("long_break_every", v)}
          />
          <NumberField
            label="Max members"
            hint={`2–${TYPE_MAX_MEMBERS[form.type]}`}
            value={form.max_members}
            min={2}
            max={TYPE_MAX_MEMBERS[form.type]}
            onChange={(v) => update("max_members", v)}
          />
        </div>

        {(fieldErrors.focus_minutes ||
          fieldErrors.short_break_minutes ||
          fieldErrors.long_break_minutes ||
          fieldErrors.long_break_every ||
          fieldErrors.max_members) && (
          <p className="text-xs text-red-500">
            Some timer values are outside the allowed ranges.
          </p>
        )}

        <button
          type="submit"
          disabled={submitting}
          className="rounded-lg bg-zinc-900 py-2 font-semibold text-white hover:bg-zinc-700 disabled:opacity-50 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300"
        >
          {submitting ? "Creating…" : "Create room"}
        </button>
      </form>
    </div>
  );
}
