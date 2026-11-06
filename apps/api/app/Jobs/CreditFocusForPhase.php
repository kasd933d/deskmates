<?php

namespace App\Jobs;

use App\Services\FocusCreditService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * PRD 7.3 "on demand: CreditFocusForPhase" — converts one ended phase's
 * attendance into credited minutes (PRD 4.8). Dispatched by the room clock
 * when a phase closes, by always-on flip detection, and nightly as repair
 * for dispatches lost while the queue was down.
 *
 * Idempotent by construction: the service claims `credited_at` under a lock,
 * and focus_sessions' unique (user, phase) key absorbs duplicate runs.
 */
class CreditFocusForPhase implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $phaseId,
    ) {}

    public function handle(FocusCreditService $credit): void
    {
        $credit->creditPhase($this->phaseId);
    }
}
