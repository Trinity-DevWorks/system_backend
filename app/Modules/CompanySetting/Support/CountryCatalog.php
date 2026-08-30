<?php

declare(strict_types=1);

namespace App\Modules\CompanySetting\Support;

final class CountryCatalog
{
    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::entries());
    }

    public static function isValid(?string $code): bool
    {
        $normalized = self::normalize($code);

        return $normalized !== null && isset(self::entries()[$normalized]);
    }

    public static function normalize(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $normalized = strtoupper(trim($code));

        return $normalized === '' ? null : $normalized;
    }

    public static function name(string $code, string $locale = 'en'): ?string
    {
        $normalized = self::normalize($code);
        if ($normalized === null || ! isset(self::entries()[$normalized])) {
            return null;
        }

        $resolvedLocale = self::resolveLocale($locale);

        return self::entries()[$normalized][$resolvedLocale];
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public static function listForLocale(string $locale = 'en'): array
    {
        $resolvedLocale = self::resolveLocale($locale);
        $list = [];

        foreach (self::entries() as $code => $names) {
            $list[] = [
                'code' => $code,
                'name' => $names[$resolvedLocale],
            ];
        }

        if (class_exists(\Collator::class)) {
            $collator = new \Collator($resolvedLocale);
            usort(
                $list,
                static fn (array $left, array $right): int => $collator->compare($left['name'], $right['name']) ?: 0
            );
        } else {
            usort(
                $list,
                static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name'])
            );
        }

        return $list;
    }

    /**
     * @return array<string, array{en: string, ar: string}>
     */
    private static function entries(): array
    {
        static $entries;

        return $entries ??= require __DIR__.'/countries.php';
    }

    private static function resolveLocale(string $locale): string
    {
        return str_starts_with(strtolower($locale), 'ar') ? 'ar' : 'en';
    }
}
