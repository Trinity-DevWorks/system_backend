<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesCreditNote\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSalesCreditNoteRequest extends FormRequest
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
            'credit_date' => ['sometimes', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
