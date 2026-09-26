<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\DTOs;

use App\Models\User;
use App\Modules\Inventory\Stock\Models\StockTransferReceipt;
use App\Modules\Inventory\Stock\Models\StockTransferReceiptLine;
use Illuminate\Support\Collection;

readonly class StockTransferReceiptResponseData
{
    public static function fromModel(StockTransferReceipt $receipt, bool $includeLines = true): array
    {
        $receipt->loadMissing([
            'createdByUser:id,name,email',
            'postedByUser:id,name,email',
        ]);

        $payload = [
            'id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'stock_transfer_id' => $receipt->stock_transfer_id,
            'warehouse_id' => $receipt->warehouse_id,
            'status' => $receipt->status->value,
            'received_date' => $receipt->received_date?->toDateString(),
            'notes' => $receipt->notes,
            'created_by' => self::userBrief($receipt->createdByUser),
            'posted_by' => self::userBrief($receipt->postedByUser),
            'posted_at' => $receipt->posted_at?->toIso8601String(),
            'created_at' => (string) $receipt->created_at,
        ];

        if ($includeLines) {
            $receipt->loadMissing([
                'lines.item:id,item_code,name',
                'lines.lot:id,lot_number,expiry_date',
            ]);
            $payload['lines'] = $receipt->lines
                ->map(fn (StockTransferReceiptLine $line): array => [
                    'id' => $line->id,
                    'stock_transfer_line_id' => $line->stock_transfer_line_id,
                    'item_id' => $line->item_id,
                    'item_name' => $line->item?->name,
                    'lot_id' => $line->lot_id,
                    'lot_number' => $line->lot?->lot_number,
                    'quantity' => (string) $line->quantity,
                    'base_quantity' => (string) $line->base_quantity,
                    'notes' => $line->notes,
                ])
                ->values()
                ->all();
        }

        return $payload;
    }

    /**
     * @param  Collection<int, StockTransferReceipt>  $receipts
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $receipts, bool $includeLines = true): array
    {
        return $receipts
            ->map(fn (StockTransferReceipt $receipt): array => self::fromModel($receipt, $includeLines))
            ->values()
            ->all();
    }

    /**
     * @return array{id:string,name:string,email:string|null}|null
     */
    private static function userBrief(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
