<?php

declare(strict_types=1);

/**
 * Notification type registry (Phase 1).
 *
 * What: Catalog of business notification types, default channels, severity, and copy keys.
 * Used for: NotificationDispatcher (channel defaults), preferences UI, mail subjects, frontend i18n.
 * Solves: Keeps type metadata in one place so producers only pass a type key + payload params.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Channels
    |--------------------------------------------------------------------------
    | Preference UI / defaults: database (in-app) + mail.
    | Broadcast (Reverb) mirrors database and is not a user-facing preference.
    */
    'channels' => [
        'database',
        'mail',
    ],

    /*
    |--------------------------------------------------------------------------
    | Low-stock digest
    |--------------------------------------------------------------------------
    | Digest has a tenant-wide cooldown. One instant alert is allowed per
    | item × warehouse until the digest covers it or its stock recovers.
    | The fallback expiry prevents stale suppression if the scheduler is offline.
    */
    'low_stock' => [
        'cooldown_hours' => 24,
        'cache_key_prefix' => 'notifications:low_stock_digest',
        'instant_suppression_fallback_hours' => 48,
        'instant_cache_key_prefix' => 'notifications:instant_low_stock',
    ],

    /*
    |--------------------------------------------------------------------------
    | Lot expiry digest
    |--------------------------------------------------------------------------
    | Daily summary of on-hand lots that are already expired or expire within
    | `within_days`. Tenant-wide cooldown avoids repeating the same digest.
    */
    'lot_expiry' => [
        'within_days' => 7,
        'cooldown_hours' => 24,
        'cache_key_prefix' => 'notifications:lot_expiry_digest',
        'instant_suppression_fallback_hours' => 48,
        'instant_cache_key_prefix' => 'notifications:instant_lot_expiry',
    ],

    /*
    |--------------------------------------------------------------------------
    | Type catalog
    |--------------------------------------------------------------------------
    | `default_channels`: used when the user has no preference row.
    | `mail_subject`: English subject template; :tokens replaced from payload params.
    | `severity`: info | success | warning | critical (UI badge styling).
    */
    'types' => [
        'user.created' => [
            'severity' => 'info',
            'default_channels' => ['database', 'mail'],
            'mail_subject' => 'Your account was created',
            'permission' => null,
        ],
        'user.role_assigned' => [
            'severity' => 'info',
            'default_channels' => ['database', 'mail'],
            'mail_subject' => 'Your role was updated',
            'permission' => null,
        ],
        'user.deactivated' => [
            'severity' => 'warning',
            'default_channels' => ['database', 'mail'],
            'mail_subject' => 'Your account was deactivated',
            'permission' => null,
        ],
        'branch.user_assigned' => [
            'severity' => 'info',
            'default_channels' => ['database'],
            'mail_subject' => 'You were assigned to a branch',
            'permission' => null,
        ],
        'purchasing.low_stock' => [
            'severity' => 'warning',
            'default_channels' => ['database', 'mail'],
            'mail_subject' => 'Low stock digest (:alert_count alerts)',
            'permission' => ['resource' => 'stock', 'action' => 'view'],
        ],
        'purchasing.low_stock_item' => [
            'severity' => 'warning',
            'default_channels' => ['database'],
            'mail_subject' => 'Low stock: :item_code in :warehouse_name',
            'permission' => ['resource' => 'stock', 'action' => 'view'],
        ],
        'purchase_order.confirmed' => [
            'severity' => 'info',
            'default_channels' => ['database', 'mail'],
            'mail_subject' => 'Purchase order :po_number confirmed',
            'permission' => ['resource' => 'stock', 'action' => 'view'],
        ],
        'purchase_order.sent' => [
            'severity' => 'info',
            'default_channels' => ['database', 'mail'],
            'mail_subject' => 'Purchase order :po_number marked as sent',
            'permission' => ['resource' => 'stock', 'action' => 'view'],
        ],
        'purchase_order.cancelled' => [
            'severity' => 'warning',
            'default_channels' => ['database', 'mail'],
            'mail_subject' => 'Purchase order :po_number cancelled',
            'permission' => ['resource' => 'stock', 'action' => 'view'],
        ],
        'purchase_order.closed' => [
            'severity' => 'info',
            'default_channels' => ['database', 'mail'],
            'mail_subject' => 'Purchase order :po_number closed',
            'permission' => ['resource' => 'stock', 'action' => 'view'],
        ],
        'goods_receipt.posted' => [
            'severity' => 'success',
            'default_channels' => ['database'],
            'mail_subject' => 'Goods receipt :grn_number posted',
            'permission' => ['resource' => 'stock', 'action' => 'view'],
        ],
        'stock_transfer.dispatched' => [
            'severity' => 'info',
            'default_channels' => ['database'],
            'mail_subject' => 'Stock transfer :transfer_number dispatched',
            'permission' => ['resource' => 'stock', 'action' => 'view'],
        ],
        'stock_transfer.received' => [
            'severity' => 'info',
            'default_channels' => ['database'],
            'mail_subject' => 'Stock transfer :transfer_number received',
            'permission' => ['resource' => 'stock', 'action' => 'view'],
        ],
        'stock_transfer.cancelled' => [
            'severity' => 'warning',
            'default_channels' => ['database', 'mail'],
            'mail_subject' => 'Stock transfer :transfer_number cancelled',
            'permission' => ['resource' => 'stock', 'action' => 'view'],
        ],
        'stock.lot_expiry' => [
            'severity' => 'warning',
            'default_channels' => ['database', 'mail'],
            'mail_subject' => 'Lots expiring (:lot_count on hand)',
            'permission' => ['resource' => 'stock', 'action' => 'view'],
        ],
        'stock.lot_expiry_item' => [
            'severity' => 'warning',
            'default_channels' => ['database'],
            'mail_subject' => 'Lot :lot_number of :item_code expires :expiry_date',
            'permission' => ['resource' => 'stock', 'action' => 'view'],
        ],
        'stock_movement.posted' => [
            'severity' => 'info',
            'default_channels' => ['database'],
            'mail_subject' => 'Stock movement posted',
            'permission' => ['resource' => 'stock', 'action' => 'view'],
        ],
        'depreciation.posted' => [
            'severity' => 'info',
            'default_channels' => ['database', 'mail'],
            'mail_subject' => 'Depreciation posted for :month',
            'permission' => ['resource' => 'depreciation_runs', 'action' => 'view'],
        ],
        'accounting.budget_exceeded' => [
            'severity' => 'warning',
            'default_channels' => ['database'],
            'mail_subject' => ':document is over budget',
            'permission' => ['resource' => 'budgets', 'action' => 'view'],
        ],
        'accounting.recurring_failed' => [
            'severity' => 'warning',
            'default_channels' => ['database', 'mail'],
            'mail_subject' => 'Recurring entry :template_number failed',
            'permission' => ['resource' => 'recurring_entries', 'action' => 'view'],
        ],
        'accounting.recurring_drafts_ready' => [
            'severity' => 'info',
            'default_channels' => ['database'],
            'mail_subject' => ':count recurring document(s) waiting for review',
            'permission' => ['resource' => 'recurring_entries', 'action' => 'view'],
        ],
        'accounting.deferral_posting_failed' => [
            'severity' => 'warning',
            'default_channels' => ['database', 'mail'],
            'mail_subject' => 'Deferral :schedule_number could not be recognised',
            'permission' => ['resource' => 'deferral_schedules', 'action' => 'view'],
        ],
    ],
];
