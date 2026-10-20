<?php

declare(strict_types=1);

namespace App\Modules\Accounting\DTOs;

use Illuminate\Foundation\Http\FormRequest;

readonly class JournalEntryData
{
    /**
     * @param  list<JournalLineData>|null  $lines  null keeps the current lines
     */
    public function __construct(
        public string $entryDate,
        public ?string $description,
        public ?int $branchId,
        public ?array $lines,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $data = $request->validated();
        $description = isset($data['description']) ? trim((string) $data['description']) : null;

        return new self(
            entryDate: (string) $data['entry_date'],
            description: $description !== '' ? $description : null,
            branchId: isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            lines: array_key_exists('lines', $data) && is_array($data['lines'])
                ? JournalLineData::listFromArray($data['lines'])
                : null,
        );
    }
}
