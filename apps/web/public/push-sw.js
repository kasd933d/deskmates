/* global self, clients */

/**
 * Milestone 6 (PRD 4.10): the Web Push service worker.
 *
 * Phase pushes carry `background_only: true` — PRD says push fires "if the
 * tab is in the background", so while any DeskMates tab is visible the toast
 * is dropped (the in-tab chime covers that case). Session reminders never
 * set the flag: a "starts in 10 min" nudge must reach you either way.
 */

self.addEventListener("push", (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch {
    data = { body: event.data ? event.data.text() : "" };
  }

  event.waitUntil(
    (async () => {
      if (data.background_only) {
        const tabs = await clients.matchAll({
          type: "window",
          includeUncontrolled: true,
        });
        const visible = tabs.some(
          (client) =>
            client.visibilityState === "visible" &&
            client.url.startsWith(self.location.origin),
        );
        if (visible) return;
      }

      await self.registration.showNotification(data.title || "DeskMates", {
        body: data.body || "",
        tag: data.tag || "deskmates-push",
        data: { url: data.url || "/" },
      });
    })(),
  );
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || "/";

  event.waitUntil(
    (async () => {
      const tabs = await clients.matchAll({
        type: "window",
        includeUncontrolled: true,
      });

      for (const client of tabs) {
        if (client.url.startsWith(self.location.origin) && "focus" in client) {
          await client.focus();
          if ("navigate" in client) {
            await client.navigate(url);
          }
          return;
        }
      }

      await clients.openWindow(url);
    })(),
  );
});
