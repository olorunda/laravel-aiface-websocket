<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('aiface.storage.table_prefix', 'aiface_');
        $tableName = $prefix . 'command_history';

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->string('sn')->index();
                $table->string('cmd', 64)->index();
                $table->string('enroll_id', 64)->nullable()->index();
                $table->string('name', 255)->nullable()->index();
                $table->tinyInteger('backupnum')->nullable();
                $table->string('status', 32)->default('success')->index();
                $table->longText('request_payload')->nullable();
                $table->longText('response_payload')->nullable();
                $table->boolean('result')->default(false);
                $table->timestamps();
            });
        } else {
            Schema::table($tableName, function (Blueprint $table) {
                if (!Schema::hasColumn($table->getTable(), 'enroll_id')) {
                    $table->string('enroll_id', 64)->nullable()->index();
                }
                if (!Schema::hasColumn($table->getTable(), 'name')) {
                    $table->string('name', 255)->nullable()->index();
                }
                if (!Schema::hasColumn($table->getTable(), 'backupnum')) {
                    $table->tinyInteger('backupnum')->nullable();
                }
                if (!Schema::hasColumn($table->getTable(), 'status')) {
                    $table->string('status', 32)->default('success')->index();
                }
            });
        }
    }

    public function down(): void
    {
        $prefix = config('aiface.storage.table_prefix', 'aiface_');
        Schema::dropIfExists($prefix . 'command_history');
    }
};
