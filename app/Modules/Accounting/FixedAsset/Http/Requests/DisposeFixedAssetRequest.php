<?php

declare(strict_types=1);

namespace App\Modules\Accounting\FixedAsset\Http\Requests;

use App\Modules\Accounting\FixedAsset\Enums\DisposalMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DisposeFixedAssetRequest extends FormRequest
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
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0'],
            'mode' => ['required', Rule::enum(DisposalMode::class)],
            'customer_id' => ['required_if:mode,sale_on_account', 'nullable', 'uuid', 'exists:customers,id'],
            'cash_bank_account_id' => ['required_if:mode,sale_cash', 'nullable', 'integer', 'exists:cash_bank_accounts,id'],
        ];
    }
}
