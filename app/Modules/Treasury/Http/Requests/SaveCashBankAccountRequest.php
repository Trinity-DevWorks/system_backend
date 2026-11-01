<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Http\Requests;

use App\Modules\Treasury\Enums\CashBankAccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCashBankAccountRequest extends FormRequest
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
        $updating = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $id = $this->route('cash_bank_account');
        $id = is_object($id) ? $id->getKey() : $id;
        $required = $updating ? 'sometimes' : 'required';

        return [
            'code' => [$required, 'string', 'max:50', 'regex:/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/', Rule::unique('cash_bank_accounts', 'code')->ignore($id)],
            'name' => [$required, 'string', 'max:255'],
            'type' => [$required, Rule::in(CashBankAccountType::values())],
            'currency_id' => [$required, 'integer', Rule::exists('currencies', 'id')],
            'account_id' => [$required, 'integer', Rule::exists('accounts', 'id'), Rule::unique('cash_bank_accounts', 'account_id')->ignore($id)],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'iban' => ['nullable', 'string', 'max:64'],
            'account_number' => ['nullable', 'string', 'max:64'],
            'opening_balance' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'],
            'opening_date' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
