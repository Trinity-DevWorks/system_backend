<?php

declare(strict_types=1);

namespace App\Modules\DocumentNumbering\Http\Requests;

use App\Support\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDocumentNumberSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Limits keep every number within the 32-character document number columns:
     * prefix 6 + branch code 10 + year 4 + padding 8 + 3 separators = 31.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array', 'min:1'],
            'settings.*.document_type' => ['required', 'string', 'distinct', Rule::in(array_map(fn (DocumentType $t): string => $t->value, DocumentType::cases()))],
            'settings.*.prefix' => ['required', 'string', 'min:1', 'max:6', 'regex:/^[A-Za-z0-9]+$/'],
            'settings.*.include_branch' => ['required', 'boolean'],
            'settings.*.include_year' => ['required', 'boolean'],
            'settings.*.padding' => ['required', 'integer', 'min:3', 'max:8'],
            'settings.*.start_number' => ['required', 'integer', 'min:1', 'max:99999999'],
        ];
    }
}
