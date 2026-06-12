<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Item\Support;

use App\Modules\Inventory\Item\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;

final class ItemPosFieldValidator
{
    public static function validateAfter(Validator $validator, Request $request): void
    {
        $validator->after(function (Validator $validator) use ($request): void {
            $item = $request->route('item');
            $allowSale = $request->has('allow_sale')
                ? (bool) $request->boolean('allow_sale')
                : ($item instanceof Item ? (bool) $item->allow_sale : true);

            if (! $allowSale) {
                return;
            }

            $qrEnabled = $request->has('qr_enabled')
                ? (bool) $request->boolean('qr_enabled')
                : ($item instanceof Item ? (bool) $item->qr_enabled : false);

            if ($qrEnabled) {
                $description = trim((string) $request->input('qr_description', ''));
                if ($description === '') {
                    $validator->errors()->add(
                        'qr_description',
                        'QR description is required when QR menu is enabled.'
                    );
                }
            }
        });
    }
}
