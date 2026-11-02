"use client";

import { useParams, useRouter } from "next/navigation";
import { useCallback, useEffect, useRef, useState } from "react";
import { useAuth } from "@/hooks/useAuth";
import { useHeartbeat } from "@/hooks/useHeartbeat";
import { api, ApiError } from "@/lib/api";
import { getEcho } from "@/lib/echo";
import { serverClock } from "@/lib/clock";
import { IntentionEditor } from "@/components/room/IntentionEditor";
import { MemberGrid } from "@/components/room/MemberGrid";
import { Timer } from "@/components/room/Timer";
import type {
  IntentionUpdatedEvent,
  MemberRemovedEvent,
  MemberStatusChangedEvent,
  PresenceUserInfo,
  Room,
  RoomMemberCard,
  RoomPhaseChangedEvent,
  RoomSettingsUpdatedEvent,
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

/** Room config keys carried by the RoomSettingsUpdated broadcast. */
const ROOM_SETTING_KEYS = [
  "name",
  "theme",
  "focus_minutes",
  "short_break_minutes",
  "long_break_minutes",
  "long_break_every",
  "max_members",
  "silent_focus",
] as const;

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
  const [members, setMembers] = useState<RoomMemberCard[]>([]);
  const [notice, setNotice] = useState<string | null>(null);
  // Monotonic per room visit: once membership is held we keep heartbeating
  // so the server can self-heal a reaped membership (PRD 4.4) instead of the
  // client guessing from a stale grid.
  const [hasJoined, setHasJoined] = useState(false);
  // Set only by the server's verdict (409 kicked / 403 gone): stops the
  // heartbeat and the presence subscription.
  const [stopped, setStopped] = useState(false);
  const autoJoinTriedRef = useRef<string | null>(null);
  const refreshTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const clockSubscribed = useRef(false);

  const userId = user?.id ?? null;
  const isMember = userId !== null && members.some((m) => m.user_id === userId);
  const ownCard = userId !== null ? (members.find((m) => m.user_id === userId) ?? null) : null;

  const loadRoom = useCallback(async () => {
    try {
      const data = await api<RoomStateResponse>(`/api/v1/rooms/${slug}`);
      setRoom(data.room);
      setTimer(data.timer);
      setMembers(data.members);
      if (userId !== null) {
        setHasJoined((prev) => prev || data.members.some((m) => m.user_id === userId));
      }
      setError(null);
    } catch (err) {
      if (err instanceof ApiError) {
        setError(err.message);
      } else {
        setError("Failed to load the room.");
      }
    }
  }, [slug, userId]);

  // Coalesce bursty triggers (presence here/joining, settings updates) into
  // a single authoritative refetch.
  const scheduleRefresh = useCallback(() => {
    if (refreshTimerRef.current !== null) return;
    refreshTimerRef.current = setTimeout(() => {
      refreshTimerRef.current = null;
      void loadRoom();
    }, 150);
  }, [loadRoom]);

  useEffect(() => {
    return () => {
      if (refreshTimerRef.current !== null) clearTimeout(refreshTimerRef.current);
    };
  }, []);

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
          setMembers(data.members);
          setHasJoined(data.members.some((m) => m.user_id === user.id));
          setStopped(false);
          setNotice(null);
          autoJoinTriedRef.current = null;
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

  // M3: open rooms (public / always-on) admit anyone — join on arrival so
  // the presence channel and heartbeat have membership to work with.
  // Private rooms wait for an invite code; the ref makes this idempotent
  // across StrictMode double-effects and refetches.
  useEffect(() => {
    if (!room || !user || isMember || stopped) return;
    if (room.type === "private") return;

    const key = `${room.id}:${user.id}`;
    if (autoJoinTriedRef.current === key) return;
    autoJoinTriedRef.current = key;

    api<{ room: Room }>(`/api/v1/rooms/${room.id}/join`, {
      method: "POST",
      body: {},
      csrf: true,
    })
      .then(() => {
        setHasJoined(true);
        setStopped(false);
        return loadRoom();
      })
      .catch((err: unknown) => {
        // A transient failure must not latch the one-shot guard: the next
        // effect run (a refetch swaps `room`) gets to try again.
        autoJoinTriedRef.current = null;
        setError(err instanceof ApiError ? err.message : "Could not join this room.");
      });
  }, [room, user, isMember, stopped, loadRoom]);

  // Clock sync: start as soon as we know we are on this page.
  useEffect(() => {
    if (!clockSubscribed.current) {
      clockSubscribed.current = true;
      void serverClock.sync();
    }
  }, []);

  // PRD 4.4: authoritative presence — every 20 s with the visibility flag.
  useHeartbeat(room?.id ?? null, hasJoined && !stopped, {
    onStatus: (status) => {
      if (userId === null) return;
      setMembers((prev) =>
        prev.map((m) => (m.user_id === userId ? { ...m, status } : m)),
      );
    },
    onRejoined: () => {
      setNotice(null);
      void loadRoom();
    },
    onKicked: () => {
      setStopped(true);
      setNotice("You joined a different room in another tab.");
      if (userId !== null) {
        setMembers((prev) => prev.filter((m) => m.user_id !== userId));
      }
    },
    onGone: () => {
      setStopped(true);
      if (userId !== null) {
        setMembers((prev) => prev.filter((m) => m.user_id !== userId));
      }
    },
  });

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
      })
      .listen("IntentionUpdated", (payload: IntentionUpdatedEvent) => {
        setMembers((prev) =>
          prev.map((m) =>
            m.user_id === payload.user_id ? { ...m, intention: payload.intention } : m,
          ),
        );
      })
      .listen("RoomSettingsUpdated", (payload: RoomSettingsUpdatedEvent) => {
        // Host transfer (M3) and host edits (M4): apply instantly so the
        // new host's controls appear without a refresh, then re-sync for
        // the authoritative state.
        setRoom((prev) => {
          if (!prev) return prev;
          const next: Room = { ...prev, is_host: payload.host_id === userId };
          for (const key of ROOM_SETTING_KEYS) {
            const value = payload[key];
            if (value !== undefined) {
              (next as unknown as Record<string, unknown>)[key] = value;
            }
          }
          return next;
        });
        setMembers((prev) =>
          prev.map((m) => ({ ...m, is_host: m.user_id === payload.host_id })),
        );
        scheduleRefresh();
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
  }, [room?.id, loadRoom, scheduleRefresh, userId]); // eslint-disable-line react-hooks/exhaustive-deps

  // M3 presence: instant join/leave plus member events on the presence
  // channel (PRD 4.4). Subscribed only while we hold membership — the
  // channel auth rejects non-members of private rooms anyway.
  useEffect(() => {
    if (!room || !hasJoined || stopped) return;

    const echo = getEcho();
    // echo.join() prefixes `presence-` itself (presenceChannel(): the wire
    // name is `presence-room.{id}`), and leave() probes the prefixed form —
    // passing the full wire name here would subscribe to
    // `presence-presence-room.{id}` and fail channel auth with 403.
    const channelName = `room.${room.id}`;
    const presence = echo.join(channelName);

    // Members who joined between our state fetch and this subscription.
    presence.here(() => scheduleRefresh());
    presence.joining(() => scheduleRefresh());

    // Instant leave (PRD 4.4); the reaper's MemberRemoved is the backstop
    // for sockets that die without a clean unsubscribe.
    presence.leaving((info: PresenceUserInfo) => {
      setMembers((prev) => prev.filter((m) => m.user_id !== info.id));
    });

    presence.listen("MemberRemoved", (payload: MemberRemovedEvent) => {
      if (payload.user_id === userId) {
        // Could be a second-room kick or a reap: refresh the grid and let
        // the next heartbeat settle it (409 stops us, otherwise the beat
        // self-heals an open room — server-side, never client-guessed).
        scheduleRefresh();
        return;
      }
      setMembers((prev) => prev.filter((m) => m.user_id !== payload.user_id));
    });

    presence.listen("MemberStatusChanged", (payload: MemberStatusChangedEvent) => {
      setMembers((prev) =>
        prev.map((m) =>
          m.user_id === payload.user_id ? { ...m, status: payload.status } : m,
        ),
      );
    });

    return () => {
      echo.leave(channelName);
    };
    // `room?.id` is the subscription identity: room objects are recreated
    // by every refetch, which must not churn the socket subscription.
  }, [room?.id, hasJoined, stopped, userId, loadRoom, scheduleRefresh]); // eslint-disable-line react-hooks/exhaustive-deps

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
      setHasJoined(true);
      setStopped(false);
      setNotice(null);
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

  async function handleIntention(next: string) {
    if (!room || userId === null) return;
    const value = next.trim().slice(0, 80);
    const normalized = value === "" ? null : value;
    if (normalized === (ownCard?.intention ?? null)) return;

    // Optimistic — the IntentionUpdated broadcast confirms for the room.
    setMembers((prev) =>
      prev.map((m) => (m.user_id === userId ? { ...m, intention: normalized } : m)),
    );
    try {
      await api(`/api/v1/rooms/${room.id}/intention`, {
        method: "PATCH",
        body: { intention: normalized },
        csrf: true,
      });
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save your intention.");
      void loadRoom(); // roll back to server truth
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

      {isMember && (
        <IntentionEditor
          value={ownCard?.intention ?? null}
          onSave={(intention) => void handleIntention(intention)}
        />
      )}

      <MemberGrid members={members} />

      <div className="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
        <div className="grid grid-cols-2 gap-4 text-sm">
          <div>
            <span className="block text-zinc-400">Members</span>
            <span className="font-semibold">{members.length} / {room.max_members}</span>
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

      {notice && (
        <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
          {notice}
        </p>
      )}

      {room.type === "private" && !isHost && !isMember && (
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
