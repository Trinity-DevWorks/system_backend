<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTreasuryTransferRequest extends FormRequest
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
        $required = $this->isMethod('PUT') || $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'transfer_date' => [$required, 'date'],
            'from_account_id' => [$required, 'integer', Rule::exists('cash_bank_accounts', 'id')],
            'to_account_id' => [$required, 'integer', Rule::exists('cash_bank_accounts', 'id')],
            'amount_from' => [$required, 'numeric', 'gt:0'],
            'amount_to' => ['nullable', 'numeric', 'gt:0'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'exchange_rate_to' => ['nullable', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:128'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
