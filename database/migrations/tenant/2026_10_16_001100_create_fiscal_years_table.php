<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16);
            $table->uuid('closing_entry_id')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique('starts_on');
            $table->unique('ends_on');
        });

        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->foreign('closing_entry_id')->references('id')->on('journal_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_years');
    }
};
