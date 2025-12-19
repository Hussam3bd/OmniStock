<?php

namespace App\Jobs;

use App\Services\Accounting\RefundDetectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DetectTransactionRefunds implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $accountId,
        public int $lookbackDays = 90,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(RefundDetectionService $service): void
    {
        $service->detectRefunds($this->accountId, $this->lookbackDays);
    }
}
