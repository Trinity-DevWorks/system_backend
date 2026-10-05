<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Support;

/**
 * Human-readable sentences shown in the wallet. The company name is the
 * tenant's own name, filled when the message is built.
 */
final class InvoiceApprovalStatement
{
    public static function history(string $companyName, string $nonce): string
    {
        return 'Sign in to view your invoices from '.self::company($companyName)
            .". This does not approve any invoice.\nnonce:{$nonce}";
    }

    public static function view(string $companyName, string $invoiceNumber, string $nonce): string
    {
        return 'Sign in to view invoice '.self::number($invoiceNumber)
            .' from '.self::company($companyName)
            .". This does not approve the invoice.\nnonce:{$nonce}";
    }

    public static function approve(string $companyName, string $invoiceNumber, string $grandTotal, string $currencyCode): string
    {
        $amount = trim(trim($grandTotal).' '.trim($currencyCode));
        $sentence = 'Approve invoice '.self::number($invoiceNumber).' from '.self::company($companyName);
        if ($amount !== '') {
            $sentence .= ' for '.$amount;
        }

        return $sentence.'.';
    }

    public static function dispute(string $companyName, string $invoiceNumber): string
    {
        return 'Dispute invoice '.self::number($invoiceNumber).' from '.self::company($companyName)
            .'. I do not approve this invoice.';
    }

    private static function company(string $companyName): string
    {
        $company = trim($companyName);

        return $company !== '' ? $company : 'the company';
    }

    private static function number(string $invoiceNumber): string
    {
        $number = trim($invoiceNumber);

        return $number !== '' ? $number : 'this invoice';
    }
}
