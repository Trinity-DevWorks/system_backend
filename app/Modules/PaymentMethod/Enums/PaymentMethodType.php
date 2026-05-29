<?php

declare(strict_types=1);

namespace App\Modules\PaymentMethod\Enums;

enum PaymentMethodType: string
{
    case Cash = 'cash';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case DigitalWallet = 'digital_wallet';
    case Credit = 'credit';
    case WishMoney = 'wish_money';
    case VISA = 'visa';
    case Other = 'other';
}
