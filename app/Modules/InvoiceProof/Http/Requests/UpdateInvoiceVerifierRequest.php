<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Http\Requests;

use App\Modules\InvoiceProof\Enums\InvoiceVerifierRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * The wallet is fixed after create; remove and re-add to change it.
 */
class UpdateInvoiceVerifierRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', new Enum(InvoiceVerifierRole::class)],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
