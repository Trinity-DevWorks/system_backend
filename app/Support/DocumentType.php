<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Every numbered document. The value is a stable code used as the counter key in
 * document_sequences and in document_number_settings, so changing a prefix never restarts a series.
 */
enum DocumentType: string
{
    case SalesQuotation = 'sales_quotation';
    case SalesOrder = 'sales_order';
    case DeliveryNote = 'delivery_note';
    case SalesInvoice = 'sales_invoice';
    case SalesCreditNote = 'sales_credit_note';
    case CustomerReceipt = 'customer_receipt';
    case CustomerRefund = 'customer_refund';
    case PurchaseOrder = 'purchase_order';
    case GoodsReceipt = 'goods_receipt';
    case LandedCost = 'landed_cost';
    case PurchaseInvoice = 'purchase_invoice';
    case PurchaseDebitNote = 'purchase_debit_note';
    case SupplierPayment = 'supplier_payment';
    case SupplierRefund = 'supplier_refund';
    case StockTransfer = 'stock_transfer';
    case StockTransferReceipt = 'stock_transfer_receipt';
    case StockTransferClosure = 'stock_transfer_closure';
    case StockAdjustment = 'stock_adjustment';
    case StockCount = 'stock_count';
    case OpeningStock = 'opening_stock';
    case Production = 'production';
    case BundleExplosion = 'bundle_explosion';
    case StockRevaluation = 'stock_revaluation';
    case PriceChange = 'price_change';
    case JournalEntry = 'journal_entry';
    case FixedAsset = 'fixed_asset';
    case AssetDisposal = 'asset_disposal';
    case ExpenseVoucher = 'expense_voucher';
    case TreasuryTransfer = 'treasury_transfer';
    case RecurringTemplate = 'recurring_template';
    case DeferralSchedule = 'deferral_schedule';
    case OpeningSalesInvoice = 'opening_sales_invoice';
    case OpeningCreditNote = 'opening_credit_note';
    case OpeningPurchaseInvoice = 'opening_purchase_invoice';
    case OpeningDebitNote = 'opening_debit_note';

    public function defaultPrefix(): string
    {
        return match ($this) {
            self::SalesQuotation => 'QT',
            self::SalesOrder => 'SO',
            self::DeliveryNote => 'DN',
            self::SalesInvoice => 'SI',
            self::SalesCreditNote => 'SCN',
            self::CustomerReceipt => 'CR',
            self::CustomerRefund => 'CRF',
            self::PurchaseOrder => 'PO',
            self::GoodsReceipt => 'GRN',
            self::LandedCost => 'LC',
            self::PurchaseInvoice => 'PI',
            self::PurchaseDebitNote => 'PDN',
            self::SupplierPayment => 'SP',
            self::SupplierRefund => 'SRF',
            self::StockTransfer => 'ST',
            self::StockTransferReceipt => 'STR',
            self::StockTransferClosure => 'STC',
            self::StockAdjustment => 'ADJ',
            self::StockCount => 'CNT',
            self::OpeningStock => 'OS',
            self::Production => 'PRD',
            self::BundleExplosion => 'BEX',
            self::StockRevaluation => 'SRV',
            self::PriceChange => 'PCH',
            self::JournalEntry => 'JE',
            self::FixedAsset => 'FA',
            self::AssetDisposal => 'FAD',
            self::ExpenseVoucher => 'EXP',
            self::TreasuryTransfer => 'TRF',
            self::RecurringTemplate => 'RT',
            self::DeferralSchedule => 'DFS',
            self::OpeningSalesInvoice => 'OSI',
            self::OpeningCreditNote => 'OCN',
            self::OpeningPurchaseInvoice => 'OPI',
            self::OpeningDebitNote => 'ODN',
        };
    }

    /** Module that owns the document (for grouping in settings). */
    public function group(): string
    {
        return match ($this) {
            self::SalesQuotation, self::SalesOrder, self::DeliveryNote, self::SalesInvoice,
            self::SalesCreditNote, self::CustomerReceipt, self::CustomerRefund,
            self::OpeningSalesInvoice, self::OpeningCreditNote => 'sales',
            self::PurchaseOrder, self::GoodsReceipt, self::LandedCost, self::PurchaseInvoice, self::PurchaseDebitNote,
            self::SupplierPayment, self::SupplierRefund,
            self::OpeningPurchaseInvoice, self::OpeningDebitNote => 'purchasing',
            self::JournalEntry, self::ExpenseVoucher, self::TreasuryTransfer,
            self::FixedAsset, self::AssetDisposal,
            self::RecurringTemplate, self::DeferralSchedule => 'accounting',
            default => 'inventory',
        };
    }
}
