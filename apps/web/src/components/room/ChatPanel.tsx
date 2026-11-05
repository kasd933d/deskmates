"use client";

import { useEffect, useRef, useState, type FormEvent, type KeyboardEvent } from "react";
import { ApiError } from "@/lib/api";
import type { ChatMessage } from "@/types";

type Props = {
  messages: ChatMessage[];
  currentUserId: number | null;
  /** Host or admin — may delete anyone's message (PRD 4.6). */
  canModerate: boolean;
  /** Member and not stopped: the composer is live. */
  enabled: boolean;
  /** Why the composer is off (join / muted / silent focus), or null. */
  disabledReason: string | null;
  /** POSTs the message; throws ApiError so the panel can render the verdict. */
  onSend: (body: string) => Promise<void>;
  onDelete: (message: ChatMessage) => void;
  onReport: (message: ChatMessage) => void;
};

const MAX_BODY = 300;

function formatTime(ms: number): string {
  return new Date(ms).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
}

/**
 * PRD 4.6: lightweight room chat — last messages, a 300-character composer,
 * per-message delete (author/host/admin) and report. The composer surfaces
 * the server's verdicts inline: 429 (rate limit), 403 (muted / silent
 * focus / not a member) and 422 (validation) all render as text under the
 * input instead of a silent failure.
 */
export function ChatPanel({
  messages,
  currentUserId,
  canModerate,
  enabled,
  disabledReason,
  onSend,
  onDelete,
  onReport,
}: Props) {
  const [draft, setDraft] = useState("");
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const scrollRef = useRef<HTMLDivElement>(null);

  // Stick to the newest message when the list grows.
  useEffect(() => {
    const el = scrollRef.current;
    if (el) el.scrollTop = el.scrollHeight;
  }, [messages.length]);

  async function submit() {
    const body = draft.trim();
    if (body === "" || sending || !enabled) return;

    setSending(true);
    setError(null);
    try {
      await onSend(body);
      setDraft("");
    } catch (err) {
      if (err instanceof ApiError) {
        if (err.status === 429) {
          setError("Slow down — one message every 2 seconds.");
        } else if (err.status === 422 && err.errors?.body?.[0]) {
          setError(err.errors.body[0]);
        } else {
          setError(err.message);
        }
      } else {
        setError("Could not send your message.");
      }
    } finally {
      setSending(false);
    }
  }

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    void submit();
  }

  function handleKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
    // Enter sends, Shift+Enter keeps the newline (IM composing is not a send).
    if (event.key === "Enter" && !event.shiftKey && !event.nativeEvent.isComposing) {
      event.preventDefault();
      void submit();
    }
  }

  const placeholder = !enabled
    ? (disabledReason ?? "Chat is unavailable.")
    : "Message the room… (Enter to send)";

  return (
    <section
      aria-label="Chat"
      className="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900"
    >
      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Chat</h2>
        <span className="text-xs text-zinc-400">last {messages.length}</span>
      </div>

      <div
        ref={scrollRef}
        className="max-h-80 min-h-24 overflow-y-auto pr-1"
        aria-live="polite"
      >
        {messages.length === 0 ? (
          <p className="py-6 text-center text-sm text-zinc-500">
            {enabled ? "No messages yet — say hi." : (disabledReason ?? "Chat is unavailable.")}
          </p>
        ) : (
          <ul className="flex flex-col gap-3">
            {messages.map((msg) => {
              const canDelete = canModerate || msg.user_id === currentUserId;
              const canReport = msg.user_id !== currentUserId;

              return (
                <li key={msg.id} className="flex items-start gap-2">
                  <span
                    aria-hidden
                    className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-base"
                    style={{ backgroundColor: msg.avatar_color ?? "#e4e4e7" }}
                  >
                    {msg.avatar_emoji ?? "🙂"}
                  </span>

                  <div className="min-w-0 flex-1">
                    <div className="flex items-baseline gap-2">
                      <span className="truncate text-sm font-semibold">
                        {msg.name ?? "Member"}
                      </span>
                      <time
                        className="text-xs text-zinc-400"
                        dateTime={new Date(msg.created_at_ms).toISOString()}
                      >
                        {formatTime(msg.created_at_ms)}
                      </time>
                    </div>
                    <p className="whitespace-pre-wrap break-words text-sm text-zinc-700 dark:text-zinc-300">
                      {msg.body}
                    </p>
                  </div>

                  <div className="flex shrink-0 gap-0.5">
                    {canDelete && (
                      <button
                        type="button"
                        aria-label="Delete message"
                        title="Delete message"
                        onClick={() => onDelete(msg)}
                        className="rounded p-1 text-xs text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-300"
                      >
                        🗑
                      </button>
                    )}
                    {canReport && (
                      <button
                        type="button"
                        aria-label="Report message"
                        title="Report message"
                        onClick={() => onReport(msg)}
                        className="rounded p-1 text-xs text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-300"
                      >
                        🚩
                      </button>
                    )}
                  </div>
                </li>
              );
            })}
          </ul>
        )}
      </div>

      <form onSubmit={handleSubmit} className="mt-3 flex flex-col gap-2">
        <textarea
          value={draft}
          onChange={(e) => setDraft(e.target.value.slice(0, MAX_BODY))}
          onKeyDown={handleKeyDown}
          disabled={!enabled || sending}
          rows={2}
          maxLength={MAX_BODY}
          aria-label="Message"
          placeholder={placeholder}
          className="resize-none rounded-lg border border-zinc-300 bg-transparent px-3 py-2 text-sm outline-none focus:border-zinc-500 disabled:opacity-60 dark:border-zinc-700"
        />

        <div className="flex items-center justify-between gap-3">
          <span className="text-xs text-zinc-400" aria-hidden={draft.length === 0}>
            {draft.length}/{MAX_BODY}
          </span>
          <button
            type="submit"
            disabled={!enabled || sending || draft.trim() === ""}
            className="rounded-lg bg-zinc-900 px-4 py-1.5 text-sm font-semibold text-white hover:bg-zinc-700 disabled:opacity-50 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300"
          >
            {sending ? "Sending…" : "Send"}
          </button>
        </div>

        {error && (
          <p role="alert" className="text-xs text-red-600 dark:text-red-400">
            {error}
          </p>
        )}
        {!enabled && disabledReason && (
          <p className="text-xs text-amber-700 dark:text-amber-300">{disabledReason}</p>
        )}
      </form>
    </section>
  );
}
