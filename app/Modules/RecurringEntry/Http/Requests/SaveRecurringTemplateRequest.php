<?php

declare(strict_types=1);

namespace App\Modules\RecurringEntry\Http\Requests;

use App\Modules\RecurringEntry\Enums\RateMode;
use App\Modules\RecurringEntry\Enums\RecurrenceFrequency;
use App\Modules\RecurringEntry\Enums\RecurringDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRecurringTemplateRequest extends FormRequest
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
        $days = array_merge([-1], range(1, 31));

        return [
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'document_type' => [$required, Rule::in(RecurringDocumentType::values())],
            'currency_id' => ['required_if:document_type,journal_entry', 'nullable', 'integer', Rule::exists('currencies', 'id')],
            'rate_mode' => ['nullable', Rule::in(RateMode::values())],
            'fixed_exchange_rate' => ['required_if:rate_mode,fixed', 'nullable', 'numeric', 'gt:0'],
            'cash_bank_account_id' => ['required_if:document_type,expense_voucher', 'nullable', 'integer', Rule::exists('cash_bank_accounts', 'id')],
            'payee' => ['nullable', 'string', 'max:255'],
            'supplier_id' => ['nullable', 'uuid', Rule::exists('suppliers', 'id')],
            'reference' => ['nullable', 'string', 'max:128'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'frequency' => [$required, Rule::in(RecurrenceFrequency::values())],
            'interval' => ['nullable', 'integer', 'between:1,12'],
            'day_of_month' => ['nullable', 'integer', Rule::in($days)],
            'day_of_week' => ['nullable', 'integer', 'between:1,7'],
            'start_date' => [$required, 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'max_occurrences' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'auto_post' => ['nullable', 'boolean'],
            'lines' => [$required, 'array', 'min:1', 'max:200'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.vat_group_id' => ['nullable', 'integer', Rule::exists('vat_groups', 'id')],
            'lines.*.party_type' => ['nullable', Rule::in(['customer', 'supplier'])],
            'lines.*.party_id' => ['nullable', 'uuid'],
        ];
    }
}
