<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Requests;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Enums\NormalBalance;
use App\Modules\Accounting\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateAccountRequest extends FormRequest
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
        /** @var Account $account */
        $account = $this->route('account');

        return [
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:/^[0-9]+$/',
                Rule::unique('accounts', 'code')->ignore($account->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'type' => ['nullable', new Enum(AccountType::class)],
            'normal_balance' => ['nullable', new Enum(NormalBalance::class)],
            'is_group' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
