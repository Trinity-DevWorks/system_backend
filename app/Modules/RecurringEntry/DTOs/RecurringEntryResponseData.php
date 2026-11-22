<?php

declare(strict_types=1);

namespace App\Modules\RecurringEntry\DTOs;

use App\Models\User;
use App\Modules\RecurringEntry\Models\RecurringRun;
use App\Modules\RecurringEntry\Models\RecurringTemplate;
use App\Modules\RecurringEntry\Models\RecurringTemplateLine;

final class RecurringEntryResponseData
{
    /**
     * @return array<string, mixed>
     */
    public static function template(RecurringTemplate $template): array
    {
        return [
            ...self::templateRow($template),
            'lines' => $template->relationLoaded('lines')
                ? $template->lines->map(fn (RecurringTemplateLine $line): array => self::line($line))->values()->all()
                : [],
            'recent_runs' => $template->relationLoaded('runs')
                ? $template->runs->map(fn (RecurringRun $run): array => self::run($run))->values()->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function templateRow(RecurringTemplate $template): array
    {
        $branch = $template->relationLoaded('branch') ? $template->branch : null;
        $currency = $template->relationLoaded('currency') ? $template->currency : null;
        $cash = $template->relationLoaded('cashBankAccount') ? $template->cashBankAccount : null;
        $supplier = $template->relationLoaded('supplier') ? $template->supplier : null;

        return [
            'id' => (string) $template->id,
            'template_number' => $template->template_number,
            'name' => $template->name,
            'description' => $template->description,
            'document_type' => $template->document_type->value,
            'branch' => $branch !== null ? ['id' => (int) $branch->id, 'name' => $branch->name] : null,
            'currency' => $currency !== null ? [
                'id' => (int) $currency->id,
                'code' => $currency->code,
                'symbol' => $currency->symbol,
            ] : null,
            'rate_mode' => $template->rate_mode->value,
            'fixed_exchange_rate' => $template->fixed_exchange_rate !== null ? (string) $template->fixed_exchange_rate : null,
            'cash_bank_account' => $cash !== null ? [
                'id' => (int) $cash->id,
                'code' => $cash->code,
                'name' => $cash->name,
            ] : null,
            'payee' => $template->payee,
            'supplier' => $supplier !== null ? ['id' => (string) $supplier->id, 'name' => $supplier->name] : null,
            'reference' => $template->reference,
            'notes' => $template->notes,
            'frequency' => $template->frequency->value,
            'interval' => (int) $template->interval,
            'day_of_month' => $template->day_of_month !== null ? (int) $template->day_of_month : null,
            'day_of_week' => $template->day_of_week !== null ? (int) $template->day_of_week : null,
            'start_date' => $template->start_date->toDateString(),
            'end_date' => $template->end_date?->toDateString(),
            'max_occurrences' => $template->max_occurrences !== null ? (int) $template->max_occurrences : null,
            'occurrences_count' => (int) $template->occurrences_count,
            'next_run_date' => $template->next_run_date?->toDateString(),
            'last_run_date' => $template->last_run_date?->toDateString(),
            'auto_post' => (bool) $template->auto_post,
            'status' => $template->status->value,
            'last_error_code' => $template->last_error_code,
            'total' => $template->totalAmount(),
            'created_by' => self::user($template->relationLoaded('createdByUser') ? $template->createdByUser : null),
            'created_at' => (string) $template->created_at,
            'updated_at' => (string) $template->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function run(RecurringRun $run): array
    {
        $template = $run->relationLoaded('template') ? $run->template : null;

        return [
            'id' => (int) $run->id,
            'template' => $template !== null ? [
                'id' => (string) $template->id,
                'template_number' => $template->template_number,
                'name' => $template->name,
            ] : ['id' => (string) $run->recurring_template_id, 'template_number' => null, 'name' => null],
            'scheduled_date' => $run->scheduled_date->toDateString(),
            'status' => $run->status->value,
            'document_type' => $run->document_type->value,
            'document_id' => $run->document_id,
            'document_number' => $run->document_number,
            'error_code' => $run->error_code,
            'error_message' => $run->error_message,
            'triggered_by' => self::user($run->relationLoaded('triggeredByUser') ? $run->triggeredByUser : null),
            'attempts' => (int) $run->attempts,
            'created_at' => (string) $run->created_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function line(RecurringTemplateLine $line): array
    {
        $account = $line->relationLoaded('account') ? $line->account : null;

        return [
            'id' => (int) $line->id,
            'account' => $account !== null ? [
                'id' => (int) $account->id,
                'code' => $account->code,
                'name' => $account->name,
            ] : null,
            'description' => $line->description,
            'debit' => (string) $line->debit,
            'credit' => (string) $line->credit,
            'amount' => (string) $line->amount,
            'vat_group_id' => $line->vat_group_id !== null ? (int) $line->vat_group_id : null,
            'party_type' => $line->party_type,
            'party_id' => $line->party_id,
            'sort_order' => (int) $line->sort_order,
        ];
    }

    /**
     * @return array{id: string, name: string}|null
     */
    private static function user(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return ['id' => (string) $user->id, 'name' => (string) $user->name];
    }
}
