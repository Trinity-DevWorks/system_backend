<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What: Validates query filters for GET /audits (and export).
 * Where: AuditController::index and AuditController::export.
 * Why: Keeps filter parsing/authorization shape consistent and rejects oversized pages.
 */
class IndexAuditRequest extends FormRequest
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
        return [
            'event' => ['sometimes', 'string', 'max:100'],
            'user_id' => ['sometimes', 'string', 'max:64'],
            'auditable_type' => ['sometimes', 'string', 'max:100'],
            'auditable_id' => ['sometimes', 'string', 'max:64'],
            'tags' => ['sometimes', 'string', 'max:255'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'format' => ['sometimes', 'string', 'in:json,csv'],
        ];
    }
}
