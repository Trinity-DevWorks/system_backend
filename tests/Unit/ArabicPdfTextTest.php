<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Export\ArabicPdfText;
use Tests\TestCase;

class ArabicPdfTextTest extends TestCase
{
    public function test_arabic_is_converted_to_joined_presentation_forms(): void
    {
        $shaped = ArabicPdfText::shape('كشف حساب العميل');

        $this->assertNotSame('كشف حساب العميل', $shaped);
        $this->assertMatchesRegularExpression('/[\x{FE70}-\x{FEFF}]/u', $shaped);
    }

    public function test_latin_text_is_left_unchanged(): void
    {
        $this->assertSame('USD 2.25$', ArabicPdfText::shape('USD 2.25$'));
        $this->assertSame('', ArabicPdfText::shape(null));
    }
}
