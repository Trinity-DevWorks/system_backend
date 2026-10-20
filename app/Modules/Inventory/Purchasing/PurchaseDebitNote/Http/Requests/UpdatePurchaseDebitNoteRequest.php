<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\PurchaseDebitNote\Http\Requests;

use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Enums\PurchaseDebitNoteType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchaseDebitNoteRequest extends FormRequest
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
            'type' => ['required', Rule::enum(PurchaseDebitNoteType::class)],
            'debit_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'adjustment' => ['nullable', 'numeric'],
        ];
    }
}
