/**
 * Shared API payload types (PRD instruction #10: keep event/payload types in
 * one place and use TypeScript strict mode).
 */

export type User = {
  id: number;
  name: string;
  email: string;
  avatar_emoji: string;
  avatar_color: string;
  timezone: string;
  role: "user" | "admin";
  show_on_leaderboard: boolean;
  current_streak: number;
  longest_streak: number;
  created_at: string;
};

export type RoomType = "always_on" | "public" | "private";

export type RoomPhase = "idle" | "focus" | "short_break" | "long_break";

export type Room = {
  id: number;
  slug: string;
  name: string;
  type: RoomType;
  theme: string;
  max_members: number;
  focus_minutes: number;
  short_break_minutes: number;
  long_break_minutes: number;
  long_break_every: number;
  silent_focus: boolean;
  phase: RoomPhase;
  phase_started_at: string | null;
  phase_ends_at: string | null;
  cycle_index: number;
  phase_version: number;
  anchor_epoch: number | null;
  member_count?: number;
  host: User | null;
  invite_code?: string;
  is_host: boolean;
  server_now_ms: number;
};

export type LobbyResponse = {
  rooms: Room[];
  my_rooms: Room[];
  links: Record<string, string | null>;
  meta: { current_page: number; per_page: number; total: number };
  server_now_ms: number;
};

export type AuthResponse = {
  user: User;
  server_now_ms: number;
};

/**
 * Live timer state. For always-on rooms it is computed deterministically;
 * for user rooms it mirrors the room row. `phase_version` is absent for
 * deterministic timers (nothing to version).
 */
export type TimerState = {
  phase: RoomPhase;
  phase_started_at_ms: number | null;
  phase_ends_at_ms: number | null;
  cycle_index: number;
  phase_version?: number;
};

export type RoomStateResponse = {
  room: Room;
  timer: TimerState;
  server_now_ms: number;
};

export type TimerAction = "start" | "pause" | "skip" | "reset";

export type TimerActionResponse = {
  timer: TimerState;
  server_now_ms: number;
};

/** Payload of the RoomPhaseChanged broadcast. */
export type RoomPhaseChangedEvent = {
  room_id: number;
  room_slug: string;
  phase: RoomPhase;
  phase_started_at_ms: number | null;
  phase_ends_at_ms: number | null;
  cycle_index: number;
  phase_version: number | null;
  server_now_ms: number;
};
