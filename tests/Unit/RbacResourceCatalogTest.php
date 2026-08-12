<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Rbac\RbacResourceCatalog;
use Tests\TestCase;

class RbacResourceCatalogTest extends TestCase
{
    public function test_audits_allows_view_and_export_only(): void
    {
        $this->assertSame(['view', 'export'], RbacResourceCatalog::actions('audits'));
        $this->assertTrue(RbacResourceCatalog::allows('audits', 'view'));
        $this->assertTrue(RbacResourceCatalog::allows('audits', 'export'));
        $this->assertFalse(RbacResourceCatalog::allows('audits', 'add'));
        $this->assertFalse(RbacResourceCatalog::allows('audits', 'import'));
    }

    public function test_clamp_flags_clears_inapplicable_actions(): void
    {
        $clamped = RbacResourceCatalog::clampFlags('audits', [
            'can_view' => true,
            'can_add' => true,
            'can_edit' => true,
            'can_delete' => true,
            'can_import' => true,
            'can_export' => true,
        ]);

        $this->assertTrue($clamped['can_view']);
        $this->assertFalse($clamped['can_add']);
        $this->assertFalse($clamped['can_edit']);
        $this->assertFalse($clamped['can_delete']);
        $this->assertFalse($clamped['can_import']);
        $this->assertTrue($clamped['can_export']);
    }

    public function test_users_keep_crud_without_import_export(): void
    {
        $this->assertSame(
            ['view', 'add', 'edit', 'delete'],
            RbacResourceCatalog::actions('users'),
        );
        $this->assertFalse(RbacResourceCatalog::allows('users', 'import'));
        $this->assertFalse(RbacResourceCatalog::allows('users', 'export'));
    }
}
