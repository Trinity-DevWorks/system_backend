# Recurring entries and deferrals

Two accounting features. Recurring templates generate a journal entry or an expense voucher on a schedule. Deferral schedules spread a balance-sheet amount (prepaid expense or deferred revenue) into later periods.

## Recurring entries

A template (`RT-…`) stores the document, the lines, and the schedule. A daily command, `accounting:run-periodic`, and the **Run now** action generate each due date. `auto_post` posts the document; otherwise it stays a draft.

Dates are computed from the start date plus `n × interval`, not by stepping from the previous date. Day 31 therefore returns in March after February. A missed run is caught up, one date at a time, up to `accounting.recurring.max_catch_up` (24). A failure (closed period, insufficient cash, and so on) is stored on `recurring_runs` and the template stays on that date until someone retries or skips it.

Generated journal entries keep `source_type = manual` and a `recurring_template_id`, so they can be edited or reversed from the journal screen. The exchange rate is the rate in force when the occurrence is generated, unless the template uses a fixed rate. There is no historical rate table.

Accrued expenses in a template are whatever liability account the user picks. The Lebanese chart already has `4619 Sundry Operating Creditors` for that; it is not a system role.

## Deferrals

A schedule (`DFS-…`) is non-monetary (IAS 21). Every recognition line uses the schedule's historical rate. Primary amounts are allocated so they sum to `total_amount_primary` exactly. The last line absorbs rounding.

Prepaid expense recognition debits the expense account and credits the prepaid account. Deferred revenue debits the unearned-revenue account and credits revenue. The entries are automatic (`source_type = deferral_recognition`) and are not edited by hand. Reversing a line and posting it again uses a new source id (`:r1`, `:r2`, …).

An expense voucher line marked “spread” posts to the prepaid account and opens an active schedule for the net amount. VAT stays on the VAT input account. The voucher cannot be reversed once a recognition line has been posted.

Currency revaluation does not include these accounts. It only revalues cash, bank, receivables, payables, and cheques.

## Operations

Deploy order: `php artisan tenants:migrate`, then `php artisan tenants:sync-rbac`, then `php artisan accounting:seed-chart` (adds the `prepaid_expenses` → 4720 and `deferred_revenue` → 4730 mappings without overwriting existing ones).

The `scheduler` container runs `schedule:work`. The periodic command is daily at 01:00. Owner receives the new permissions from `tenants:sync-rbac`; other roles need them granted. Tenants without the accounting module do not see the screens or the routes.
