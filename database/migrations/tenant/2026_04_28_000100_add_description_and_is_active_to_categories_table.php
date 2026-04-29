<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // No-op in development: columns now belong to base create_categories migration.
        // Keep this file for compatibility with environments where this migration already ran.
    }

    public function down(): void
    {
        // No-op intentionally.
    }
};
