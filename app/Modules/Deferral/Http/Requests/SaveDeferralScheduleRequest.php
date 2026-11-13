<?php

declare(strict_types=1);

namespace App\Modules\Deferral\Http\Requests;

use App\Modules\Deferral\Enums\DeferralKind;
use App\Modules\Deferral\Enums\DeferralMethod;
use App\Modules\Deferral\Enums\DeferralPostingDay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveDeferralScheduleRequest extends FormRequest
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
            'kind' => [$required, Rule::in(DeferralKind::values())],
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'deferral_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')],
            'recognition_account_id' => [$required, 'integer', Rule::exists('accounts', 'id')],
            'currency_id' => [$required, 'integer', Rule::exists('currencies', 'id')],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'total_amount' => [$required, 'numeric', 'gt:0'],
            'start_date' => [$required, 'date'],
            'end_date' => [$required, 'date', 'after_or_equal:start_date'],
            'method' => [$required, Rule::in(DeferralMethod::values())],
            'posting_day' => ['nullable', Rule::in(DeferralPostingDay::values())],
            'auto_post' => ['nullable', 'boolean'],
            'supplier_id' => ['nullable', 'uuid', Rule::exists('suppliers', 'id')],
            'customer_id' => ['nullable', 'uuid', Rule::exists('customers', 'id')],
        ];
    }
}
