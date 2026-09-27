<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('invoice_type', 16);
            $table->uuid('invoice_id');
            $table->unsignedTinyInteger('schema_version');
            // Stored as text so PostgreSQL jsonb cannot reorder keys or change bytes.
            $table->longText('canonical_json');
            $table->char('content_hash', 64);
            $table->timestamp('created_at');

            $table->unique(['invoice_type', 'invoice_id']);
            $table->index('invoice_id');
            $table->index('content_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_snapshots');
    }
};
