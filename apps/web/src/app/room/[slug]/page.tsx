import { Suspense } from "react";
import { RoomView } from "./room-view";

/**
 * Server shell for the room route (Next 16 instant navigations): the
 * client view reads `useParams()`, which blocks prerendering unless it
 * sits inside a <Suspense> boundary. The fallback IS the meaningful
 * first paint here — everything below it loads client-side.
 */
export default function RoomPage() {
  return (
    <Suspense fallback={<p className="p-8 text-center text-zinc-500">Loading…</p>}>
      <RoomView />
    </Suspense>
  );
}
