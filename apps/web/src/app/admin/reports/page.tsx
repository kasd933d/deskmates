"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useAuth } from "@/hooks/useAuth";
import { api, ApiError } from "@/lib/api";
import type { Report, ReportsResponse, ReportStatus } from "@/types";

const STATUS_META: Record<ReportStatus, { label: string; className: string }> = {
  open: {
    label: "open",
    className: "bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300",
  },
  reviewed: {
    label: "reviewed",
    className: "bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300",
  },
  dismissed: {
    label: "dismissed",
    className: "bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400",
  },
};

function formatTime(ms: number): string {
  return new Date(ms).toLocaleString();
}

function ReportCard({
  report,
  acting,
  onSetStatus,
}: {
  report: Report;
  acting: boolean;
  onSetStatus: (id: number, status: "reviewed" | "dismissed") => void;
}) {
  const status = STATUS_META[report.status];

  return (
    <li
      data-testid={`report-${report.id}`}
      className="rounded-xl border border-zinc-100 p-4 dark:border-zinc-800/60"
    >
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="text-sm font-semibold">
            {report.target?.avatar_emoji ?? "🙂"} {report.target?.name ?? "Unknown user"}
            <span className="mx-1.5 font-normal text-zinc-400">reported by</span>
            {report.reporter?.name ?? "Unknown"}
          </p>
          <p className="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
            {report.room ? (
              <a
                href={`/room/${report.room.slug}`}
                className="underline hover:text-zinc-700 dark:hover:text-zinc-200"
              >
                {report.room.name}
              </a>
            ) : (
              "Room"
            )}
            {" · "}
            <time dateTime={new Date(report.created_at_ms).toISOString()}>
              {formatTime(report.created_at_ms)}
            </time>
          </p>
        </div>
        <span
          className={`rounded-full px-2 py-0.5 text-xs font-medium ${status.className}`}
        >
          {status.label}
        </span>
      </div>

      <p className="mt-2 whitespace-pre-wrap break-words text-sm text-zinc-700 dark:text-zinc-300">
        {report.reason}
      </p>

      {report.message && (
        <blockquote className="mt-2 rounded-lg bg-zinc-50 px-3 py-2 text-xs text-zinc-600 italic dark:bg-zinc-800/60 dark:text-zinc-400">
          “{report.message.body}”
        </blockquote>
      )}

      {report.status === "open" && (
        <div className="mt-3 flex gap-2">
          <button
            type="button"
            disabled={acting}
            onClick={() => onSetStatus(report.id, "reviewed")}
            className="rounded-lg bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-zinc-700 disabled:opacity-50 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300"
          >
            Mark reviewed
          </button>
          <button
            type="button"
            disabled={acting}
            onClick={() => onSetStatus(report.id, "dismissed")}
            className="rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-medium hover:bg-zinc-100 disabled:opacity-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
          >
            Dismiss
          </button>
        </div>
      )}
    </li>
  );
}

/**
 * PRD 4.12/6: `/admin/reports` — the moderation queue behind the `admin`
 * role. Open reports can be marked reviewed or dismissed; everyone else
 * gets a plain "Admins only." from the API (403) and a matching message
 * here.
 */
export default function AdminReportsPage() {
  const { user, loading: authLoading } = useAuth();
  const router = useRouter();
  const [reports, setReports] = useState<Report[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [actingId, setActingId] = useState<number | null>(null);

  useEffect(() => {
    if (!authLoading && !user) {
      router.push("/login");
    }
  }, [user, authLoading, router]);

  useEffect(() => {
    if (!user) return;
    let cancelled = false;
    api<ReportsResponse>("/api/v1/admin/reports")
      .then((result) => {
        if (!cancelled) {
          setReports(result.reports);
          setError(null);
        }
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        if (err instanceof ApiError && err.status === 403) {
          setError("Admins only.");
        } else {
          setError(err instanceof ApiError ? err.message : "Failed to load reports.");
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [user]);

  async function setStatus(id: number, status: "reviewed" | "dismissed") {
    setActingId(id);
    try {
      const data = await api<{ report: Report }>(`/api/v1/admin/reports/${id}`, {
        method: "PATCH",
        body: { status },
        csrf: true,
      });
      setReports((prev) => prev.map((r) => (r.id === id ? data.report : r)));
      setError(null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not update the report.");
    } finally {
      setActingId(null);
    }
  }

  if (authLoading || !user) {
    return <p className="p-8 text-center text-zinc-500">Loading…</p>;
  }

  const open = reports.filter((r) => r.status === "open");
  const handled = reports.filter((r) => r.status !== "open");

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-12">
      <div>
        <h1 className="text-2xl font-bold">Moderation queue</h1>
        <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
          Reports from the rooms, newest first.
        </p>
      </div>

      {error && (
        <p
          role="alert"
          className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600 dark:bg-red-950/50 dark:text-red-400"
        >
          {error}
        </p>
      )}

      {loading ? (
        <p className="text-sm text-zinc-500">Loading reports…</p>
      ) : reports.length === 0 && !error ? (
        <p className="text-sm text-zinc-500">No reports — all quiet.</p>
      ) : (
        <>
          <section aria-label="Open reports">
            <h2 className="mb-3 text-sm font-semibold text-zinc-700 dark:text-zinc-300">
              Open ({open.length})
            </h2>
            {open.length === 0 ? (
              <p className="text-sm text-zinc-500">Nothing waiting for review.</p>
            ) : (
              <ul className="flex flex-col gap-3">
                {open.map((r) => (
                  <ReportCard
                    key={r.id}
                    report={r}
                    acting={actingId === r.id}
                    onSetStatus={(id, status) => void setStatus(id, status)}
                  />
                ))}
              </ul>
            )}
          </section>

          {handled.length > 0 && (
            <section aria-label="Handled reports">
              <h2 className="mb-3 text-sm font-semibold text-zinc-700 dark:text-zinc-300">
                Handled ({handled.length})
              </h2>
              <ul className="flex flex-col gap-3">
                {handled.map((r) => (
                  <ReportCard
                    key={r.id}
                    report={r}
                    acting={actingId === r.id}
                    onSetStatus={(id, status) => void setStatus(id, status)}
                  />
                ))}
              </ul>
            </section>
          )}
        </>
      )}
    </div>
  );
}
