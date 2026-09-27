<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\InvoiceProof\Support\ProofPortalUnlockChallenge;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProofPortalUnlockChallengeTest extends TestCase
{
    public function test_issue_overwrites_and_consume_is_one_use(): void
    {
        $first = ProofPortalUnlockChallenge::issue('tenant', 'inv-1', 'Acme', 'INV-1');
        $second = ProofPortalUnlockChallenge::issue('tenant', 'inv-1', 'Acme', 'INV-1');

        $this->assertNotSame($first['nonce'], $second['nonce']);
        $this->assertSame($second['nonce'], ProofPortalUnlockChallenge::current('tenant', 'inv-1'));
        $this->assertStringContainsString('nonce:'.$second['nonce'], $second['message']);

        ProofPortalUnlockChallenge::consume('tenant', 'inv-1', $second['nonce']);
        $this->assertNull(ProofPortalUnlockChallenge::current('tenant', 'inv-1'));
        $this->assertFalse(Cache::has('proof-portal-unlock:tenant:inv-1'));
    }
}
