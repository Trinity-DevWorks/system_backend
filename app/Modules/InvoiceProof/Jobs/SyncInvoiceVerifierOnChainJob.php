<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Jobs;

use App\Models\Tenant;
use App\Modules\InvoiceProof\Services\InvoiceVerifierService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Writes one company verifier (or its removal) on InvoiceRegistry from the registrar.
 */
class SyncInvoiceVerifierOnChainJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(
        public readonly ?string $tenantId,
        public readonly string $verifierId,
    ) {}

    public function handle(InvoiceVerifierService $invoiceVerifierService): void
    {
        $run = fn () => $invoiceVerifierService->syncOnChain($this->verifierId);

        if ($this->tenantId) {
            $tenant = Tenant::query()->find($this->tenantId);
            if ($tenant === null) {
                return;
            }

            $tenant->run($run);

            return;
        }

        $run();
    }
}
