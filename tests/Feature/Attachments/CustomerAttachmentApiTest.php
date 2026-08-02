<?php

declare(strict_types=1);

namespace Tests\Feature\Attachments;

use App\Modules\Customer\Models\Customer;
use App\Services\AttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * HTTP tests for customer attachment routes (upload, list, download, view, delete, auth, scope).
 *
 * Hits the real tenant API with Host + Bearer token. Run with:
 * php artisan test --filter=CustomerAttachmentApiTest
 */
class CustomerAttachmentApiTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private string $token;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant('attach_api');
        config([
            'attachments.disk' => 'local',
            'attachments.async.enabled' => false,
            'attachments.virus_scan.driver' => 'null',
        ]);
        $this->token = $this->tenantBearerToken();

        $this->tenant->run(function (): void {
            $this->customer = $this->createCustomer();
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownTenant();

        parent::tearDown();
    }

    public function test_upload_list_show_download_view_and_delete_attachment(): void
    {
        $upload = $this->asTenantRequest($this->token)
            ->post($this->tenantUrl("/customers/{$this->customer->id}/attachments"), [
                'file' => UploadedFile::fake()->create('invoice.pdf', 96, 'application/pdf'),
            ]);

        $upload->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.file_name', 'invoice.pdf')
            ->assertJsonPath('data.attachable_type', 'customer')
            ->assertJsonPath('data.viewer_category', 'pdf')
            ->assertJsonPath('data.can_preview', true);

        $attachmentId = (string) $upload->json('data.id');
        $this->assertNotSame('', $attachmentId);

        $this->asTenantRequest($this->token)
            ->get($this->tenantUrl("/customers/{$this->customer->id}/attachments"))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $attachmentId);

        $show = $this->asTenantRequest($this->token)
            ->get($this->tenantUrl("/customers/{$this->customer->id}/attachments/{$attachmentId}"))
            ->assertOk()
            ->assertJsonPath('data.id', $attachmentId);

        $this->assertStringContainsString('/download', (string) $show->json('data.download_url'));
        $this->assertStringContainsString('/view', (string) $show->json('data.view_url'));

        $this->asTenantRequest($this->token)
            ->get($this->tenantUrl("/customers/{$this->customer->id}/attachments/{$attachmentId}/download"))
            ->assertOk();

        $this->asTenantRequest($this->token)
            ->get($this->tenantUrl("/customers/{$this->customer->id}/attachments/{$attachmentId}/view"))
            ->assertOk();

        $this->asTenantRequest($this->token)
            ->delete($this->tenantUrl("/customers/{$this->customer->id}/attachments/{$attachmentId}"))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->tenant->run(function () use ($attachmentId): void {
            $this->assertSoftDeleted('attachments', ['id' => $attachmentId]);
        });
    }

    public function test_upload_requires_file(): void
    {
        $response = $this->asTenantRequest($this->token)
            ->post($this->tenantUrl("/customers/{$this->customer->id}/attachments"), []);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.file', fn ($errors) => is_array($errors) && $errors !== []);
    }

    public function test_attachment_cannot_be_accessed_under_another_customer(): void
    {
        $attachmentId = null;
        $otherCustomerId = null;

        $this->tenant->run(function () use (&$attachmentId, &$otherCustomerId): void {
            $other = $this->createCustomer('Other Customer');
            $otherCustomerId = (string) $other->id;

            $attachment = app(AttachmentService::class)->store(
                $this->customer,
                UploadedFile::fake()->create('scoped.pdf', 20, 'application/pdf'),
                null,
            );
            $attachmentId = (string) $attachment->id;
        });

        $this->asTenantRequest($this->token)
            ->get($this->tenantUrl("/customers/{$otherCustomerId}/attachments/{$attachmentId}/download"))
            ->assertNotFound()
            ->assertJsonPath('code', 'CUSTOMER_ATTACHMENT_SCOPE_MISMATCH')
            ->assertJsonPath('message', 'Attachment not found for this customer.');
    }

    public function test_unauthenticated_upload_is_rejected(): void
    {
        $this->asTenantRequest()
            ->post($this->tenantUrl("/customers/{$this->customer->id}/attachments"), [
                'file' => UploadedFile::fake()->create('nope.pdf', 10, 'application/pdf'),
            ])
            ->assertUnauthorized();
    }
}
