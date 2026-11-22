<?php

declare(strict_types=1);

namespace App\Modules\DocumentEmail\Services;

use App\Modules\DocumentEmail\Models\EmailTemplate;
use App\Modules\DocumentEmail\Support\DocumentEmailRegistry;

/**
 * Subject / body per document type and language: the company's own text, or the default.
 */
final class EmailTemplateService
{
    public const LOCALES = ['en', 'ar'];

    public const PLACEHOLDERS = ['party', 'number', 'date', 'total', 'due_date', 'company', 'period'];

    /**
     * @return array{subject: string, body: string, custom: bool}
     */
    public function get(string $type, string $locale): array
    {
        $row = EmailTemplate::query()->where('document_type', $type)->where('locale', $locale)->first();
        if ($row !== null) {
            return ['subject' => $row->subject, 'body' => $row->body, 'custom' => true];
        }

        return [...$this->default($type, $locale), 'custom' => false];
    }

    /**
     * @return array{subject: string, body: string}
     */
    public function default(string $type, string $locale): array
    {
        /** @var array{subject?: string, body?: string}|string $text */
        $text = trans('document_emails.templates.'.$type, [], $locale);

        return [
            'subject' => is_array($text) ? (string) ($text['subject'] ?? '') : '',
            'body' => is_array($text) ? (string) ($text['body'] ?? '') : '',
        ];
    }

    /**
     * Every type × language, for the settings screen.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $rows = EmailTemplate::query()->get()->keyBy(fn (EmailTemplate $t): string => $t->document_type.'|'.$t->locale);
        $out = [];
        foreach (DocumentEmailRegistry::types() as $type) {
            foreach (self::LOCALES as $locale) {
                $row = $rows->get($type.'|'.$locale);
                $default = $this->default($type, $locale);
                $out[] = [
                    'document_type' => $type,
                    'locale' => $locale,
                    'subject' => $row->subject ?? $default['subject'],
                    'body' => $row->body ?? $default['body'],
                    'custom' => $row !== null,
                    'default_subject' => $default['subject'],
                    'default_body' => $default['body'],
                ];
            }
        }

        return $out;
    }

    public function save(string $type, string $locale, string $subject, string $body): void
    {
        EmailTemplate::query()->updateOrCreate(
            ['document_type' => $type, 'locale' => $locale],
            ['subject' => $subject, 'body' => $body],
        );
    }

    public function reset(string $type, string $locale): void
    {
        EmailTemplate::query()->where('document_type', $type)->where('locale', $locale)->delete();
    }

    /**
     * Fills {party}, {number}... Unknown placeholders are left as typed.
     *
     * @param  array<string, string>  $values
     */
    public static function fill(string $text, array $values): string
    {
        $map = [];
        foreach ($values as $key => $value) {
            $map['{'.$key.'}'] = $value;
        }

        return strtr($text, $map);
    }
}
