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
  /** Milestone 3: member cards seed the grid; presence keeps it live. */
  members: RoomMemberCard[];
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

/** PRD 4.4: member card status dot states. */
export type MemberStatus = "focusing" | "break" | "away";

/** PRD 4.4: a member card — avatar, name, intention and status dot. */
export type RoomMemberCard = {
  user_id: number;
  name: string | null;
  avatar_emoji: string | null;
  avatar_color: string | null;
  intention: string | null;
  status: MemberStatus;
  is_host: boolean;
  is_muted: boolean;
  joined_at: string | null;
};

/** Payload of the MemberRemoved broadcast (presence channel). */
export type MemberRemovedEvent = {
  room_id: number;
  user_id: number;
};

/** Payload of the MemberStatusChanged broadcast (presence channel). */
export type MemberStatusChangedEvent = {
  room_id: number;
  user_id: number;
  status: MemberStatus;
};

/** Payload of the IntentionUpdated broadcast (room channel). */
export type IntentionUpdatedEvent = {
  room_id: number;
  user_id: number;
  intention: string | null;
};

/**
 * Payload of the RoomSettingsUpdated broadcast (room channel): the host id
 * plus whichever room config keys the host may change (all present on emit).
 */
export type RoomSettingsUpdatedEvent = {
  room_id: number;
  host_id: number;
  server_now_ms: number;
  name?: string;
  theme?: string;
  focus_minutes?: number;
  short_break_minutes?: number;
  long_break_minutes?: number;
  long_break_every?: number;
  max_members?: number;
  silent_focus?: boolean;
};

/**
 * `user_info` signed into a presence channel's channel_data — the shape
 * .here() / .joining() / .leaving() deliver (channels.php return array).
 */
export type PresenceUserInfo = {
  id: number;
  name: string;
  avatar_emoji: string;
  avatar_color: string;
};

/* ----------------------------------------------------------------------
 * Milestone 4 — chat (PRD 4.6)
 * ---------------------------------------------------------------------- */

/** A chat message; REST payload and MessageSent broadcast share the shape. */
export type ChatMessage = {
  id: number;
  room_id: number;
  user_id: number;
  name: string | null;
  avatar_emoji: string | null;
  avatar_color: string | null;
  body: string;
  created_at_ms: number;
};

export type MessagesResponse = {
  messages: ChatMessage[];
  server_now_ms: number;
};

/** Payload of the MessageDeleted broadcast (presence channel). */
export type MessageDeletedEvent = {
  room_id: number;
  message_id: number;
};

/* ----------------------------------------------------------------------
 * Milestone 4 — moderation (PRD 4.6 / 4.12)
 * ---------------------------------------------------------------------- */

/** Payload of the MemberMuted broadcast (presence channel). */
export type MemberMutedEvent = {
  room_id: number;
  user_id: number;
  is_muted: boolean;
};

/**
 * Payload of the MemberKicked broadcast (private-user channel): reason
 * distinguishes a kick (may rejoin) from a ban (may not).
 */
export type MemberKickedEvent = {
  room_id: number;
  room_slug: string;
  reason: "kicked" | "banned";
  server_now_ms: number;
};

export type ReportStatus = "open" | "reviewed" | "dismissed";

/** A row in the admin moderation queue. */
export type Report = {
  id: number;
  room_id: number;
  reason: string;
  status: ReportStatus;
  message_id: number | null;
  created_at_ms: number;
  reporter: { id: number; name: string } | null;
  target: { id: number; name: string; avatar_emoji: string | null } | null;
  room: { id: number; slug: string; name: string } | null;
  message: { id: number; body: string } | null;
};

export type ReportsResponse = {
  reports: Report[];
  server_now_ms: number;
};

/* ----------------------------------------------------------------------
 * Milestone 4 — reactions (PRD 4.7)
 * ---------------------------------------------------------------------- */

/** The five quick reactions (PRD 4.7). */
export type ReactionEmoji = "👏" | "🔥" | "💪" | "☕" | "🎉";

/** Payload carried by the `client-reaction` whisper (ephemeral, no server row). */
export type ReactionPayload = {
  emoji: ReactionEmoji;
};

/** A reaction currently floating up the screen. */
export type FloatingReaction = {
  /** Unique per float — used for the removal timer and React key. */
  id: number;
  emoji: ReactionEmoji;
  /** Horizontal position as a % of the viewport width. */
  x: number;
  /** The reacting member (server-injected whisper metadata), if still present. */
  user_id: number | null;
};
