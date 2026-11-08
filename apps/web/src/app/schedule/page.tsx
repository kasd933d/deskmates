"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState, type FormEvent } from "react";
import { useAuth } from "@/hooks/useAuth";
import { api, ApiError } from "@/lib/api";
import type {
  LobbyResponse,
  Room,
  ScheduleResponse,
  ScheduledSession,
  SessionConfig,
  SessionResponse,
} from "@/types";

/**
 * Milestone 6 (PRD 4.11): `/schedule` — every upcoming booking the viewer
 * can reach, rendered in their profile timezone, with RSVP toggles and the
 * host's booking form. Times cross the wire as UTC ISO-8601 and are only
 * localized for display (PRD 8: UTC in storage).
 */

/** Local wall-clock value for <input type="datetime-local"> (minute grid). */
function localInputValue(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function formatStart(iso: string, timezone?: string): string {
  const date = new Date(iso);
  try {
    return new Intl.DateTimeFormat(undefined, {
      weekday: "short",
      month: "short",
      day: "numeric",
      hour: "2-digit",
      minute: "2-digit",
      timeZone: timezone || undefined,
    }).format(date);
  } catch {
    // Unknown IANA zone in the profile — fall back to the browser's.
    return date.toLocaleString();
  }
}

function errorMessage(err: unknown, fallback: string): string {
  if (err instanceof ApiError) {
    const first = err.errors ? Object.values(err.errors)[0]?.[0] : undefined;
    return first ?? err.message;
  }
  return fallback;
}

/** The host's booking form; the four timer fields pre-fill per room (PRD 8). */
function CreateSessionForm({
  rooms,
  onCreated,
}: {
  rooms: Room[];
  onCreated: (session: ScheduledSession) => void;
}) {
  const [roomId, setRoomId] = useState(rooms[0] ? String(rooms[0].id) : "");
  const [title, setTitle] = useState("");
  const [startsAt, setStartsAt] = useState(() =>
    localInputValue(new Date(Date.now() + 5 * 60_000)),
  );
  const [config, setConfig] = useState<SessionConfig>(
    () =>
      rooms[0]
        ? {
            focus_minutes: rooms[0].focus_minutes,
            short_break_minutes: rooms[0].short_break_minutes,
            long_break_minutes: rooms[0].long_break_minutes,
            long_break_every: rooms[0].long_break_every,
          }
        : { focus_minutes: 25, short_break_minutes: 5, long_break_minutes: 15, long_break_every: 4 },
  );
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function pickRoom(id: string) {
    setRoomId(id);
    const room = rooms.find((r) => String(r.id) === id);
    if (room) {
      setConfig({
        focus_minutes: room.focus_minutes,
        short_break_minutes: room.short_break_minutes,
        long_break_minutes: room.long_break_minutes,
        long_break_every: room.long_break_every,
      });
    }
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setSaving(true);
    try {
      const data = await api<SessionResponse>("/api/v1/sessions", {
        method: "POST",
        csrf: true,
        body: {
          room_id: Number(roomId),
          title: title.trim(),
          // datetime-local is local wall time; the API stores UTC.
          starts_at: new Date(startsAt).toISOString(),
          focus_minutes: config.focus_minutes,
          short_break_minutes: config.short_break_minutes,
          long_break_minutes: config.long_break_minutes,
          long_break_every: config.long_break_every,
        },
      });
      setTitle("");
      onCreated(data.session);
    } catch (err) {
      setError(errorMessage(err, "Could not schedule the session."));
    } finally {
      setSaving(false);
    }
  }

  const numberField = (
    key: keyof SessionConfig,
    label: string,
    min: number,
    max: number,
  ) => (
    <label className="flex flex-col gap-1 text-xs font-medium">
      {label}
      <input
        type="number"
        required
        min={min}
        max={max}
        value={config[key]}
        onChange={(event) =>
          setConfig((prev) => ({ ...prev, [key]: Number(event.target.value) }))
        }
        data-testid={`session-${key}`}
        className="rounded-lg border border-zinc-300 bg-transparent px-2 py-1.5 font-normal outline-none focus:border-zinc-500 dark:border-zinc-700"
      />
    </label>
  );

  return (
    <form
      onSubmit={handleSubmit}
      data-testid="schedule-form"
      className="flex flex-col gap-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900"
    >
      <h2 className="text-sm font-semibold">Schedule a session</h2>

      {error && (
        <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600 dark:bg-red-950/50 dark:text-red-400">
          {error}
        </p>
      )}

      <label className="flex flex-col gap-1 text-sm font-medium">
        Room
        <select
          value={roomId}
          onChange={(event) => pickRoom(event.target.value)}
          data-testid="session-room"
          className="rounded-lg border border-zinc-300 bg-transparent px-3 py-2 font-normal outline-none focus:border-zinc-500 dark:border-zinc-700"
        >
          {rooms.map((room) => (
            <option key={room.id} value={room.id}>
              {room.name}
            </option>
          ))}
        </select>
      </label>

      <label className="flex flex-col gap-1 text-sm font-medium">
        Title
        <input
          type="text"
          required
          minLength={2}
          maxLength={80}
          value={title}
          onChange={(event) => setTitle(event.target.value)}
          placeholder="Morning sprint"
          data-testid="session-title"
          className="rounded-lg border border-zinc-300 bg-transparent px-3 py-2 font-normal outline-none focus:border-zinc-500 dark:border-zinc-700"
        />
      </label>

      <label className="flex flex-col gap-1 text-sm font-medium">
        Starts at
        <input
          type="datetime-local"
          required
          value={startsAt}
          min={localInputValue(new Date())}
          onChange={(event) => setStartsAt(event.target.value)}
          data-testid="session-starts-at"
          className="rounded-lg border border-zinc-300 bg-transparent px-3 py-2 font-normal outline-none focus:border-zinc-500 dark:border-zinc-700"
        />
      </label>

      <fieldset className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <legend className="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
          Timer config
        </legend>
        {numberField("focus_minutes", "Focus (min)", 10, 90)}
        {numberField("short_break_minutes", "Short break (min)", 3, 30)}
        {numberField("long_break_minutes", "Long break (min)", 10, 45)}
        {numberField("long_break_every", "Long break every", 2, 6)}
      </fieldset>

      <button
        type="submit"
        disabled={saving}
        data-testid="session-submit"
        className="rounded-lg bg-zinc-900 py-2 text-sm font-semibold text-white hover:bg-zinc-700 disabled:opacity-50 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300"
      >
        {saving ? "Scheduling…" : "Schedule session"}
      </button>

      <p className="text-xs text-zinc-500 dark:text-zinc-400">
        The room timer starts by itself at this time; RSVP&apos;d members get a push 10 minutes before.
      </p>
    </form>
  );
}

export default function SchedulePage() {
  const { user, loading: authLoading } = useAuth();
  const router = useRouter();
  const [sessions, setSessions] = useState<ScheduledSession[]>([]);
  const [hostedRooms, setHostedRooms] = useState<Room[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  // Auth gate + initial load (same shape as the lobby page: setState only
  // lands in the promise callbacks, never synchronously in the effect).
  useEffect(() => {
    if (authLoading) return;
    if (!user) {
      router.push("/login");
      return;
    }

    let cancelled = false;
    Promise.all([
      api<ScheduleResponse>("/api/v1/sessions"),
      api<LobbyResponse>("/api/v1/rooms"),
    ])
      .then(([schedule, lobby]) => {
        if (cancelled) return;
        setSessions(schedule.sessions);
        // Bookable rooms: hosted by me, never always-on (their clock is
        // deterministic — RoomPolicy::update rejects bookings).
        setHostedRooms(
          lobby.my_rooms.filter((room) => room.is_host && room.type !== "always_on"),
        );
        setLoading(false);
      })
      .catch((err) => {
        if (cancelled) return;
        setError(errorMessage(err, "Could not load the schedule."));
        setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [user, authLoading, router]);

  async function toggleRsvp(session: ScheduledSession) {
    setError(null);
    try {
      const data = await api<SessionResponse>(
        `/api/v1/sessions/${session.id}/rsvp`,
        { method: session.has_rsvp ? "DELETE" : "POST", csrf: true },
      );
      setSessions((prev) =>
        prev.map((item) => (item.id === session.id ? data.session : item)),
      );
    } catch (err) {
      setError(errorMessage(err, "Could not update your RSVP."));
    }
  }

  async function cancelSession(session: ScheduledSession) {
    setError(null);
    try {
      await api<SessionResponse>(`/api/v1/sessions/${session.id}`, {
        method: "DELETE",
        csrf: true,
      });
      setSessions((prev) => prev.filter((item) => item.id !== session.id));
      setNotice(`Cancelled “${session.title}”.`);
    } catch (err) {
      setError(errorMessage(err, "Could not cancel the session."));
    }
  }

  if (authLoading || !user) {
    return <p className="p-8 text-center text-zinc-500">Loading…</p>;
  }

  const byStart = (a: ScheduledSession, b: ScheduledSession) =>
    a.starts_at.localeCompare(b.starts_at);

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-12">
      <div>
        <h1 className="text-2xl font-bold">Schedule</h1>
        <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
          Upcoming sessions in your timezone ({user.timezone || "browser time"}).
        </p>
      </div>

      {error && (
        <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600 dark:bg-red-950/50 dark:text-red-400">
          {error}
        </p>
      )}
      {notice && (
        <p className="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-600 dark:bg-green-950/50 dark:text-green-400">
          {notice}
        </p>
      )}

      {hostedRooms.length > 0 ? (
        <CreateSessionForm
          rooms={hostedRooms}
          onCreated={(session) => {
            setSessions((prev) => [...prev, session].sort(byStart));
            setNotice(`Scheduled “${session.title}”.`);
          }}
        />
      ) : (
        <p className="rounded-2xl border border-dashed border-zinc-300 p-4 text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
          Host a room to schedule sessions in it —{" "}
          <Link href="/rooms/new" className="font-medium underline">
            create one
          </Link>
          .
        </p>
      )}

      <section className="flex flex-col gap-3">
        <h2 className="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
          Upcoming sessions
        </h2>

        {loading ? (
          <p className="text-sm text-zinc-500">Loading…</p>
        ) : sessions.length === 0 ? (
          <p data-testid="schedule-empty" className="rounded-2xl border border-dashed border-zinc-300 p-4 text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
            No upcoming sessions yet.
          </p>
        ) : (
          [...sessions].sort(byStart).map((session) => {
            const canCancel =
              session.host?.id === user.id || user.role === "admin";

            return (
              <article
                key={session.id}
                data-testid="session-row"
                className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900"
              >
                <div className="min-w-0">
                  <h3 className="truncate font-semibold">{session.title}</h3>
                  <p className="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                    {session.room && (
                      <Link
                        href={`/room/${session.room.slug}`}
                        className="font-medium underline-offset-2 hover:underline"
                      >
                        {session.room.name}
                      </Link>
                    )}
                    {" · "}
                    {formatStart(session.starts_at, user.timezone)}
                  </p>
                  <p className="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">
                    {session.host?.name ?? "Unknown host"} hosts ·{" "}
                    <span data-testid="rsvp-count">{session.rsvp_count}</span> going ·{" "}
                    {session.config.focus_minutes}/{session.config.short_break_minutes} min
                  </p>
                </div>

                <div className="flex shrink-0 items-center gap-2">
                  <button
                    type="button"
                    onClick={() => void toggleRsvp(session)}
                    data-testid={`rsvp-${session.id}`}
                    aria-pressed={session.has_rsvp}
                    className={`rounded-lg px-3 py-1.5 text-sm font-semibold ${
                      session.has_rsvp
                        ? "bg-emerald-600 text-white hover:bg-emerald-500"
                        : "border border-zinc-300 hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
                    }`}
                  >
                    {session.has_rsvp ? "Going ✓" : "RSVP"}
                  </button>
                  {canCancel && (
                    <button
                      type="button"
                      onClick={() => void cancelSession(session)}
                      data-testid={`cancel-${session.id}`}
                      className="rounded-lg px-3 py-1.5 text-sm text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                    >
                      Cancel
                    </button>
                  )}
                </div>
              </article>
            );
          })
        )}
      </section>
    </div>
  );
}
