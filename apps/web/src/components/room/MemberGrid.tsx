"use client";

import { useState } from "react";
import type { RoomMemberCard } from "@/types";

const STATUS_META: Record<RoomMemberCard["status"], { label: string; dot: string }> = {
  focusing: { label: "focusing", dot: "bg-emerald-500" },
  break: { label: "on break", dot: "bg-sky-500" },
  away: { label: "away", dot: "bg-zinc-400" },
};

export type ModerationAction = "mute" | "unmute" | "kick" | "ban";

type Props = {
  members: RoomMemberCard[];
  /** The signed-in user — never offered moderation or report on self. */
  currentUserId: number | null;
  /** Host or admin of this room (PRD 4.12: kick / ban / mute). */
  canModerate: boolean;
  /** Everyone may report another member (PRD 4.12). */
  onReport: (member: RoomMemberCard) => void;
  onModerate: (action: ModerationAction, member: RoomMemberCard) => void;
};

const ACTION_LABELS: Record<"kick" | "ban", string> = { kick: "Kick", ban: "Ban" };

/**
 * PRD 4.4/4.12: the member grid — avatar, name, intention and status dot,
 * plus the moderation toolbar (mute / kick / ban, with an inline confirm
 * for the destructive pair) and a report button on every other member.
 */
export function MemberGrid({ members, currentUserId, canModerate, onReport, onModerate }: Props) {
  const [pending, setPending] = useState<{ userId: number; action: "kick" | "ban" } | null>(null);

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
            const isSelf = member.user_id === currentUserId;
            // Report is offered on every other member; the moderation
            // buttons inside are gated by `canModerate`.
            const showActions = !isSelf;
            const confirming = pending?.userId === member.user_id ? pending.action : null;

            return (
              <li
                key={member.user_id}
                data-member-id={member.user_id}
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

                  {showActions &&
                    (confirming ? (
                      <div className="mt-2 flex flex-wrap items-center gap-2 text-xs">
                        <span className="text-zinc-600 dark:text-zinc-300">
                          {ACTION_LABELS[confirming]} {member.name ?? "this member"}?
                          {confirming === "ban" && " They cannot rejoin."}
                        </span>
                        <button
                          type="button"
                          data-testid={`confirm-${confirming}`}
                          onClick={() => {
                            onModerate(confirming, member);
                            setPending(null);
                          }}
                          className="rounded bg-red-600 px-2 py-1 font-semibold text-white hover:bg-red-500"
                        >
                          {ACTION_LABELS[confirming]}
                        </button>
                        <button
                          type="button"
                          onClick={() => setPending(null)}
                          className="rounded border border-zinc-300 px-2 py-1 hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
                        >
                          Cancel
                        </button>
                      </div>
                    ) : (
                      <div className="mt-2 flex flex-wrap gap-1">
                        {canModerate && (
                          <>
                            <button
                              type="button"
                              data-testid={`mute-${member.user_id}`}
                              onClick={() =>
                                onModerate(member.is_muted ? "unmute" : "mute", member)
                              }
                              className="rounded border border-zinc-200 px-2 py-1 text-xs hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
                            >
                              {member.is_muted ? "Unmute" : "Mute"}
                            </button>
                            <button
                              type="button"
                              data-testid={`kick-${member.user_id}`}
                              onClick={() => setPending({ userId: member.user_id, action: "kick" })}
                              className="rounded border border-zinc-200 px-2 py-1 text-xs hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
                            >
                              {ACTION_LABELS.kick}
                            </button>
                            <button
                              type="button"
                              data-testid={`ban-${member.user_id}`}
                              onClick={() => setPending({ userId: member.user_id, action: "ban" })}
                              className="rounded border border-zinc-200 px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:border-zinc-700 dark:text-red-400 dark:hover:bg-red-950/40"
                            >
                              {ACTION_LABELS.ban}
                            </button>
                          </>
                        )}
                        <button
                          type="button"
                          data-testid={`report-${member.user_id}`}
                          onClick={() => onReport(member)}
                          className="rounded border border-zinc-200 px-2 py-1 text-xs hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
                        >
                          Report
                        </button>
                      </div>
                    ))}
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </section>
  );
}
