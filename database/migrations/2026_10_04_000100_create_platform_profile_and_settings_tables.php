<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (platform) company profile and regional settings: one row each.
 * The profile name and logo drive the product branding on every host.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('MENA');
            $table->string('legal_name')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('tax_number', 64)->nullable();
            $table->string('registration_number', 64)->nullable();
            $table->text('address')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('logo_mime_type', 127)->nullable();
            $table->timestamp('logo_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('preferred_language', 8)->default('en');
            $table->string('timezone', 64)->default('UTC');
            $table->string('date_format', 16)->default('Y-m-d');
            $table->string('number_format', 16)->default('comma_dot');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('platform_profiles');
    }
};
