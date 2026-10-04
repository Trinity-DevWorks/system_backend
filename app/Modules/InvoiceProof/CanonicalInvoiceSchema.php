<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof;

/**
 * Defines the Canonical Invoice Schema: version, decimal scales, date formats,
 * and the frozen JSON key lists.
 *
 * This is the contract for the hashed proof document, not the sales/purchase
 * API payload. Field names and key order must not change for a given version.
 *
 * v1 sealed SHA-256 of the whole JSON string. v2 seals the salted Merkle root
 * of the document leaves (CanonicalInvoiceMerkle) so single fields can be
 * disclosed and verified without revealing the rest of the invoice. v3 keeps
 * the v2 layout and seals exchange_rate with 12 decimals, matching the stored rate.
 */
final class CanonicalInvoiceSchema
{
    public const VERSION = 3;

    public const MONEY_SCALE = 4;

    public const QUANTITY_SCALE = 6;

    public const RATE_SCALE = 12;

    public const PERCENT_SCALE = 4;

    public const DATE_FORMAT = 'Y-m-d';

    public const DATETIME_FORMAT = 'Y-m-d\TH:i:s\Z';

    public const HASH_ALGO = 'sha256';

    /**
     * Top-level keys in encode order.
     *
     * @var list<string>
     */
    public const ROOT_KEYS = [
        'schema_version',
        'proof_id',
        'invoice_type',
        'invoice_number',
        'invoice_date',
        'due_on',
        'currency_code',
        'exchange_rate',
        'supplier',
        'buyer',
        'salesman',
        'payment_method',
        'payment_terms',
        'warehouse',
        'billing_address',
        'shipping_address',
        'lines',
        'subtotal',
        'discount_total',
        'tax_total',
        'adjustment',
        'grand_total',
        'notes',
    ];

    /**
     * Party keys in encode order. Used for both supplier and buyer.
     *
     * @var list<string>
     */
    public const PARTY_KEYS = [
        'name',
        'legal_name',
        'tax_number',
        'email',
    ];

    /**
     * Salesman keys in encode order. Null when the invoice has no salesman.
     *
     * @var list<string>
     */
    public const SALESMAN_KEYS = [
        'code',
        'name',
    ];

    /**
     * Payment method keys in encode order.
     *
     * @var list<string>
     */
    public const PAYMENT_METHOD_KEYS = [
        'code',
        'name',
    ];

    /**
     * Payment term keys in encode order.
     *
     * @var list<string>
     */
    public const PAYMENT_TERMS_KEYS = [
        'code',
        'name',
        'due_days',
    ];

    /**
     * Warehouse keys in encode order. Used for the header and each line.
     *
     * @var list<string>
     */
    public const WAREHOUSE_KEYS = [
        'name',
        'shortcut_name',
    ];

    /**
     * Address keys in encode order. Used for billing and shipping.
     *
     * @var list<string>
     */
    public const ADDRESS_KEYS = [
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'country',
        'phone',
    ];

    /**
     * Lot keys in encode order.
     *
     * @var list<string>
     */
    public const LOT_KEYS = [
        'lot_number',
        'expiry_date',
    ];

    /**
     * Line keys in encode order.
     *
     * @var list<string>
     */
    public const LINE_KEYS = [
        'sort_order',
        'item_code',
        'item_name',
        'description',
        'uom_code',
        'quantity',
        'unit_price',
        'discount_percent',
        'discount_amount',
        'tax_rate',
        'line_subtotal',
        'tax_amount',
        'line_total',
        'warehouse',
        'lot',
        'notes',
    ];
}
