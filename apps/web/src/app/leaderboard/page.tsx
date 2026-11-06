import { Suspense } from "react";
import { LeaderboardView } from "./leaderboard-view";

/**
 * Server shell: the client view reads `useSearchParams()` for the optional
 * `?room=` scope, which blocks prerendering unless it sits inside a
 * <Suspense> boundary (same pattern as the room route).
 */
export default function LeaderboardPage() {
  return (
    <Suspense fallback={<p className="p-8 text-center text-zinc-500">Loading…</p>}>
      <LeaderboardView />
    </Suspense>
  );
}
