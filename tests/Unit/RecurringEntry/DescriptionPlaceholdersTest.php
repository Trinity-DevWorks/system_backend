<?php

declare(strict_types=1);

namespace Tests\Unit\RecurringEntry;

use App\Modules\RecurringEntry\Support\DescriptionPlaceholders;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DescriptionPlaceholdersTest extends TestCase
{
    public function test_known_tokens_are_replaced_and_unknown_tokens_stay(): void
    {
        $text = DescriptionPlaceholders::render(
            'Rent {month_name} {year} {period} {quarter} {date} {day} {month} {unknown}',
            Carbon::parse('2026-12-15'),
        );

        $this->assertNotNull($text);
        $this->assertStringContainsString('2026', $text);
        $this->assertStringContainsString('2026-12', $text);
        $this->assertStringContainsString('Q4', $text);
        $this->assertStringContainsString('2026-12-15', $text);
        $this->assertStringContainsString('15', $text);
        $this->assertStringContainsString('12', $text);
        $this->assertStringContainsString('{unknown}', $text);
    }

    public function test_null_stays_null(): void
    {
        $this->assertNull(DescriptionPlaceholders::render(null, Carbon::parse('2026-01-01')));
    }
}
