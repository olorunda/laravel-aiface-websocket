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

        if (Schema::hasTable($tableName)) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::hasColumn($tableName, 'enroll_id')) {
                    $table->string('enroll_id', 64)->nullable()->index();
                }
                if (!Schema::hasColumn($tableName, 'name')) {
                    $table->string('name', 255)->nullable()->index();
                }
                if (!Schema::hasColumn($tableName, 'backupnum')) {
                    $table->tinyInteger('backupnum')->nullable();
                }
                if (!Schema::hasColumn($tableName, 'status')) {
                    $table->string('status', 32)->default('success')->index();
                }
            });
        }
    }

    public function down(): void
    {
        $prefix = config('aiface.storage.table_prefix', 'aiface_');
        $tableName = $prefix . 'command_history';

        if (Schema::hasTable($tableName)) {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                return;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $cols = [];
                if (Schema::hasColumn($tableName, 'enroll_id')) {
                    $cols[] = 'enroll_id';
                }
                if (Schema::hasColumn($tableName, 'name')) {
                    $cols[] = 'name';
                }
                if (Schema::hasColumn($tableName, 'backupnum')) {
                    $cols[] = 'backupnum';
                }
                if (Schema::hasColumn($tableName, 'status')) {
                    $cols[] = 'status';
                }

                if (!empty($cols)) {
                    $table->dropColumn($cols);
                }
            });
        }
    }
};
