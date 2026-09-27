<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\InvoiceProof\Models\InvoiceSnapshot;
use RuntimeException;
use Tests\TestCase;

/**
 * Checks invoice snapshots reject Eloquent update and delete.
 */
class InvoiceSnapshotImmutabilityTest extends TestCase
{
    public function test_existing_snapshot_cannot_be_updated(): void
    {
        $snapshot = $this->existingSnapshot();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invoice snapshots are immutable and cannot be updated.');

        $snapshot->update(['content_hash' => str_repeat('b', 64)]);
    }

    public function test_existing_snapshot_cannot_be_deleted(): void
    {
        $snapshot = $this->existingSnapshot();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invoice snapshots are immutable and cannot be deleted.');

        $snapshot->delete();
    }

    private function existingSnapshot(): InvoiceSnapshot
    {
        $snapshot = new InvoiceSnapshot;
        $snapshot->id = '11111111-1111-4111-8111-111111111111';
        $snapshot->exists = true;
        $snapshot->syncOriginal();

        return $snapshot;
    }
}
