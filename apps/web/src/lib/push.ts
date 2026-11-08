"use client";

/**
 * Milestone 6 (PRD 4.10): Web Push subscription plumbing.
 *
 * The browser owns the subscription (`pushManager.subscribe` with the VAPID
 * public key from env); the API only stores endpoint + keys so the server
 * can fan out later. Everything here is best-effort UI plumbing — the SW in
 * `public/push-sw.js` decides whether a toast actually appears.
 */

import { api } from "@/lib/api";

const VAPID_PUBLIC_KEY = process.env.NEXT_PUBLIC_VAPID_PUBLIC_KEY ?? "";

/** PRD 13: Web Push needs service workers — unsupported browsers get UI-less. */
export function pushSupported(): boolean {
  return (
    typeof window !== "undefined" &&
    "serviceWorker" in navigator &&
    "PushManager" in window &&
    Notification.permission !== "denied" &&
    VAPID_PUBLIC_KEY !== ""
  );
}

/** base64url → Uint8Array, as required by PushManager.subscribe. */
function urlBase64ToUint8Array(base64: string): Uint8Array<ArrayBuffer> {
  const padding = "=".repeat((4 - (base64.length % 4)) % 4);
  const base64Normalized = (base64 + padding).replace(/-/g, "+").replace(/_/g, "/");
  const raw = atob(base64Normalized);
  const bytes = new Uint8Array(new ArrayBuffer(raw.length));
  for (let i = 0; i < raw.length; i++) {
    bytes[i] = raw.charCodeAt(i);
  }
  return bytes;
}

async function getRegistration(): Promise<ServiceWorkerRegistration> {
  return navigator.serviceWorker.register("/push-sw.js");
}

/** The browser's current subscription, if any (regardless of server state). */
export async function currentSubscription(): Promise<PushSubscription | null> {
  if (!("serviceWorker" in navigator)) return null;
  const registration = await navigator.serviceWorker.getRegistration();
  return (await registration?.pushManager.getSubscription()) ?? null;
}

/**
 * Ask for permission, subscribe this browser, and mirror the subscription
 * to the API. Throws ApiError/Permission-denied so the caller can show why.
 */
export async function subscribePush(): Promise<void> {
  const permission = await Notification.requestPermission();
  if (permission !== "granted") {
    throw new Error("Notifications are blocked in your browser settings.");
  }

  const registration = await getRegistration();
  const subscription = await registration.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY),
  });

  const json = subscription.toJSON();
  if (!json.endpoint || !json.keys?.p256dh || !json.keys?.auth) {
    throw new Error("The browser returned an incomplete push subscription.");
  }

  await api("/api/v1/push/subscribe", {
    method: "POST",
    csrf: true,
    body: {
      endpoint: json.endpoint,
      keys: { p256dh: json.keys.p256dh, auth: json.keys.auth },
    },
  });
}

/** Drop the browser subscription and its server row (idempotent). */
export async function unsubscribePush(): Promise<void> {
  const subscription = await currentSubscription();
  if (!subscription) return;

  const endpoint = subscription.endpoint;
  await api("/api/v1/push/subscribe", {
    method: "DELETE",
    csrf: true,
    body: { endpoint },
  });
  await subscription.unsubscribe();
}
