<?php

declare(strict_types=1);

namespace Tests\Unit\DocumentEmail;

use App\Modules\DocumentEmail\Services\EmailTemplateService;
use App\Modules\DocumentEmail\Support\DocumentEmailRegistry;
use PHPUnit\Framework\TestCase;

class DocumentEmailRegistryTest extends TestCase
{
    public function test_every_type_is_wired_to_a_model_a_pdf_and_a_permission(): void
    {
        $this->assertCount(12, DocumentEmailRegistry::types());

        $rbac = require __DIR__.'/../../../config/rbac.php';
        foreach (DocumentEmailRegistry::types() as $type) {
            $def = DocumentEmailRegistry::definition($type);
            $this->assertTrue(class_exists($def['model']), $type.': model');
            $this->assertArrayHasKey($def['resource'], $rbac['resources'], $type.': RBAC resource');
            $this->assertContains($def['action'], $rbac['resources'][$def['resource']]['actions'], $type.': RBAC action');

            if (DocumentEmailRegistry::isStatement($type)) {
                $this->assertNull($def['pdf']);

                continue;
            }
            $this->assertNotNull($def['pdf']);
            $this->assertTrue(method_exists($def['pdf'], 'render'), $type.': render()');
            $this->assertTrue(method_exists($def['pdf'], 'downloadFilename'), $type.': downloadFilename()');
            $this->assertNotEmpty($def['statuses'], $type.': statuses');
            $this->assertNotNull($def['number'], $type.': number field');
        }
    }

    public function test_every_type_has_a_default_template_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $templates = (require __DIR__.'/../../../lang/'.$locale.'/document_emails.php')['templates'];
            foreach (DocumentEmailRegistry::types() as $type) {
                $this->assertNotEmpty($templates[$type]['subject'] ?? null, "$locale $type subject");
                $this->assertNotEmpty($templates[$type]['body'] ?? null, "$locale $type body");
            }
        }
    }

    public function test_placeholders_are_filled_and_unknown_ones_kept(): void
    {
        $this->assertSame(
            'Invoice INV-1 for ACME, total 100.00 USD {unknown}',
            EmailTemplateService::fill('Invoice {number} for {party}, total {total} {unknown}', [
                'number' => 'INV-1',
                'party' => 'ACME',
                'total' => '100.00 USD',
            ]),
        );
    }
}
