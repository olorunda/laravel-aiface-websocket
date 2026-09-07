<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('aiface.storage.table_prefix', 'aiface_');
        $tableName = $prefix . 'scheduled_commands';

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->string('task_id', 64)->unique();
                $table->string('sn', 64)->index();
                $table->string('cmd', 64)->index();
                $table->string('enrollid')->nullable()->index();
                $table->integer('backupnum')->nullable();
                $table->integer('delay_seconds')->default(0);
                $table->timestamp('execute_at')->index();
                $table->string('status', 32)->default('pending')->index(); // pending, executed, cancelled, failed
                $table->text('payload')->nullable();
                $table->text('response')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $prefix = config('aiface.storage.table_prefix', 'aiface_');
        Schema::dropIfExists($prefix . 'scheduled_commands');
    }
};
