<?php

declare(strict_types=1);

namespace App\Modules\DocumentEmail\Services;

use App\Models\User;
use App\Modules\CompanySetting\Support\TenantDisplayFormat;
use App\Modules\Customer\Models\Customer;
use App\Modules\DocumentEmail\Jobs\SendDocumentEmailJob;
use App\Modules\DocumentEmail\Models\DocumentEmail;
use App\Modules\DocumentEmail\Support\DocumentEmailRegistry;
use App\Modules\Supplier\Models\Supplier;
use App\Services\PermissionService;
use App\Support\DocumentPdf\DocumentPdfOptions;
use App\Support\Export\LedgerStatementBuilder;
use App\Support\Export\TabularExportService;
use App\Support\OpeningDocumentService;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Emailing a document: what to prefill, the checks before sending, the queued send and its history.
 */
final class DocumentEmailService
{
    public const MAX_RECIPIENTS = 10;

    public function __construct(
        private readonly EmailSettingService $settings,
        private readonly EmailTemplateService $templates,
        private readonly PermissionService $permissions,
    ) {}

    public function authorize(User $user, string $type): void
    {
        $def = DocumentEmailRegistry::definition($type);
        if (! $this->permissions->userHas($def['resource'], 'view', $user)
            || ! $this->permissions->userHas($def['resource'], $def['action'], $user)) {
            abort(403);
        }
    }

    public function find(string $type, string $id): Model
    {
        $class = DocumentEmailRegistry::definition($type)['model'];

        /** @var Model */
        return $class::query()->findOrFail($id);
    }

    /** Only documents that may be printed may be emailed (no drafts, no cancelled orders). */
    public function assertSendable(string $type, Model $document): void
    {
        $statuses = DocumentEmailRegistry::definition($type)['statuses'];
        if ($statuses !== null) {
            $status = $document->getAttribute('status');
            $value = $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
            if (! in_array($value, $statuses, true)) {
                abort(422, 'This document can\'t be emailed in its current status.', ['X-Error-Code' => 'EMAIL_DOCUMENT_NOT_SENDABLE']);
            }
        }
        if ($type === 'sales_invoice') {
            OpeningDocumentService::assertNotOpening($document);
        }
    }

    public function party(string $type, Model $document): Customer|Supplier|null
    {
        if (DocumentEmailRegistry::isStatement($type)) {
            /** @var Customer|Supplier $document */
            return $document;
        }
        $party = DocumentEmailRegistry::definition($type)['party'] === 'customer'
            ? $document->getRelationValue('customer')
            : $document->getRelationValue('supplier');

        return $party instanceof Customer || $party instanceof Supplier ? $party : null;
    }

    /**
     * Prefill for the send dialog.
     *
     * @param  array{date_from?: string|null, date_to?: string|null, currency_id?: int|null}  $meta
     * @return array<string, mixed>
     */
    public function defaults(string $type, string $id, string $locale, array $meta = []): array
    {
        $document = $this->find($type, $id);
        $party = $this->party($type, $document);
        $template = $this->templates->get($type, $locale);
        $values = $this->values($type, $document, $party, $locale, $meta);

        $suggestions = [];
        if ($party !== null) {
            if (filled($party->email)) {
                $suggestions[strtolower((string) $party->email)] = ['email' => (string) $party->email, 'label' => (string) $party->name];
            }
            foreach ($party->contacts()->get() as $contact) {
                $email = (string) ($contact->email ?? '');
                if ($email !== '' && ! isset($suggestions[strtolower($email)])) {
                    $suggestions[strtolower($email)] = [
                        'email' => $email,
                        'label' => trim((string) $contact->name.($contact->position ? ' · '.$contact->position : '')),
                    ];
                }
            }
        }

        $settings = $this->settings->settings();

        return [
            'document_type' => $type,
            'document_id' => $id,
            'document_number' => $this->number($type, $document),
            'party_name' => $party?->name,
            'to' => $party !== null && filled($party->email) ? [(string) $party->email] : [],
            'suggestions' => array_values($suggestions),
            'subject' => EmailTemplateService::fill($template['subject'], $values),
            'body' => EmailTemplateService::fill($template['body'], $values),
            'locale' => $locale,
            'attachment_name' => $this->attachmentName($type, $document),
            'configured' => $settings->isReady(),
            // ready | disabled (filled in, "Send emails" is off) | incomplete (server / sender missing)
            'email_status' => $settings->isReady() ? 'ready' : ($settings->isComplete() ? 'disabled' : 'incomplete'),
            'sendable' => $this->isSendable($type, $document),
            'history' => $this->history($type, $id),
        ];
    }

