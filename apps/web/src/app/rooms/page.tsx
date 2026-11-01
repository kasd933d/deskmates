"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { useAuth } from "@/hooks/useAuth";
import { api, ApiError } from "@/lib/api";
import type { LobbyResponse, Room, RoomType } from "@/types";

const TYPE_LABELS: Record<RoomType, string> = {
  always_on: "Always-on",
  public: "Public",
  private: "Private",
};

function phaseLabel(phase: Room["phase"]): string {
  switch (phase) {
    case "focus":
      return "Focus";
    case "short_break":
      return "Short break";
    case "long_break":
      return "Long break";
    default:
      return "Idle";
  }
}

function RoomCard({ room, inviteCode }: { room: Room; inviteCode?: string }) {
  return (
    <li>
      <Link
        href={`/room/${room.slug}${inviteCode ? `?invite=${inviteCode}` : ""}`}
        className="flex items-center justify-between rounded-2xl border border-zinc-200 bg-white p-4 transition hover:border-zinc-400 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-600"
      >
        <div>
          <div className="flex items-center gap-2">
            <span className="font-semibold">{room.name}</span>
            <span className="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
              {TYPE_LABELS[room.type]}
            </span>
          </div>
          <div className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            {phaseLabel(room.phase)} · {room.focus_minutes}/{room.short_break_minutes} min ·{" "}
            {room.member_count ?? 0}/{room.max_members} members
          </div>
        </div>
        <span aria-hidden className="text-zinc-400">→</span>
      </Link>
    </li>
  );
}

export default function LobbyPage() {
  const { user, loading: authLoading } = useAuth();
  const router = useRouter();
  const [data, setData] = useState<LobbyResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (authLoading) return;
    if (!user) {
      router.push("/login");
      return;
    }
    let cancelled = false;
    api<LobbyResponse>("/api/v1/rooms")
      .then((result) => {
        if (!cancelled) setData(result);
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : "Failed to load the lobby.");
      });
    return () => {
      cancelled = true;
    };
  }, [user, authLoading, router]);

  if (authLoading || (!user && !error)) {
    return <p className="p-8 text-center text-zinc-500">Loading…</p>;
  }

  if (error) {
    return <p role="alert" className="p-8 text-center text-red-500">{error}</p>;
  }

  const alwaysOn = data?.rooms.filter((r) => r.type === "always_on") ?? [];
  const publicRooms = data?.rooms.filter((r) => r.type === "public") ?? [];
  const myRooms = data?.my_rooms ?? [];

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-8 px-4 py-10">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">Lobby</h1>
        <Link
          href="/rooms/new"
          className="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300"
        >
          + New room
        </Link>
      </div>

      <section>
        <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
          My rooms
        </h2>
        {myRooms.length === 0 ? (
          <p className="rounded-2xl border border-dashed border-zinc-300 p-6 text-center text-sm text-zinc-400 dark:border-zinc-700">
            You haven&apos;t joined any rooms yet. Drop into an always-on room below!
          </p>
        ) : (
          <ul className="flex flex-col gap-2">
            {myRooms.map((room) => (
              <RoomCard key={room.id} room={room} inviteCode={room.invite_code} />
            ))}
          </ul>
        )}
      </section>

      <section>
        <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
          Always-on rooms
        </h2>
        <ul className="flex flex-col gap-2">
          {alwaysOn.map((room) => (
            <RoomCard key={room.id} room={room} />
          ))}
        </ul>
      </section>

      <section>
        <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
          Public rooms
        </h2>
        {publicRooms.length === 0 ? (
          <p className="rounded-2xl border border-dashed border-zinc-300 p-6 text-center text-sm text-zinc-400 dark:border-zinc-700">
            No public rooms right now. Why not create one?
          </p>
        ) : (
          <ul className="flex flex-col gap-2">
            {publicRooms.map((room) => (
              <RoomCard key={room.id} room={room} />
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
