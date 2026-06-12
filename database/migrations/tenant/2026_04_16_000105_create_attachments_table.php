<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('attachable');
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('file_size');
            /** image | pdf | document | video | audio | text | other */
            $table->string('viewer_category', 32)->index();
            $table->boolean('can_preview')->default(false);
            $table->boolean('is_primary')->default(false);
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['attachable_type', 'attachable_id', 'is_primary']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
