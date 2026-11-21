<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Requests;

use App\Modules\Accounting\DTOs\JournalLineData;
use Illuminate\Foundation\Http\FormRequest;

class ReplaceJournalEntryLinesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return JournalLinesRules::rules(true);
    }

    /**
     * @return list<JournalLineData>
     */
    public function lines(): array
    {
        return JournalLineData::listFromArray((array) $this->validated('lines'));
    }
}
