<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (platform) branding: the product name and logo shown on every host. One row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_branding', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('MEASolutions');
            $table->string('logo_path')->nullable();
            $table->string('logo_mime_type', 127)->nullable();
            $table->timestamp('logo_updated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_branding');
    }
};
