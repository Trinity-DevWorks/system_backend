<?php

declare(strict_types=1);

/**
 * Attachment feature settings (disk, size limits, quota, virus scan, async).
 *
 * Used by: AttachmentService, StoreAttachmentRequest, AppServiceProvider (virus driver),
 *          ProcessAttachmentJob (via service), and .env ATTACHMENT_* keys.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Storage disk
    |--------------------------------------------------------------------------
    |
    | local = private disk under storage/ (tenant-suffixed by Stancl)
    | s3    = object storage; tenant prefix applied via tenancy.filesystem
    |
    */
    'disk' => env('ATTACHMENT_DISK', env('FILESYSTEM_DISK', 'local')),

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */
    'max_file_kilobytes' => (int) env('ATTACHMENT_MAX_FILE_KB', 15360), // 15 MB
    'max_per_record' => (int) env('ATTACHMENT_MAX_PER_RECORD', 50),

    /*
    |--------------------------------------------------------------------------
    | Integrity
    |--------------------------------------------------------------------------
    */
    'checksum_algo' => env('ATTACHMENT_CHECKSUM_ALGO', 'sha256'),

    /*
    |--------------------------------------------------------------------------
    | Virus scanning
    |--------------------------------------------------------------------------
    |
    | driver: null (skip / mark clean), clamav (TCP ClamAV daemon)
    |
    */
    'virus_scan' => [
        'driver' => env('ATTACHMENT_VIRUS_SCAN_DRIVER', 'null'),
        'fail_closed' => (bool) env('ATTACHMENT_VIRUS_SCAN_FAIL_CLOSED', true),
        'clamav' => [
            'host' => env('CLAMAV_HOST', '127.0.0.1'),
            'port' => (int) env('CLAMAV_PORT', 3310),
            'timeout' => (int) env('CLAMAV_TIMEOUT', 30),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Async processing
    |--------------------------------------------------------------------------
    |
    | Files at or above this size (KB) are accepted quickly and processed on
    | the queue (checksum verify + virus scan). Smaller files process inline
    | unless ATTACHMENT_FORCE_ASYNC=true.
    |
    */
    'async' => [
        'enabled' => (bool) env('ATTACHMENT_ASYNC_ENABLED', true),
        'force' => (bool) env('ATTACHMENT_FORCE_ASYNC', false),
        'threshold_kilobytes' => (int) env('ATTACHMENT_ASYNC_THRESHOLD_KB', 1024), // 1 MB
    ],

    /*
    |--------------------------------------------------------------------------
    | Soft delete
    |--------------------------------------------------------------------------
    |
    | Soft-deleted rows keep the blob until force-delete / purge.
    |
    */
    'purge_files_on_soft_delete' => (bool) env('ATTACHMENT_PURGE_ON_SOFT_DELETE', false),

];
