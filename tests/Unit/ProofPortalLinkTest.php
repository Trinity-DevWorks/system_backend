<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\InvoiceProof\Support\ProofPortalLink;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProofPortalLinkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'blockchain.proof_portal_secret' => 'unit-test-portal-secret',
            'blockchain.proof_portal_ttl_days' => 7,
        ]);
    }

    public function test_issue_includes_matching_hmac(): void
    {
        $issued = ProofPortalLink::issue('tenant', '11111111-1111-4111-8111-111111111111');

        $this->assertArrayHasKey('url', $issued);
        $this->assertArrayHasKey('exp', $issued);
        $this->assertArrayHasKey('sig', $issued);
        $this->assertSame(
            ProofPortalLink::sign('tenant', '11111111-1111-4111-8111-111111111111', $issued['exp']),
            $issued['sig'],
        );
        $this->assertStringContainsString('exp='.$issued['exp'], $issued['url']);
        $this->assertGreaterThan(time(), $issued['exp']);
    }

    public function test_assert_valid_accepts_matching_signature(): void
    {
        $exp = time() + 3600;
        $sig = ProofPortalLink::sign('tenant', 'inv-1', $exp);

        ProofPortalLink::assertValid('tenant', 'inv-1', $exp, $sig);
        $this->addToAssertionCount(1);
    }

    public function test_assert_valid_rejects_missing_stamp(): void
    {
        try {
            ProofPortalLink::assertValid('tenant', 'inv-1', null, null);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('PROOF_LINK_INVALID', $e->getHeaders()['X-Error-Code'] ?? null);
        }
    }

    public function test_assert_valid_rejects_expired_stamp(): void
    {
        $exp = time() - 10;
        $sig = ProofPortalLink::sign('tenant', 'inv-1', $exp);

        try {
            ProofPortalLink::assertValid('tenant', 'inv-1', $exp, $sig);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('PROOF_LINK_EXPIRED', $e->getHeaders()['X-Error-Code'] ?? null);
        }
    }
}
