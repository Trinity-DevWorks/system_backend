<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The company's own mailbox (SMTP) used to email documents. One row per tenant.
        Schema::create('email_settings', function (Blueprint $table) {
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
            $table->boolean('bcc_sender')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        // Company overrides of the default subject / body per document type and language.
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 40);
            $table->string('locale', 2);
            $table->string('subject');
            $table->text('body');
            $table->timestamps();

            $table->unique(['document_type', 'locale']);
        });

        // Every email sent (or tried) for a document: the send history.
        Schema::create('document_emails', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_type', 40);
            $table->uuid('document_id');
            $table->string('document_number')->nullable();
            $table->json('to');
            $table->json('cc')->nullable();
            $table->string('subject');
            $table->text('body');
            $table->string('locale', 2);
            $table->string('attachment_name')->nullable();
            $table->string('status', 16)->default('queued'); // queued | sending | sent | failed
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->json('meta')->nullable(); // statements: date_from / date_to / currency_id
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['document_type', 'document_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_emails');
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('email_settings');
    }
};
