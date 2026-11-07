<?php

declare(strict_types=1);

use App\Support\DocumentNumberService;
use App\Support\DocumentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gapless document counters, incremented inside the posting transaction by
 * {@see DocumentNumberService}.
 *
 * document_type = stable type code ({@see DocumentType}), never the prefix.
 * scope         = "B{branch id}" for per-branch series, "*" for one series across branches.
 * year          = calendar year for yearly series, 0 when the number has no year.
 *
 * document_number_settings holds the per-type format (prefix, parts, padding, start).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 32);
            $table->string('scope', 16);
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();

            $table->unique(['document_type', 'scope', 'year']);
        });

        Schema::create('document_number_settings', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 32)->unique();
            $table->string('prefix', 6);
            $table->boolean('include_branch')->default(true);
            $table->boolean('include_year')->default(true);
            $table->unsignedTinyInteger('padding')->default(6);
            $table->unsignedBigInteger('start_number')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_number_settings');
        Schema::dropIfExists('document_sequences');
    }
};
