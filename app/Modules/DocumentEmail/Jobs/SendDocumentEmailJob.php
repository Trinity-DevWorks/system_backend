<?php

declare(strict_types=1);

namespace App\Modules\DocumentEmail\Jobs;

use App\Modules\DocumentEmail\Mail\DocumentMail;
use App\Modules\DocumentEmail\Models\DocumentEmail;
use App\Modules\DocumentEmail\Services\DocumentEmailService;
use App\Modules\DocumentEmail\Services\EmailSettingService;
use App\Modules\Inventory\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrder;
use App\Modules\Inventory\Purchasing\Services\PurchaseOrderService;
use App\Modules\Notification\Services\DomainNotificationPublisher;
use App\Modules\Sales\SalesQuotation\Enums\SalesQuotationStatus;
use App\Modules\Sales\SalesQuotation\Models\SalesQuotation;
use App\Modules\Sales\SalesQuotation\Services\SalesQuotationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Builds the PDF and sends one document email through the company's mailbox.
 * Tenancy is restored by stancl's QueueTenancyBootstrapper.
 */
class SendDocumentEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /**
     * Room for the PDF and a slow upload, but under the queue's retry_after (90s) so a
     * slow send is never handed to a second worker while it is still running.
     */
    public int $timeout = 85;

    public function __construct(public readonly string $documentEmailId) {}

    public function handle(EmailSettingService $settingsService, DocumentEmailService $emails): void
    {
        // Claim the email so two workers (or an overlapping retry) never send it twice.
        $claimed = DocumentEmail::query()
            ->whereKey($this->documentEmailId)
            ->where('status', DocumentEmail::STATUS_QUEUED)
            ->update(['status' => DocumentEmail::STATUS_SENDING, 'attempts' => DB::raw('attempts + 1')]);
        $email = DocumentEmail::query()->with('user')->find($this->documentEmailId);
        if ($claimed === 0 || $email === null) {
            return;
        }

        $settings = $settingsService->settings();

        try {
            $settingsService->assertReady($settings);
            [$pdf, $filename] = $emails->attachment($email);

            $mail = new DocumentMail(
                subjectLine: $email->subject,
                bodyText: $email->body,
                companyName: $settingsService->companyName(),
                locale: $email->locale,
                pdf: $pdf,
                filename: $filename,
            );
            $mail->from((string) $settings->from_address, $settings->from_name ?: $settingsService->companyName())
                ->to($email->to)
                ->cc($email->cc ?? []);
            if (filled($settings->reply_to)) {
                $mail->replyTo((string) $settings->reply_to);
            }
            if ($settings->bcc_sender && filled($email->user?->email)) {
                $mail->bcc((string) $email->user?->email);
            }

            $settingsService->mailer($settings)->send($mail);
        } catch (\Throwable $e) {
            $error = $settingsService->cleanError($e, $settings);
            $final = $this->attempts() >= $this->tries || ! $this->isRetryable($e);
            $email->forceFill([
                'status' => $final ? DocumentEmail::STATUS_FAILED : DocumentEmail::STATUS_QUEUED,
                'error' => $error,
            ])->save();

            if (! $final) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);

                return;
            }

            $this->notifyFailed($email);

            return;
        }

        $email->forceFill([
            'status' => DocumentEmail::STATUS_SENT,
            'error' => null,
            'sent_at' => now(),
        ])->save();

        $this->afterSent($email);
    }

    /** Wrong settings or a refused document won't fix themselves: don't retry those. */
    private function isRetryable(\Throwable $e): bool
    {
        return ! $e instanceof HttpExceptionInterface;
    }

    /** A document emailed to the customer / supplier is now "sent": a draft quotation, a confirmed purchase order. */
    private function afterSent(DocumentEmail $email): void
    {
        try {
            if ($email->document_type === 'sales_quotation') {
                $quotation = SalesQuotation::query()->find($email->document_id);
                if ($quotation !== null && $quotation->status === SalesQuotationStatus::Draft) {
                    app(SalesQuotationService::class)->send($quotation, $email->user_id);
                }
            } elseif ($email->document_type === 'purchase_order') {
                $order = PurchaseOrder::query()->find($email->document_id);
                if ($order !== null && $order->status === PurchaseOrderStatus::Confirmed && $order->sent_at === null) {
                    app(PurchaseOrderService::class)->markAsSent($order, $email->user_id);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Could not mark the emailed document as sent.', [
                'type' => $email->document_type,
                'id' => $email->document_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyFailed(DocumentEmail $email): void
    {
        if ($email->user_id === null) {
            return;
        }

        try {
            app(DomainNotificationPublisher::class)->documentEmailFailed($email);
        } catch (\Throwable $e) {
            Log::warning('Could not notify about a failed document email.', ['id' => $email->id, 'error' => $e->getMessage()]);
        }
    }
}
