<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Stock\Enums\BundleExplosionStatus;
use App\Modules\Inventory\Stock\Models\BundleExplosion;
use App\Modules\Inventory\Stock\Support\BundleExplosionRules;
use App\Modules\Inventory\Stock\Support\BundleExplosionScale;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BundleExplosionRulesTest extends TestCase
{
    public function test_status_values_are_draft_and_posted(): void
    {
        $this->assertSame(['draft', 'posted'], BundleExplosionStatus::values());
    }

    public function test_assert_draft_rejects_posted_document(): void
    {
        $document = new BundleExplosion(['status' => BundleExplosionStatus::Posted]);

        $this->expectException(HttpException::class);
        BundleExplosionRules::assertDraft($document);
    }

    public function test_scale_multiplies_component_qty_by_kit_count(): void
    {
        $factor = BundleExplosionScale::factor('3.000000');
        $this->assertSame('6.000000', BundleExplosionScale::apply('2.000000', $factor));
    }

    public function test_zero_kit_qty_is_rejected(): void
    {
        $this->expectException(HttpException::class);
        BundleExplosionScale::factor('0');
    }
}
