<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entry_number', 32)->unique();
            $table->date('entry_date');
            /** `manual` or the source document REFERENCE_TYPE */
            $table->string('source_type', 48);
            $table->string('source_id', 64)->nullable();
            /** post | reverse */
            $table->string('source_event', 16);
            $table->uuid('reversal_of_id')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('description', 500)->nullable();
            $table->string('status', 24)->default('draft');
            $table->decimal('total_debit', 20, 4)->default(0);
            $table->decimal('total_credit', 20, 4)->default(0);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'source_event']);
            $table->index(['entry_date', 'status']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreign('reversal_of_id')->references('id')->on('journal_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
