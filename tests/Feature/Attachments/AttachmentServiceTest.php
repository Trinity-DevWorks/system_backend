<?php

declare(strict_types=1);

namespace Tests\Feature\Attachments;

use App\Enums\AttachmentProcessingStatus;
use App\Enums\AttachmentScanStatus;
use App\Enums\AttachmentViewerCategory;
use App\Jobs\ProcessAttachmentJob;
use App\Models\Attachment;
use App\Services\AttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Tests the AttachmentService directly (no HTTP): store, checksum, soft/force delete,
 * orphan cleanup, quota, and async job dispatch.
 *
 * Requires local Postgres (Stancl tenant schemas). Skipped in CI via --exclude-group=attachments.
 * Run with: php artisan test --group=attachments
 */
#[Group('attachments')]
class AttachmentServiceTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private AttachmentService $attachments;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'attachments.disk' => 'local',
            'attachments.async.enabled' => false,
            'attachments.virus_scan.driver' => 'null',
            'attachments.max_per_record' => 50,
            'attachments.purge_files_on_soft_delete' => false,
        ]);

        $this->setUpTenant();
        $this->attachments = app(AttachmentService::class);
    }

    protected function tearDown(): void
    {
        $this->tearDownTenant();

        parent::tearDown();
    }

    public function test_store_persists_metadata_checksum_and_writes_file(): void
    {
        $this->tenant->run(function (): void {
            Storage::fake('local');

            $customer = $this->createCustomer();
            $file = UploadedFile::fake()->create('quote.pdf', 120, 'application/pdf');

            $attachment = $this->attachments->store($customer, $file, (string) $this->tenantUser->id);

            $this->assertSame('customer', $attachment->attachable_type);
            $this->assertSame((string) $customer->id, (string) $attachment->attachable_id);
            $this->assertSame('quote.pdf', $attachment->file_name);
            $this->assertSame(AttachmentViewerCategory::Pdf, $attachment->viewer_category);
            $this->assertTrue($attachment->can_preview);
            $this->assertSame((string) $this->tenantUser->id, (string) $attachment->uploaded_by);
            $this->assertFalse($attachment->is_primary);
            $this->assertNotNull($attachment->checksum);
            $this->assertSame('sha256', $attachment->checksum_algo);
            $this->assertSame(AttachmentProcessingStatus::Ready, $attachment->processing_status);
            $this->assertSame(AttachmentScanStatus::Skipped, $attachment->scan_status);

            Storage::disk('local')->assertExists($attachment->file_path);
            $this->assertDatabaseHas('attachments', [
                'id' => $attachment->id,
                'attachable_type' => 'customer',
                'attachable_id' => $customer->id,
                'file_name' => 'quote.pdf',
            ]);
        });
    }

    public function test_store_classifies_images_and_office_documents(): void
    {
        $this->tenant->run(function (): void {
            Storage::fake('local');
            $customer = $this->createCustomer();

            $image = $this->attachments->store(
                $customer,
                UploadedFile::fake()->image('logo.png', 40, 40),
                null,
            );
            $this->assertSame(AttachmentViewerCategory::Image, $image->viewer_category);
            $this->assertTrue($image->can_preview);

            $word = $this->attachments->store(
                $customer,
                UploadedFile::fake()->create(
                    'spec.docx',
                    80,
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                ),
                null,
            );
            $this->assertSame(AttachmentViewerCategory::Document, $word->viewer_category);

            $excel = $this->attachments->store(
                $customer,
                UploadedFile::fake()->create(
                    'prices.xlsx',
                    80,
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ),
                null,
            );
            $this->assertSame(AttachmentViewerCategory::Document, $excel->viewer_category);
        });
    }

    public function test_failed_db_write_deletes_orphan_file(): void
    {
        $this->tenant->run(function (): void {
            Storage::fake('local');
            $customer = $this->createCustomer();

            $writtenPaths = [];

            Attachment::creating(function (Attachment $attachment) use (&$writtenPaths): void {
                $writtenPaths[] = $attachment->file_path;

                throw new RuntimeException('Forced attachment insert failure.');
            });

            try {
                $this->attachments->store(
                    $customer,
                    UploadedFile::fake()->create('orphan.pdf', 50, 'application/pdf'),
                    null,
                );
                $this->fail('Expected store() to rethrow the forced failure.');
            } catch (RuntimeException $e) {
                $this->assertSame('Forced attachment insert failure.', $e->getMessage());
            }

            $this->assertNotEmpty($writtenPaths);
            foreach ($writtenPaths as $path) {
                Storage::disk('local')->assertMissing($path);
            }

            $this->assertSame(0, Attachment::query()->count());
            $this->assertSame(0, $customer->attachments()->count());
        });
    }

    public function test_delete_soft_deletes_and_keeps_file_by_default(): void
    {
        $this->tenant->run(function (): void {
            Storage::fake('local');
            $customer = $this->createCustomer();

            $attachment = $this->attachments->store(
                $customer,
                UploadedFile::fake()->create('remove-me.pdf', 40, 'application/pdf'),
                null,
            );
            $path = $attachment->file_path;
            Storage::disk('local')->assertExists($path);

            $this->attachments->delete($attachment);

            $this->assertSoftDeleted('attachments', ['id' => $attachment->id]);
            Storage::disk('local')->assertExists($path);
        });
    }

    public function test_force_delete_removes_row_and_file(): void
    {
        $this->tenant->run(function (): void {
            Storage::fake('local');
            $customer = $this->createCustomer();

            $attachment = $this->attachments->store(
                $customer,
                UploadedFile::fake()->create('purge-me.pdf', 40, 'application/pdf'),
                null,
            );
            $path = $attachment->file_path;

            $this->attachments->forceDelete($attachment);

            $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
            Storage::disk('local')->assertMissing($path);
        });
    }

    public function test_list_for_returns_attachments_for_attachable(): void
    {
        $this->tenant->run(function (): void {
            Storage::fake('local');
            $customerA = $this->createCustomer('Customer A');
            $customerB = $this->createCustomer('Customer B');

            $this->attachments->store($customerA, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), null);
            $this->attachments->store($customerB, UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'), null);

            $listed = $this->attachments->listFor($customerA);

            $this->assertCount(1, $listed);
            $this->assertSame('a.pdf', $listed->first()?->file_name);
        });
    }

    public function test_quota_blocks_excess_uploads(): void
    {
        $this->tenant->run(function (): void {
            Storage::fake('local');
            config(['attachments.max_per_record' => 1]);
            $customer = $this->createCustomer();

            $this->attachments->store($customer, UploadedFile::fake()->create('one.pdf', 10, 'application/pdf'), null);

            try {
                $this->attachments->store($customer, UploadedFile::fake()->create('two.pdf', 10, 'application/pdf'), null);
                $this->fail('Expected quota abort.');
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
                $this->assertSame('ATTACHMENT_QUOTA_EXCEEDED', $e->getHeaders()['X-Error-Code'] ?? null);
            }
        });
    }

    public function test_large_files_dispatch_async_processing_job(): void
    {
        Queue::fake();

        $this->tenant->run(function (): void {
            Storage::fake('local');
            config([
                'attachments.async.enabled' => true,
                'attachments.async.force' => false,
                'attachments.async.threshold_kilobytes' => 1,
            ]);

            $customer = $this->createCustomer();
            // UploadedFile fake size is in KB.
            $attachment = $this->attachments->store(
                $customer,
                UploadedFile::fake()->create('big.pdf', 2, 'application/pdf'),
                null,
            );

            $this->assertSame(AttachmentProcessingStatus::Pending, $attachment->processing_status);
            Queue::assertPushed(ProcessAttachmentJob::class, function (ProcessAttachmentJob $job) use ($attachment): bool {
                return $job->attachmentId === $attachment->id;
            });
        });
    }
}
