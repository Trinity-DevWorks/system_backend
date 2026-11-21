<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Requests;

final class JournalLinesRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(bool $required): array
    {
        return [
            'lines' => [$required ? 'present' : 'sometimes', 'array', 'max:500'],
            'lines.*.account_id' => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'lines.*.exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.party_type' => ['nullable', 'string', 'in:customer,supplier'],
            'lines.*.party_id' => ['nullable', 'uuid'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
