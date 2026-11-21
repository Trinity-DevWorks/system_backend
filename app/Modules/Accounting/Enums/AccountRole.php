<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Enums;

/**
 * Accounts the ERP posts to automatically, resolved through `account_mappings`.
 * Values match the `system_accounts` keys in config/chart_of_accounts.php.
 */
enum AccountRole: string
{
    case Receivable = 'receivable';
    case Payable = 'payable';
    case Inventory = 'inventory';
    case GoodsReceivedNotInvoiced = 'goods_received_not_invoiced';
    case SalesRevenue = 'sales_revenue';
    case SalesReturns = 'sales_returns';
    case SalesDiscount = 'sales_discount';
    case PurchaseDiscount = 'purchase_discount';
    case CostOfGoodsSold = 'cost_of_goods_sold';
    case PurchasePriceDifference = 'purchase_price_difference';
    case NonStockPurchase = 'non_stock_purchase';
    case VatOutput = 'vat_output';
    case VatInput = 'vat_input';
    case VatPayable = 'vat_payable';
    case Cash = 'cash';
    case Bank = 'bank';
    case ChequesToCollect = 'cheques_to_collect';
    case SalesmanCommissionPayable = 'salesman_commission_payable';
    case InventoryShortage = 'inventory_shortage';
    case InventorySurplus = 'inventory_surplus';
    case OpeningBalanceEquity = 'opening_balance_equity';
    case ExchangeLoss = 'exchange_loss';
    case ExchangeGain = 'exchange_gain';
    case Rounding = 'rounding';
    case RetainedEarnings = 'retained_earnings';
    case AccumulatedLosses = 'accumulated_losses';
    case CurrentYearProfit = 'current_year_profit';
    case CurrentYearLoss = 'current_year_loss';

    /**
     * Account types a mapping for this role may point at.
     *
     * @return list<AccountType>
     */
    public function allowedTypes(): array
    {
        return match ($this) {
            self::Receivable,
            self::Inventory,
            self::Cash,
            self::Bank,
            self::ChequesToCollect => [AccountType::Asset],
            self::Payable,
            self::GoodsReceivedNotInvoiced,
            self::SalesmanCommissionPayable => [AccountType::Liability],
            self::VatOutput,
            self::VatInput,
            self::VatPayable => [AccountType::Asset, AccountType::Liability],
            self::SalesRevenue,
            self::SalesReturns,
            self::SalesDiscount,
            self::InventorySurplus,
            self::ExchangeGain => [AccountType::Revenue],
            self::PurchaseDiscount,
            self::CostOfGoodsSold,
            self::PurchasePriceDifference,
            self::NonStockPurchase,
            self::InventoryShortage,
            self::ExchangeLoss => [AccountType::Expense],
            self::Rounding => [AccountType::Expense, AccountType::Revenue],
            self::OpeningBalanceEquity,
            self::RetainedEarnings,
            self::AccumulatedLosses,
            self::CurrentYearProfit,
            self::CurrentYearLoss => [AccountType::Equity],
        };
    }

    public function allows(?AccountType $type): bool
    {
        return $type !== null && in_array($type, $this->allowedTypes(), true);
    }

    /**
     * Balances that must only move through documents, so the party ledgers and stock stay in step.
     */
    public function isControlled(): bool
    {
        return in_array($this, [
            self::Receivable,
            self::Payable,
            self::Inventory,
            self::VatOutput,
            self::VatInput,
        ], true);
    }

    /**
     * The party a line on this role's account must carry.
     */
    public function partyType(): ?string
    {
        return match ($this) {
            self::Receivable => 'customer',
            self::Payable => 'supplier',
            default => null,
        };
    }
}
