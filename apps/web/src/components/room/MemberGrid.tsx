"use client";

import type { RoomMemberCard } from "@/types";

const STATUS_META: Record<RoomMemberCard["status"], { label: string; dot: string }> = {
  focusing: { label: "focusing", dot: "bg-emerald-500" },
  break: { label: "on break", dot: "bg-sky-500" },
  away: { label: "away", dot: "bg-zinc-400" },
};

/**
 * PRD 4.4: the member grid — each card shows avatar, name, intention and a
 * status dot (focusing / on break / away). Seeded from the room state and
 * kept live by the presence channel.
 */
export function MemberGrid({ members }: { members: RoomMemberCard[] }) {
  return (
    <section
      aria-label="Room members"
      className="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900"
    >
      <div className="mb-4 flex items-center justify-between">
        <h2 className="text-sm font-semibold text-zinc-700 dark:text-zinc-300">In this room</h2>
        <span className="text-xs text-zinc-400">
          {members.length} {members.length === 1 ? "member" : "members"}
        </span>
      </div>

      {members.length === 0 ? (
        <p className="text-sm text-zinc-500">Nobody here yet — be the first.</p>
      ) : (
        <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          {members.map((member) => {
            const meta = STATUS_META[member.status];

            return (
              <li
                key={member.user_id}
                className="flex items-start gap-3 rounded-xl border border-zinc-100 p-3 dark:border-zinc-800/60"
              >
                <span
                  aria-hidden
                  className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-lg"
                  style={{ backgroundColor: member.avatar_color ?? "#e4e4e7" }}
                >
                  {member.avatar_emoji ?? "🙂"}
                </span>

                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-1.5">
                    <span className="truncate text-sm font-semibold">
                      {member.name ?? "Member"}
                    </span>
                    {member.is_host && (
                      <span title="Host" aria-label="Host">
                        👑
                      </span>
                    )}
                    {member.is_muted && (
                      <span title="Muted" aria-label="Muted">
                        🔇
                      </span>
                    )}
                  </div>

                  <div className="mt-0.5 flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                    <span aria-hidden className={`h-2 w-2 rounded-full ${meta.dot}`} />
                    <span>{meta.label}</span>
                  </div>

                  {member.intention && (
                    <p
                      className="mt-1 truncate text-xs text-zinc-600 dark:text-zinc-400"
                      title={member.intention}
                    >
                      🎯 {member.intention}
                    </p>
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </section>
  );
}
