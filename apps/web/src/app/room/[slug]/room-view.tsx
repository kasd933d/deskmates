"use client";

import { useParams, useRouter } from "next/navigation";
import { useCallback, useEffect, useRef, useState } from "react";
import { useAuth } from "@/hooks/useAuth";
import { api, ApiError } from "@/lib/api";
import { getEcho } from "@/lib/echo";
import { serverClock } from "@/lib/clock";
import { Timer } from "@/components/room/Timer";
import type {
  Room,
  RoomPhaseChangedEvent,
  RoomStateResponse,
  RoomType,
  TimerAction,
  TimerActionResponse,
  TimerState,
} from "@/types";

const TYPE_LABELS: Record<RoomType, string> = {
  always_on: "Always-on",
  public: "Public",
  private: "Private",
};

/**
 * The room view: fully client-driven (session-gated, live-synced timer).
 * Rendered inside a <Suspense> boundary by page.tsx so Next 16 can
 * prerender the loading shell without tripping instant-navigation
 * validation on `useParams()`.
 */
export function RoomView() {
  const params = useParams<{ slug: string }>();
  const slug = params.slug;
  const { user, loading: authLoading } = useAuth();
  const router = useRouter();
  const [room, setRoom] = useState<Room | null>(null);
  const [timer, setTimer] = useState<TimerState | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [inviteCode, setInviteCode] = useState("");
  const [joining, setJoining] = useState(false);
  const [acting, setActing] = useState(false);
  const clockSubscribed = useRef(false);

  const loadRoom = useCallback(async () => {
    try {
      const data = await api<RoomStateResponse>(`/api/v1/rooms/${slug}`);
      setRoom(data.room);
      setTimer(data.timer);
      setError(null);
    } catch (err) {
      if (err instanceof ApiError) {
        setError(err.message);
      } else {
        setError("Failed to load the room.");
      }
    }
  }, [slug]);

  useEffect(() => {
    if (authLoading) return;
    if (!user) {
      router.push("/login");
      return;
    }
    let cancelled = false;
    api<RoomStateResponse>(`/api/v1/rooms/${slug}`)
      .then((data) => {
        if (!cancelled) {
          setRoom(data.room);
          setTimer(data.timer);
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

  // Clock sync: start as soon as we know we are on this page.
  useEffect(() => {
    if (!clockSubscribed.current) {
      clockSubscribed.current = true;
      void serverClock.sync();
    }
  }, []);

  // Live phase changes: subscribe to the room broadcast channel.
  useEffect(() => {
    if (!room) return;

    const echo = getEcho();
    echo
      .channel(`room.${room.id}`)
      .listen("RoomPhaseChanged", (payload: RoomPhaseChangedEvent) => {
        setTimer({
          phase: payload.phase,
          phase_started_at_ms: payload.phase_started_at_ms,
          phase_ends_at_ms: payload.phase_ends_at_ms,
          cycle_index: payload.cycle_index,
          phase_version: payload.phase_version ?? undefined,
        });
      });

    // Re-fetch full state on reconnect (PRD: Echo reconnect -> re-sync).
    const pusher = echo.connector.pusher as unknown as {
      connection: {
        bind: (e: string, cb: () => void) => void;
        unbind: (e: string, cb: () => void) => void;
      };
    };
    const onReconnect = () => {
      void serverClock.sync().then(() => loadRoom());
    };
    pusher.connection.bind("connected", onReconnect);

    return () => {
      pusher.connection.unbind("connected", onReconnect);
      echo.leave(`room.${room.id}`);
    };
  }, [room?.id, loadRoom]); // eslint-disable-line react-hooks/exhaustive-deps

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
      await loadRoom();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Failed to join the room.");
    } finally {
      setJoining(false);
    }
  }

  async function handleTimerAction(action: TimerAction) {
    if (!room) return;
    setActing(true);
    try {
      const data = await api<TimerActionResponse>(`/api/v1/rooms/${room.id}/timer/${action}`, {
        method: "POST",
        csrf: true,
      });
      setTimer(data.timer);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Timer action failed.");
    } finally {
      setActing(false);
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

  if (!room || !timer) {
    return <p className="p-8 text-center text-zinc-500">Loading…</p>;
  }

  const isHost = room.is_host;
  const showHostControls = isHost && room.type !== "always_on";

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

      <div className="rounded-2xl border border-zinc-200 bg-white py-10 dark:border-zinc-800 dark:bg-zinc-900">
        <Timer timer={timer} onResync={() => void serverClock.sync()} />
      </div>

      {showHostControls && (
        <div className="flex flex-wrap justify-center gap-2">
          {timer.phase === "idle" ? (
            <button
              onClick={() => handleTimerAction("start")}
              disabled={acting}
              className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50"
            >
              ▶ Start focus
            </button>
          ) : (
            <>
              <button
                onClick={() => handleTimerAction("skip")}
                disabled={acting}
                className="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700 disabled:opacity-50 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300"
              >
                ⏭ Skip phase
              </button>
              <button
                onClick={() => handleTimerAction("pause")}
                disabled={acting}
                className="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold hover:bg-zinc-100 disabled:opacity-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
              >
                ⏸ Pause
              </button>
            </>
          )}
          <button
            onClick={() => handleTimerAction("reset")}
            disabled={acting || timer.phase === "idle"}
            className="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-500 hover:bg-zinc-100 disabled:opacity-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
          >
            ↺ Reset
          </button>
        </div>
      )}

      <div className="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
        <div className="grid grid-cols-2 gap-4 text-sm">
          <div>
            <span className="block text-zinc-400">Members</span>
            <span className="font-semibold">{room.member_count ?? 1} / {room.max_members}</span>
          </div>
          <div>
            <span className="block text-zinc-400">Cycle</span>
            <span className="font-semibold">
              {timer.cycle_index + 1} / {room.long_break_every} → long break
            </span>
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

      {room.type === "private" && !isHost && (
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
