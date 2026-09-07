<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('aiface.storage.table_prefix', 'aiface_');
        $tableName = $prefix . 'user_credentials';

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->string('sn')->index();
                $table->string('enrollid')->index();
                $table->integer('backupnum')->index(); // 0-9=FP, 10=pwd, 11=card, 40/41=palm, 50=face photo, 51=face feat
                $table->longText('record_data')->nullable();
                $table->timestamps();

                $table->unique(['sn', 'enrollid', 'backupnum']);
            });
        }
    }

    public function down(): void
    {
        $prefix = config('aiface.storage.table_prefix', 'aiface_');
        Schema::dropIfExists($prefix . 'user_credentials');
    }
};
