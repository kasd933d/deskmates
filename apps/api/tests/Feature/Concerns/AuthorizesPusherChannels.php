<?php

namespace Tests\Feature\Concerns;

trait AuthorizesPusherChannels
{
    /**
     * phpunit.xml pins BROADCAST_CONNECTION=null, whose auth() is a no-op —
     * channel authorization must run against a real driver. Pusher signs the
     * auth HMAC locally (no HTTP), so flipping the default connection to
     * pusher with dummy credentials exercises the real response shape while
     * staying offline. Only auth tests use this: actual event broadcasts
     * would hit the network.
     */
    private function usePusherChannelAuth(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher' => [
                'driver' => 'pusher',
                'key' => 'test-key',
                'secret' => 'test-secret',
                'app_id' => '123456',
                'options' => [],
            ],
        ]);

        // channels.php registered its callbacks on the driver that was
        // default at boot (null); re-require so the freshly resolved pusher
        // driver has them too (registration keys by pattern — idempotent).
        require base_path('routes/channels.php');
    }
}
