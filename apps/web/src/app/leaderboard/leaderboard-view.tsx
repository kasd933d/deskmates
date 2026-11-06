"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";
import { useAuth } from "@/hooks/useAuth";
import { api, ApiError } from "@/lib/api";
import type { LeaderboardResponse, LobbyResponse, Room } from "@/types";

/** "1h 5m" / "45m" — the only unit format this page uses. */
function fmtMinutes(minutes: number): string {
  if (minutes < 60) return `${minutes}m`;
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return m === 0 ? `${h}h` : `${h}h ${m}m`;
}

function Avatar({
  emoji,
  color,
  anonymous,
  name,
}: {
  emoji: string | null;
  color: string | null;
  anonymous: boolean;
  name: string;
}) {
  if (anonymous) {
    return (
      <span
        aria-hidden
        className="flex h-8 w-8 items-center justify-center rounded-full bg-zinc-200 text-sm dark:bg-zinc-700"
      >
        🕵️
      </span>
    );
  }
  return (
    <span
      aria-hidden
      className="flex h-8 w-8 items-center justify-center rounded-full text-sm"
      style={{ backgroundColor: color ?? "#e4e4e7" }}
    >
      {emoji ?? name.charAt(0).toUpperCase()}
    </span>
  );
}

export function LeaderboardView() {
  const { user, loading: authLoading } = useAuth();
  const searchParams = useSearchParams();
  const roomParam = searchParams.get("room");

  const [data, setData] = useState<LeaderboardResponse | null>(null);
  const [myRooms, setMyRooms] = useState<Room[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    const query = roomParam ? `?room=${encodeURIComponent(roomParam)}` : "";
    api<LeaderboardResponse>(`/api/v1/leaderboard${query}`)
      .then((result) => {
        if (!cancelled) {
          setData(result);
          setError(null);
        }
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : "Failed to load the leaderboard.");
      });
    return () => {
      cancelled = true;
    };
  }, [roomParam]);

  // Room scope chips come from the lobby payload (authed only; guests just
  // see the global board).
  useEffect(() => {
    if (authLoading || !user) return;
    let cancelled = false;
    api<LobbyResponse>("/api/v1/rooms")
      .then((result) => {
        if (!cancelled) setMyRooms(result.my_rooms);
      })
      .catch(() => {
        // Chips are a convenience — the board itself still loads.
      });
    return () => {
      cancelled = true;
    };
  }, [user, authLoading]);

  const activeRoomName =
    roomParam !== null ? (myRooms.find((r) => r.id === Number(roomParam))?.name ?? null) : null;

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-10">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">
            {activeRoomName ? `${activeRoomName} leaderboard` : "Weekly leaderboard"}
          </h1>
          <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            Top 50 by credited focus minutes · week of {data?.week_start ?? "…"}
          </p>
        </div>
        {user && (
          <Link href="/me" className="text-sm font-medium text-zinc-600 underline dark:text-zinc-400">
            My stats →
          </Link>
        )}
      </div>

      {user && !user.show_on_leaderboard && (
        <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
          You&apos;re opted out of the leaderboard — your minutes still count, but you appear as
          &quot;Anonymous focuser&quot;.{" "}
          <Link href="/settings" className="font-medium underline">
            Change in settings
          </Link>
        </p>
      )}

      <div className="flex flex-wrap gap-2">
        <Link
          href="/leaderboard"
          className={`rounded-full px-3 py-1 text-sm font-medium ${
            roomParam === null
              ? "bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900"
              : "bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700"
          }`}
        >
          Global
        </Link>
        {myRooms.map((room) => (
          <Link
            key={room.id}
            href={`/leaderboard?room=${room.id}`}
            className={`rounded-full px-3 py-1 text-sm font-medium ${
              roomParam !== null && Number(roomParam) === room.id
                ? "bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900"
                : "bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700"
            }`}
          >
            {room.name}
          </Link>
        ))}
      </div>

      {error && <p role="alert" className="text-sm text-red-500">{error}</p>}

      {!error && !data && <p className="text-center text-zinc-500">Loading…</p>}

      {data && data.entries.length === 0 && (
        <p className="rounded-2xl border border-dashed border-zinc-300 p-6 text-center text-sm text-zinc-400 dark:border-zinc-700">
          No credited focus minutes here yet this week.
          {roomParam !== null && " Try the global board or another room."}
        </p>
      )}

      {data && data.entries.length > 0 && (
        <ol className="flex flex-col divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-800 dark:bg-zinc-900">
          {data.entries.map((entry) => (
            <li
              key={`${entry.rank}:${entry.user_id ?? `anon-${entry.rank}`}`}
              className={`flex items-center gap-3 px-4 py-3 ${
                entry.is_me ? "bg-emerald-50/60 dark:bg-emerald-950/20" : ""
              }`}
            >
              <span
                className={`w-8 text-center text-sm font-semibold ${
                  entry.rank <= 3 ? "text-zinc-900 dark:text-zinc-100" : "text-zinc-400 dark:text-zinc-500"
                }`}
                aria-label={`Rank ${entry.rank}`}
              >
                {entry.rank}
              </span>
              <Avatar
                emoji={entry.avatar_emoji}
                color={entry.avatar_color}
                anonymous={entry.anonymous}
                name={entry.name}
              />
              <span className="flex-1 truncate font-medium">
                {entry.name}
                {entry.is_me && (
                  <span className="ml-2 text-xs font-normal text-emerald-600 dark:text-emerald-400">you</span>
                )}
              </span>
              <span className="font-semibold tabular-nums">{fmtMinutes(entry.minutes)}</span>
            </li>
          ))}
        </ol>
      )}

      {!authLoading && !user && (
        <p className="text-center text-sm text-zinc-500 dark:text-zinc-400">
          <Link href="/login" className="font-medium underline">
            Log in
          </Link>{" "}
          to see where you place — and to set your room scope.
        </p>
      )}
    </div>
  );
}
