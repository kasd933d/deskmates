"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { useAuth } from "@/hooks/useAuth";
import { api, ApiError } from "@/lib/api";
import type { HeatmapPayload, StatsPayload } from "@/types";

/** "1h 5m" / "45m" — the only unit format this page uses. */
function fmtMinutes(minutes: number): string {
  if (minutes < 60) return `${minutes}m`;
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return m === 0 ? `${h}h` : `${h}h ${m}m`;
}

function fmtHour(hour: number): string {
  const suffix = hour < 12 ? "AM" : "PM";
  const h = hour % 12 === 0 ? 12 : hour % 12;
  return `${h}:00 ${suffix}`;
}

/**
 * Heat cell shade: 0 stays neutral, any credit gets a step of the same hue
 * scaled against the user's own best day (so an empty grid still reads).
 */
function heatClass(minutes: number | undefined, max: number): string {
  if (minutes === undefined) return "bg-transparent";
  if (minutes === 0) return "bg-zinc-100 dark:bg-zinc-800";
  const ratio = max > 0 ? minutes / max : 1;
  if (ratio < 0.25) return "bg-emerald-200 dark:bg-emerald-900";
  if (ratio < 0.5) return "bg-emerald-400 dark:bg-emerald-800";
  if (ratio < 0.75) return "bg-emerald-500 dark:bg-emerald-600";
  return "bg-emerald-700 dark:bg-emerald-400";
}

function StatCard({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div className="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
      <span className="block text-sm text-zinc-500 dark:text-zinc-400">{label}</span>
      <span className="mt-1 block text-2xl font-bold tracking-tight">{value}</span>
      {hint && <span className="mt-0.5 block text-xs text-zinc-400 dark:text-zinc-500">{hint}</span>}
    </div>
  );
}

