<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How printed documents (PDFs) look, company-wide: one default row (no document type)
        // plus an optional row per document type that overrides it.
        Schema::create('print_settings', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 40)->nullable()->unique();
            $table->string('paper', 8)->default('a4');
            $table->boolean('show_logo')->default(true);
            $table->boolean('show_tax_number')->default(true);
            $table->boolean('show_address')->default(true);
            $table->boolean('show_signatures')->default(true);
            $table->string('footer_text', 500)->nullable();
            $table->text('terms_text')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_settings');
    }
};
