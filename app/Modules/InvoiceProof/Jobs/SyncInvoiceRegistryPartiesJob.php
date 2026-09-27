<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Jobs;

use App\Models\Tenant;
use App\Modules\InvoiceProof\Services\InvoiceChainRegistrationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fills empty InvoiceRegistry party slots after company/customer wallets are set.
 */
class SyncInvoiceRegistryPartiesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 8;

    public int $uniqueFor = 180;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(
        public readonly ?string $tenantId,
        public readonly string $proofId,
    ) {}

    public function uniqueId(): string
    {
        return ($this->tenantId ?? 'central').':parties:'.$this->proofId;
    }

    public function handle(InvoiceChainRegistrationService $invoiceChainRegistrationService): void
    {
        $run = fn () => $invoiceChainRegistrationService->syncParties($this->proofId);

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