export default function StatsPage() {
  const { user, loading: authLoading } = useAuth();
  const router = useRouter();
  const [stats, setStats] = useState<StatsPayload | null>(null);
  const [heatmap, setHeatmap] = useState<HeatmapPayload | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (authLoading) return;
    if (!user) {
      router.push("/login");
      return;
    }
    let cancelled = false;
    Promise.all([
      api<StatsPayload>("/api/v1/me/stats"),
      api<HeatmapPayload>("/api/v1/me/heatmap"),
    ])
      .then(([nextStats, nextHeatmap]) => {
        if (!cancelled) {
          setStats(nextStats);
          setHeatmap(nextHeatmap);
        }
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : "Failed to load your stats.");
      });
    return () => {
      cancelled = true;
    };
  }, [user, authLoading, router]);

  if (authLoading || (!user && !error)) {
    return <p className="p-8 text-center text-zinc-500">Loading…</p>;
  }

  if (error) {
    return <p role="alert" className="p-8 text-center text-red-500">{error}</p>;
  }

  if (!stats || !heatmap) {
    return <p className="p-8 text-center text-zinc-500">Loading…</p>;
  }

  const empty = stats.sessions_total === 0;

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-8 px-4 py-10">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">Your focus stats</h1>
        <Link
          href="/leaderboard"
          className="text-sm font-medium text-zinc-600 underline dark:text-zinc-400"
        >
          Leaderboard →
        </Link>
      </div>

      {empty ? (
        <div className="rounded-2xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
          <p className="text-lg font-semibold">No credited focus yet</p>
          <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            Sit in any room for at least 5 minutes and your minutes, streaks and heatmap will show up here.
          </p>
          <Link
            href="/rooms"
            className="mt-4 inline-block rounded-lg bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300"
          >
            Find a room
          </Link>
        </div>
      ) : (
        <>
          <section className="grid grid-cols-3 gap-3">
            <StatCard label="Today" value={fmtMinutes(stats.today_minutes)} />
            <StatCard label="This week" value={fmtMinutes(stats.week_minutes)} />
            <StatCard label="All time" value={fmtMinutes(stats.all_time_minutes)} />
          </section>

          <section className="grid grid-cols-2 gap-3">
            <StatCard
              label="Current streak"
              value={`🔥 ${stats.current_streak} ${stats.current_streak === 1 ? "day" : "days"}`}
              hint={`${stats.streak_threshold_minutes}+ min on a day keeps it alive`}
            />
            <StatCard
              label="Longest streak"
              value={`🏆 ${stats.longest_streak} ${stats.longest_streak === 1 ? "day" : "days"}`}
            />
          </section>

          <section className="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <div className="mb-3 flex items-baseline justify-between">
              <h2 className="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                Last 12 weeks
              </h2>
              <span className="text-xs text-zinc-400 dark:text-zinc-500">
                {heatmap.start_date} → {heatmap.end_date}
              </span>
            </div>

            {/* Columns = weeks (Mon-start), rows = weekdays; the partial
                current week sits last and future days simply have no cell. */}
            <div className="flex flex-col gap-1">
              {Array.from({ length: 7 }, (_, row) => (
                <div key={row} className="flex gap-1">
                  {Array.from({ length: heatmap.weeks }, (_, week) => {
                    const day = heatmap.days[week * 7 + row];
                    return (
                      <div
                        key={week}
                        title={day ? `${day.date}: ${fmtMinutes(day.minutes)}` : undefined}
                        aria-label={day ? `${day.date}, ${day.minutes} minutes` : undefined}
                        className={`h-4 w-4 rounded-[3px] ${heatClass(day?.minutes, heatmap.max_minutes)}`}
                      />
                    );
                  })}
                </div>
              ))}
            </div>

            <div className="mt-3 flex items-center justify-end gap-1.5 text-xs text-zinc-400 dark:text-zinc-500">
              Less
              <span className="h-3 w-3 rounded-[3px] bg-zinc-100 dark:bg-zinc-800" />
              <span className="h-3 w-3 rounded-[3px] bg-emerald-200 dark:bg-emerald-900" />
              <span className="h-3 w-3 rounded-[3px] bg-emerald-400 dark:bg-emerald-800" />
              <span className="h-3 w-3 rounded-[3px] bg-emerald-500 dark:bg-emerald-600" />
              <span className="h-3 w-3 rounded-[3px] bg-emerald-700 dark:bg-emerald-400" />
              More
            </div>
          </section>

          <section className="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <StatCard
              label="Best day"
              value={stats.best_day ? fmtMinutes(stats.best_day.minutes) : "—"}
              hint={stats.best_day?.date}
            />
            <StatCard
              label="Average session"
              value={fmtMinutes(Math.round(stats.avg_session_minutes))}
              hint={`${stats.sessions_total} credited ${stats.sessions_total === 1 ? "session" : "sessions"}`}
            />
            <StatCard
              label="Check-ins finished"
              value={
                stats.checkins.completion_rate === null
                  ? "—"
                  : `${Math.round(stats.checkins.completion_rate * 100)}%`
              }
              hint={`${stats.checkins.done} of ${stats.checkins.answered} answered “Yes”`}
            />
          </section>

          <section className="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
              Favorite hours
            </h2>
            {stats.favorite_hours.length === 0 ? (
              <p className="text-sm text-zinc-400 dark:text-zinc-500">Not enough sessions yet.</p>
            ) : (
              <ul className="flex flex-col gap-2">
                {stats.favorite_hours.map((slot) => (
                  <li key={slot.hour} className="flex items-center gap-3 text-sm">
                    <span className="w-24 font-medium">{fmtHour(slot.hour)}</span>
                    <span className="h-2 flex-1 rounded-full bg-zinc-100 dark:bg-zinc-800">
                      <span
                        className="block h-2 rounded-full bg-emerald-500"
                        style={{
                          width: `${Math.min(100, Math.round((slot.minutes / stats.favorite_hours[0].minutes) * 100))}%`,
                        }}
                      />
                    </span>
                    <span className="w-16 text-right text-zinc-500 dark:text-zinc-400">
                      {fmtMinutes(slot.minutes)}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </section>

          <p className="text-center text-xs text-zinc-400 dark:text-zinc-500">
            Up to {Math.floor(stats.daily_cap_minutes / 60)} credited hours per day · days need{" "}
            {stats.streak_threshold_minutes}+ minutes to count toward a streak.
          </p>
        </>
      )}
    </div>
  );
}
