<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Http\Requests;

use App\Modules\InvoiceProof\Support\WalletAddress;
use Illuminate\Foundation\Http\FormRequest;

class InspectWalletRequest extends FormRequest
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
        return [
            'address' => ['required', 'string', 'regex:'.WalletAddress::PATTERN, 'not_in:'.WalletAddress::ZERO],
        ];
    }
}
