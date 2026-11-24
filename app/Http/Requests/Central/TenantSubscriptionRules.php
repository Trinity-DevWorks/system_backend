<?php

declare(strict_types=1);

namespace App\Http\Requests\Central;

use Illuminate\Support\Fluent;
use Illuminate\Validation\Rule;

/**
 * Plan + subscription period fields shared by tenant create and `PUT tenants/{id}/subscription`.
 * An empty `subscription_ends_at` means no end date. The start can be backdated but not in the
 * future: nothing waits for it, so a future start would only mislead ("Started on" a later day).
 */
final class TenantSubscriptionRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where('is_active', true)],
            // One day of slack: the admin's "today" can already be tomorrow in UTC (the client blocks future days locally).
            'subscription_starts_at' => ['nullable', 'date', 'before_or_equal:'.now()->addDay()->toDateString()],
            'subscription_ends_at' => ['nullable', 'date', ...self::whenFilled('subscription_starts_at', 'after_or_equal')],
            // The trial sits inside the subscription: not before its start, not after its end.
            'trial_ends_at' => [
                'nullable',
                'date',
                ...self::whenFilled('subscription_starts_at', 'after_or_equal'),
                ...self::whenFilled('subscription_ends_at', 'before_or_equal'),
            ],
        ];
    }

    /**
     * Compare with another date field only when it has a value (an empty start means today,
     * an empty end means no end); comparing against an empty field would always fail.
     *
     * @return list<mixed>
     */
    private static function whenFilled(string $field, string $rule): array
    {
        return [Rule::when(
            fn (Fluent $input): bool => filled($input->get($field)),
            ["{$rule}:{$field}"]
        )];
    }
}
