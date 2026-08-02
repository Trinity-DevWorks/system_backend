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
            $table->string('disk', 32)->default('local');
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('file_size');
            $table->string('checksum', 128)->nullable();
            $table->string('checksum_algo', 16)->nullable();
            /** image | pdf | document | video | audio | text | other */
            $table->string('viewer_category', 32)->index();
            $table->boolean('can_preview')->default(false);
            $table->boolean('is_primary')->default(false);
            /** pending | ready | rejected */
            $table->string('processing_status', 32)->default('ready')->index();
            /** pending | clean | infected | skipped | failed */
            $table->string('scan_status', 32)->default('skipped')->index();
            $table->string('scan_signature')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['attachable_type', 'attachable_id', 'is_primary']);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
