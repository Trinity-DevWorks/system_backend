<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\InvoiceProof\Support\CompanySafeAbi;
use Tests\TestCase;

class CompanySafeAbiTest extends TestCase
{
    public function test_decode_owner_from_word(): void
    {
        $word = str_repeat('0', 24).'70997970c51812dc3a010c7d01b50e0d17dc79c8';

        $this->assertSame(
            '0x70997970c51812dc3a010c7d01b50e0d17dc79c8',
            CompanySafeAbi::decodeOwner('0x'.$word),
        );
    }

    public function test_decode_owners_array(): void
    {
        $offset = str_pad('20', 64, '0', STR_PAD_LEFT);
        $count = str_pad('1', 64, '0', STR_PAD_LEFT);
        $owner = str_repeat('0', 24).'70997970c51812dc3a010c7d01b50e0d17dc79c8';

        $this->assertSame(
            ['0x70997970c51812dc3a010c7d01b50e0d17dc79c8'],
            CompanySafeAbi::decodeOwners('0x'.$offset.$count.$owner),
        );
    }

    public function test_decode_owners_rejects_short_payload(): void
    {
        $this->assertNull(CompanySafeAbi::decodeOwners('0x'.str_repeat('0', 64)));
    }
}
