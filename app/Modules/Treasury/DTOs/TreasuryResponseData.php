<?php

declare(strict_types=1);

namespace App\Modules\Treasury\DTOs;

use App\Modules\Treasury\Models\CashBankAccount;
use App\Modules\Treasury\Models\ExpenseVoucher;
use App\Modules\Treasury\Models\ExpenseVoucherLine;
use App\Modules\Treasury\Models\TreasuryTransfer;

final class TreasuryResponseData
{
    /**
     * @param  array{balance: string, balance_primary: string}|null  $balance
     * @return array<string, mixed>
     */
    public static function account(CashBankAccount $a, ?array $balance = null): array
    {
        return [
            'id' => (int) $a->id,
            'code' => $a->code,
            'name' => $a->name,
            'type' => $a->type->value,
            'currency_id' => (int) $a->currency_id,
            'currency_code' => $a->relationLoaded('currency') ? $a->currency?->code : null,
            'currency_symbol' => $a->relationLoaded('currency') ? $a->currency?->symbol : null,
            'account_id' => (int) $a->account_id,
            'account' => $a->relationLoaded('account') && $a->account !== null
                ? ['id' => (int) $a->account->id, 'code' => $a->account->code, 'name' => $a->account->name]
                : null,
            'branch_id' => $a->branch_id,
            'branch_name' => $a->relationLoaded('branch') ? $a->branch?->name : null,
            'bank_name' => $a->getAttribute('bank_name'),
            'iban' => $a->getAttribute('iban'),
            'account_number' => $a->getAttribute('account_number'),
            'opening_balance' => (string) $a->opening_balance,
            'opening_date' => $a->opening_date?->toDateString(),
            'is_active' => (bool) $a->is_active,
            'balance' => $balance['balance'] ?? '0.0000',
            'balance_primary' => $balance['balance_primary'] ?? '0.0000',
            'created_at' => (string) $a->created_at,
            'updated_at' => (string) $a->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function accountBrief(?CashBankAccount $a): ?array
    {
        if ($a === null) {
            return null;
        }

        return [
            'id' => (int) $a->id,
            'code' => $a->code,
            'name' => $a->name,
            'currency_id' => (int) $a->currency_id,
            'currency_code' => $a->relationLoaded('currency') ? $a->currency?->code : null,
            'currency_symbol' => $a->relationLoaded('currency') ? $a->currency?->symbol : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function transfer(TreasuryTransfer $t): array
    {
        return [
            'id' => (string) $t->id,
            'branch_id' => (int) $t->branch_id,
            'transfer_number' => $t->transfer_number,
            'transfer_date' => $t->transfer_date->toDateString(),
            'from_account_id' => (int) $t->from_account_id,
            'to_account_id' => (int) $t->to_account_id,
            'from_account' => self::accountBrief($t->relationLoaded('fromAccount') ? $t->fromAccount : null),
            'to_account' => self::accountBrief($t->relationLoaded('toAccount') ? $t->toAccount : null),
            'amount_from' => (string) $t->amount_from,
            'amount_to' => (string) $t->amount_to,
            'exchange_rate' => (string) $t->exchange_rate,
            'exchange_rate_to' => (string) $t->exchange_rate_to,
            'reference' => $t->getAttribute('reference'),
            'notes' => $t->getAttribute('notes'),
            'status' => $t->status->value,
            'posted_at' => $t->posted_at?->toIso8601String(),
            'created_at' => (string) $t->created_at,
            'updated_at' => (string) $t->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function voucher(ExpenseVoucher $v): array
    {
        return [
            'id' => (string) $v->id,
            'branch_id' => (int) $v->branch_id,
            'voucher_number' => $v->voucher_number,
            'voucher_date' => $v->voucher_date->toDateString(),
            'cash_bank_account_id' => (int) $v->cash_bank_account_id,
            'cash_bank_account' => self::accountBrief($v->relationLoaded('cashBankAccount') ? $v->cashBankAccount : null),
            'currency_id' => (int) $v->currency_id,
            'exchange_rate' => (string) $v->exchange_rate,
            'payee' => $v->getAttribute('payee'),
            'supplier_id' => $v->supplier_id,
            'supplier_name' => $v->relationLoaded('supplier') ? $v->supplier?->getAttribute('name') : null,
            'reference' => $v->getAttribute('reference'),
            'notes' => $v->getAttribute('notes'),
            'subtotal' => (string) $v->subtotal,
            'tax_total' => (string) $v->tax_total,
            'total' => (string) $v->total,
            'status' => $v->status->value,
            'posted_at' => $v->posted_at?->toIso8601String(),
            'recurring_template' => $v->relationLoaded('recurringTemplate') && $v->recurringTemplate !== null ? [
                'id' => (string) $v->recurringTemplate->id,
                'template_number' => $v->recurringTemplate->template_number,
                'name' => $v->recurringTemplate->name,
            ] : null,
            'lines' => $v->relationLoaded('lines')
                ? $v->lines->map(fn (ExpenseVoucherLine $l): array => [
                    'id' => (int) $l->id,
                    'account_id' => (int) $l->account_id,
                    'account_code' => $l->account?->code,
                    'account_name' => $l->account?->name,
                    'description' => $l->description,
                    'amount' => (string) $l->amount,
                    'vat_group_id' => $l->vat_group_id,
                    'tax_rate' => (string) $l->tax_rate,
                    'tax_amount' => (string) $l->tax_amount,
                    'deferral_start_date' => $l->deferral_start_date?->toDateString(),
                    'deferral_end_date' => $l->deferral_end_date?->toDateString(),
                    'deferral_recognition_account_id' => $l->deferral_recognition_account_id !== null ? (int) $l->deferral_recognition_account_id : null,
                    'deferral_method' => $l->deferral_method,
                    'deferral_schedule' => $l->relationLoaded('deferralSchedule') && $l->deferralSchedule !== null ? [
                        'id' => (string) $l->deferralSchedule->id,
                        'schedule_number' => $l->deferralSchedule->schedule_number,
                        'status' => $l->deferralSchedule->status->value,
                    ] : null,
                ])->values()->all()
                : [],
            'created_at' => (string) $v->created_at,
            'updated_at' => (string) $v->updated_at,
        ];
    }
}
