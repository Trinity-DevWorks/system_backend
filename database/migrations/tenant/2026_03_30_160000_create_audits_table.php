<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('audit.drivers.database.connection', config('database.default'));
        $auditTable = config('audit.drivers.database.table', 'audits');

        Schema::connection($connection)->create($auditTable, function (Blueprint $blueprint) {
            $morphPrefix = config('audit.user.morph_prefix', 'user');

            $blueprint->bigIncrements('id');

            $blueprint->string("{$morphPrefix}_type")->nullable();
            $blueprint->string("{$morphPrefix}_id")->nullable();

            $blueprint->string('auditable_id');
            $blueprint->string('auditable_type');

            $blueprint->string('event');
            $blueprint->text('old_values')->nullable();
            $blueprint->text('new_values')->nullable();
            $blueprint->text('url')->nullable();
            $blueprint->ipAddress('ip_address')->nullable();
            $blueprint->string('user_agent', 1023)->nullable();
            $blueprint->string('tags')->nullable();
            $blueprint->timestamps();

            $blueprint->index(["{$morphPrefix}_id", "{$morphPrefix}_type"]);
        });
    }

    public function down(): void
    {
        $connection = config('audit.drivers.database.connection', config('database.default'));
        $auditTable = config('audit.drivers.database.table', 'audits');

        Schema::connection($connection)->dropIfExists($auditTable);
    }
};
