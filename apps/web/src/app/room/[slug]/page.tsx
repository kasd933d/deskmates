"use client";

import { useParams, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { useAuth } from "@/hooks/useAuth";
import { api, ApiError } from "@/lib/api";
import type { Room, RoomType } from "@/types";

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

export default function RoomPage() {
  const params = useParams<{ slug: string }>();
  const slug = params.slug;
  const { user, loading: authLoading } = useAuth();
  const router = useRouter();
  const [room, setRoom] = useState<Room | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [inviteCode, setInviteCode] = useState("");
  const [joining, setJoining] = useState(false);

  useEffect(() => {
    if (authLoading) return;
    if (!user) {
      router.push("/login");
      return;
    }
    let cancelled = false;
    api<{ room: Room }>(`/api/v1/rooms/${slug}`)
      .then((data) => {
        if (!cancelled) {
          setRoom(data.room);
          setError(null);
        }
      })
      .catch((err) => {
        if (!cancelled) {
          setError(err instanceof ApiError ? err.message : "Failed to load the room.");
        }
      });
    return () => {
      cancelled = true;
    };
  }, [user, authLoading, router, slug]);

  async function handleJoin() {
    if (!room) return;
    setJoining(true);
    setError(null);
    try {
      const data = await api<{ room: Room }>(`/api/v1/rooms/${room.id}/join`, {
        method: "POST",
        body: room.type === "private" ? { invite_code: inviteCode } : {},
        csrf: true,
      });
      setRoom(data.room);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Failed to join the room.");
    } finally {
      setJoining(false);
    }
  }

  if (authLoading || (!user && !error)) {
    return <p className="p-8 text-center text-zinc-500">Loading…</p>;
  }

  if (error && !room) {
    return (
      <div className="mx-auto flex max-w-md flex-col gap-4 px-4 py-16 text-center">
        <p role="alert" className="text-red-500">{error}</p>
        <button
          onClick={() => router.push("/rooms")}
          className="text-sm font-medium text-zinc-600 underline dark:text-zinc-400"
        >
          Back to lobby
        </button>
      </div>
    );
  }

  if (!room) {
    return <p className="p-8 text-center text-zinc-500">Loading…</p>;
  }

  const isMember = room.is_host || (user && room.member_count !== undefined);

  return (
    <div className="mx-auto flex max-w-2xl flex-col gap-6 px-4 py-10">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">{room.name}</h1>
          <div className="mt-1 flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400">
            <span className="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium dark:bg-zinc-800">
              {TYPE_LABELS[room.type]}
            </span>
            <span>{room.focus_minutes} / {room.short_break_minutes} min cycles</span>
          </div>
        </div>
        <span className="text-4xl" aria-hidden>⏳</span>
      </div>

      <div className="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
        <div className="grid grid-cols-2 gap-4 text-sm">
          <div>
            <span className="block text-zinc-400">Current phase</span>
            <span className="font-semibold">{phaseLabel(room.phase)}</span>
          </div>
          <div>
            <span className="block text-zinc-400">Members</span>
            <span className="font-semibold">{room.member_count ?? 1} / {room.max_members}</span>
          </div>
          <div>
            <span className="block text-zinc-400">Focus</span>
            <span className="font-semibold">{room.focus_minutes} min</span>
          </div>
          <div>
            <span className="block text-zinc-400">Breaks</span>
            <span className="font-semibold">
              {room.short_break_minutes} / {room.long_break_minutes} min
            </span>
          </div>
        </div>
      </div>

      {error && (
        <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600 dark:bg-red-950/50 dark:text-red-400">
          {error}
        </p>
      )}

      {room.type === "private" && !isMember && (
        <div className="flex flex-col gap-3 rounded-2xl border border-dashed border-zinc-300 p-6 dark:border-zinc-700">
          <p className="text-sm text-zinc-600 dark:text-zinc-300">
            This room is private. Enter the invite code to join.
          </p>
          <div className="flex gap-3">
            <input
              type="text"
              value={inviteCode}
              onChange={(e) => setInviteCode(e.target.value.toUpperCase())}
              placeholder="Invite code"
              className="flex-1 rounded-lg border border-zinc-300 bg-transparent px-3 py-2 uppercase tracking-widest outline-none focus:border-zinc-500 dark:border-zinc-700"
            />
            <button
              onClick={handleJoin}
              disabled={joining || inviteCode.length === 0}
              className="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700 disabled:opacity-50 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300"
            >
              {joining ? "Joining…" : "Join"}
            </button>
          </div>
        </div>
      )}

      <button
        onClick={() => router.push("/rooms")}
        className="self-start text-sm font-medium text-zinc-600 underline dark:text-zinc-400"
      >
        ← Back to lobby
      </button>
    </div>
  );
}
