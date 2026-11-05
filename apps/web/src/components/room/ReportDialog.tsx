"use client";

import { useState, type FormEvent } from "react";
import { api, ApiError } from "@/lib/api";

type Props = {
  open: boolean;
  roomId: number;
  target: { id: number; name: string };
  /** Set when a specific message is being reported (PRD 4.6/4.12). */
  messageId: number | null;
  onClose: () => void;
};

const MIN_REASON = 3;
const MAX_REASON = 500;

/**
 * PRD 4.12: report a user (optionally a specific message) with a reason.
 * Submits to POST /api/v1/reports and shows the server's verdict inline;
 * the parent keys the dialog per target so each opening starts clean.
 */
export function ReportDialog({ open, roomId, target, messageId, onClose }: Props) {
  const [reason, setReason] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [sent, setSent] = useState(false);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const trimmed = reason.trim();
    if (trimmed.length < MIN_REASON || submitting) return;

    setSubmitting(true);
    setError(null);
    try {
      await api("/api/v1/reports", {
        method: "POST",
        body: {
          room_id: roomId,
          target_user_id: target.id,
          ...(messageId !== null ? { message_id: messageId } : {}),
          reason: trimmed,
        },
        csrf: true,
      });
      setSent(true);
    } catch (err) {
      if (err instanceof ApiError) {
        setError(err.errors?.reason?.[0] ?? err.message);
      } else {
        setError("Could not send your report.");
      }
    } finally {
      setSubmitting(false);
    }
  }

  if (!open) return null;

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label={`Report ${target.name}`}
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      onClick={onClose}
    >
      <div
        className="w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-800 dark:bg-zinc-900"
        onClick={(e) => e.stopPropagation()}
      >
        {sent ? (
          <div className="flex flex-col gap-4">
            <p className="text-sm text-zinc-700 dark:text-zinc-300" data-testid="report-sent">
              Thanks — your report about {target.name} was sent to the moderators.
            </p>
            <button
              type="button"
              onClick={onClose}
              className="self-end rounded-lg bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300"
            >
              Done
            </button>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="flex flex-col gap-4">
            <div>
              <h2 className="text-base font-semibold">Report {target.name}</h2>
              <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                A moderator will review it. {messageId !== null && "The reported message is included."}
              </p>
            </div>

            <textarea
              value={reason}
              onChange={(e) => setReason(e.target.value.slice(0, MAX_REASON))}
              rows={4}
              minLength={MIN_REASON}
              maxLength={MAX_REASON}
              required
              aria-label="Reason"
              placeholder={`What happened? (${MIN_REASON}–${MAX_REASON} characters)`}
              className="resize-none rounded-lg border border-zinc-300 bg-transparent px-3 py-2 text-sm outline-none focus:border-zinc-500 dark:border-zinc-700"
            />

            {error && (
              <p role="alert" className="text-xs text-red-600 dark:text-red-400">
                {error}
              </p>
            )}

            <div className="flex justify-end gap-2">
              <button
                type="button"
                onClick={onClose}
                className="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={submitting || reason.trim().length < MIN_REASON}
                className="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700 disabled:opacity-50 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300"
              >
                {submitting ? "Sending…" : "Send report"}
              </button>
            </div>
          </form>
        )}
      </div>
    </div>
  );
}
