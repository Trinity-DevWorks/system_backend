<?php

declare(strict_types=1);

namespace App\Modules\Accounting\DTOs;

readonly class JournalLineData
{
    public function __construct(
        public int $accountId,
        public string $debit,
        public string $credit,
        public ?int $currencyId = null,
        public ?string $exchangeRate = null,
        public ?string $amountCurrency = null,
        public ?string $partyType = null,
        public ?string $partyId = null,
        public ?string $description = null,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $text = fn (string $key): ?string => isset($row[$key]) && trim((string) $row[$key]) !== '' ? trim((string) $row[$key]) : null;

        return new self(
            accountId: (int) $row['account_id'],
            debit: (string) ($row['debit'] ?? '0'),
            credit: (string) ($row['credit'] ?? '0'),
            currencyId: isset($row['currency_id']) ? (int) $row['currency_id'] : null,
            exchangeRate: $text('exchange_rate'),
            amountCurrency: $text('amount_currency'),
            partyType: $text('party_type'),
            partyId: $text('party_id'),
            description: $text('description'),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<self>
     */
    public static function listFromArray(array $rows): array
    {
        return array_values(array_map(fn (array $row): self => self::fromArray($row), $rows));
    }
}
