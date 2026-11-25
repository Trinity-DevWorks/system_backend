<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (platform) mailbox and its email texts. Account emails (password resets) always go
 * through this mailbox; tenants' own mailboxes stay for their documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_email_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->default(587);
            $table->string('encryption', 8)->default('tls'); // tls | ssl | none
            $table->string('username')->nullable();
            $table->text('password')->nullable(); // encrypted cast
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->string('reply_to')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('locale', 2);
            $table->string('subject');
            $table->text('body');
            $table->timestamps();

            $table->unique(['type', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_email_templates');
        Schema::dropIfExists('platform_email_settings');
    }
};