    /**
     * Saves the email as queued and hands it to the queue.
     *
     * @param  array{to: list<string>, cc?: list<string>|null, subject: string, body: string, locale: string, date_from?: string|null, date_to?: string|null, currency_id?: int|null}  $data
     */
    public function send(User $user, string $type, string $id, array $data): DocumentEmail
    {
        $this->settings->assertReady($this->settings->settings());
        $document = $this->find($type, $id);
        $this->assertSendable($type, $document);

        $to = $this->cleanAddresses($data['to']);
        $cc = $this->cleanAddresses($data['cc'] ?? []);
        if ($to === []) {
            abort(422, 'Add at least one recipient.', ['X-Error-Code' => 'EMAIL_NO_RECIPIENT']);
        }

        $meta = DocumentEmailRegistry::isStatement($type) ? [
            'date_from' => $data['date_from'] ?? null,
            'date_to' => $data['date_to'] ?? null,
            'currency_id' => isset($data['currency_id']) ? (int) $data['currency_id'] : null,
        ] : null;

        $email = DocumentEmail::query()->create([
            'document_type' => $type,
            'document_id' => $id,
            'document_number' => $this->number($type, $document),
            'to' => $to,
            'cc' => $cc,
            'subject' => $data['subject'],
            'body' => $data['body'],
            'locale' => $data['locale'],
            'attachment_name' => $this->attachmentName($type, $document),
            'status' => DocumentEmail::STATUS_QUEUED,
            'meta' => $meta,
            'user_id' => $user->id,
        ]);

        SendDocumentEmailJob::dispatch($email->id)->afterCommit();

        return $email->load('user');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(string $type, string $id): array
    {
        return DocumentEmail::query()
            ->with('user:id,name')
            ->where('document_type', $type)
            ->where('document_id', $id)
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (DocumentEmail $e): array => $e->toResponse())
            ->values()
            ->all();
    }

    /**
     * The PDF to attach: [bytes, file name]. Built in the email's language.
     *
     * @return array{0: string, 1: string}
     */
    public function attachment(DocumentEmail $email): array
    {
        $type = $email->document_type;
        $document = $this->find($type, $email->document_id);
        $previous = app()->getLocale();
        app()->setLocale($email->locale);

        try {
            if (DocumentEmailRegistry::isStatement($type)) {
                /** @var Customer|Supplier $document */
                $meta = $email->meta ?? [];
                $statement = app(LedgerStatementBuilder::class)->build(
                    $document,
                    isset($meta['currency_id']) ? (int) $meta['currency_id'] : null,
                    isset($meta['date_from']) ? (string) $meta['date_from'] : null,
                    isset($meta['date_to']) ? (string) $meta['date_to'] : null,
                );

                return [(string) app(TabularExportService::class)->renderPdf($statement)->output(), $this->attachmentName($type, $document)];
            }

            $service = app((string) DocumentEmailRegistry::definition($type)['pdf']);
            $pdf = $service->render($document, $email->locale);

            return [(string) $pdf->output(), (string) $service->downloadFilename($document)];
        } finally {
            app()->setLocale($previous);
        }
    }

    private function isSendable(string $type, Model $document): bool
    {
        try {
            $this->assertSendable($type, $document);

            return true;
        } catch (HttpException) {
            return false;
        }
    }

    private function number(string $type, Model $document): ?string
    {
        $field = DocumentEmailRegistry::definition($type)['number'];
        if ($field === null) {
            $code = $document->getAttribute($type === DocumentEmailRegistry::CUSTOMER_STATEMENT ? 'customer_code' : 'supplier_code');

            return $code !== null ? (string) $code : null;
        }
        $value = $document->getAttribute($field);

        return $value !== null ? (string) $value : null;
    }

    private function attachmentName(string $type, Model $document): string
    {
        if (DocumentEmailRegistry::isStatement($type)) {
            return DocumentPdfOptions::sanitizeFilename('statement-'.($this->number($type, $document) ?? ''), 'statement');
        }
        $service = app((string) DocumentEmailRegistry::definition($type)['pdf']);

        return (string) $service->downloadFilename($document);
    }

    /**
     * Values for {party}, {number}, {date}, {total}, {due_date}, {company}, {period}.
     *
     * @param  array{date_from?: string|null, date_to?: string|null, currency_id?: int|null}  $meta
     * @return array<string, string>
     */
    private function values(string $type, Model $document, Customer|Supplier|null $party, string $locale, array $meta): array
    {
        $def = DocumentEmailRegistry::definition($type);
        $date = fn (?string $field): string => $field !== null && $document->getAttribute($field) !== null
            ? TenantDisplayFormat::date($document->getAttribute($field))
            : '—';

        $total = '—';
        if ($def['total'] !== null && $document->getAttribute($def['total']) !== null) {
            $currency = $document->getRelationValue('currency');
            $code = $currency !== null ? (string) $currency->getAttribute('code') : '';
            $total = trim(TenantDisplayFormat::money($document->getAttribute($def['total'])).' '.$code);
        }

        $from = filled($meta['date_from'] ?? null) ? TenantDisplayFormat::date($meta['date_from']) : null;
        $to = filled($meta['date_to'] ?? null) ? TenantDisplayFormat::date($meta['date_to']) : null;
        $period = match (true) {
            $from !== null && $to !== null => (string) trans('document_emails.period_range', ['from' => $from, 'to' => $to], $locale),
            $from !== null => (string) trans('document_emails.period_from', ['from' => $from], $locale),
            $to !== null => (string) trans('document_emails.period_until', ['to' => $to], $locale),
            default => (string) trans('document_emails.period_all', [], $locale),
        };

        return [
            'party' => (string) ($party->name ?? ''),
            'number' => $this->number($type, $document) ?? '',
            'date' => $date($def['date']),
            'total' => $total,
            'due_date' => $date($def['due']),
            'company' => $this->settings->companyName(),
            'period' => $period,
        ];
    }

    /**
     * @param  list<string>  $addresses
     * @return list<string>
     */
    private function cleanAddresses(array $addresses): array
    {
        $out = [];
        foreach ($addresses as $address) {
            $address = trim($address);
            if ($address !== '' && ! in_array(strtolower($address), array_map('strtolower', $out), true)) {
                $out[] = $address;
            }
        }

        return $out;
    }
}
