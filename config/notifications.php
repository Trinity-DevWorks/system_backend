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
    | Phase 1: database (in-app inbox) + mail.
    | Phase 2 will add broadcast (Reverb) without changing this catalog.
    */
    'channels' => [
        'database',
        'mail',
    ],

    /*
    |--------------------------------------------------------------------------
    | Low-stock digest
    |--------------------------------------------------------------------------
    | Cooldown prevents spamming the same alert window; digest runs per tenant daily.
    */
    'low_stock' => [
        'cooldown_hours' => 24,
        'cache_key_prefix' => 'notifications:low_stock_digest',
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
        'stock_transfer.posted' => [
            'severity' => 'info',
            'default_channels' => ['database'],
            'mail_subject' => 'Stock transfer :transfer_number posted',
            'permission' => ['resource' => 'stock', 'action' => 'view'],
        ],
        'stock_transfer.cancelled' => [
            'severity' => 'warning',
            'default_channels' => ['database', 'mail'],
            'mail_subject' => 'Stock transfer :transfer_number cancelled',
            'permission' => ['resource' => 'stock', 'action' => 'view'],
        ],
        'stock_movement.posted' => [
            'severity' => 'info',
            'default_channels' => ['database'],
            'mail_subject' => 'Stock movement posted',
            'permission' => ['resource' => 'stock', 'action' => 'view'],
        ],
    ],
];
