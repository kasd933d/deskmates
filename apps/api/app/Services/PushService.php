<?php

namespace App\Services;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;

/**
 * PRD 4.10: Web Push (VAPID) delivery. One WebPush client per batch: the
 * notifications are queued, flushed together, and every answer is read —
 * a 404/410 from the push service deletes the subscription immediately so
 * the table self-cleans (PRD 4.10).
 *
 * The PSR-18 client is optional: tests bind a fake to drive the response
 * codes without touching the network.
 */
class PushService
{
    public function __construct(private ?ClientInterface $httpClient = null) {}

    /**
     * @param  iterable<PushSubscription>  $subscriptions
     * @param  bool  $backgroundOnly  phase pushes (PRD 4.10: "if the tab is
     *                                in the background") — the service worker
     *                                drops the toast while a DeskMates tab is
     *                                visible; reminders never set it.
     * @return int subscriptions the push service accepted
     */
    public function sendAll(iterable $subscriptions, string $title, string $body, string $url, bool $backgroundOnly = false): int
    {
        $subscriptions = collect($subscriptions);

        // No VAPID pair configured (clean CI checkout) — sending would only
        // produce a signing error; skip instead of throwing.
        if ($subscriptions->isEmpty() || blank(config('services.vapid.public')) || blank(config('services.vapid.private'))) {
            return 0;
        }

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'background_only' => $backgroundOnly,
        ], JSON_THROW_ON_ERROR);

        $client = $this->makeClient();

        foreach ($subscriptions as $subscription) {
            $client->queueNotification(
                new Subscription(
                    $subscription->endpoint,
                    $subscription->p256dh,
                    $subscription->auth,
                    // rfc8291: what browsers actually speak; the library's
                    // legacy default (aesgcm) predates it.
                    ContentEncoding::aes128gcm,
                ),
                $payload,
            );
        }

        $sent = 0;

        foreach ($client->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;

                continue;
            }

            $subscription = $subscriptions->firstWhere('endpoint', $report->getEndpoint());

            // PRD 4.10: the endpoint is gone — stop sending to it forever.
            if ($report->isSubscriptionExpired()) {
                $subscription?->delete();

                continue;
            }

            Log::warning('Web Push delivery failed', [
                'endpoint' => $report->getEndpoint(),
                'reason' => $report->getReason(),
            ]);
        }

        return $sent;
    }

    private function makeClient(): WebPush
    {
        return new WebPush(
            auth: [
                'VAPID' => [
                    'subject' => config('services.vapid.subject'),
                    'publicKey' => config('services.vapid.public'),
                    'privateKey' => config('services.vapid.private'),
                ],
            ],
            client: $this->httpClient,
        );
    }
}
