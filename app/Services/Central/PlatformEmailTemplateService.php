<?php

declare(strict_types=1);

namespace App\Services\Central;

use App\Models\Central\PlatformEmailTemplate;

/**
 * Texts of the platform (account) emails, per type and language. A saved row overrides the
 * default from lang/{locale}/platform_emails.php; deleting it goes back to the default.
 */
final class PlatformEmailTemplateService
{
    public const TYPES = ['password_reset'];

    public const LOCALES = ['en', 'ar'];

    public const PLACEHOLDERS = ['name', 'platform', 'minutes'];

    /**
     * @return array{subject: string, body: string, custom: bool}
     */
    public function get(string $type, string $locale): array
    {
        $row = PlatformEmailTemplate::query()->where('type', $type)->where('locale', $locale)->first();
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
        $text = trans('platform_emails.templates.'.$type, [], $locale);

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
        $rows = PlatformEmailTemplate::query()->get()->keyBy(fn (PlatformEmailTemplate $t): string => $t->type.'|'.$t->locale);
        $out = [];
        foreach (self::TYPES as $type) {
            foreach (self::LOCALES as $locale) {
                $row = $rows->get($type.'|'.$locale);
                $default = $this->default($type, $locale);
                $out[] = [
                    'type' => $type,
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
        PlatformEmailTemplate::query()->updateOrCreate(
            ['type' => $type, 'locale' => $locale],
            ['subject' => $subject, 'body' => $body],
        );
    }

    public function reset(string $type, string $locale): void
    {
        PlatformEmailTemplate::query()->where('type', $type)->where('locale', $locale)->delete();
    }

    /**
     * Fills {name}, {platform}... Unknown placeholders are left as typed.
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
