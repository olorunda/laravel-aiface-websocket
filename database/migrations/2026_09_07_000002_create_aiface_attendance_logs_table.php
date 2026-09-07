<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('aiface.storage.table_prefix', 'aiface_');
        $tableName = $prefix . 'attendance_logs';

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->string('sn')->index();
                $table->string('enrollid')->index();
                $table->string('name')->nullable();
                $table->timestamp('punch_time')->index();
                $table->tinyInteger('mode')->default(0); // 0=pwd, 1=fp, 2=card, 3=face, etc.
                $table->tinyInteger('inout')->default(0); // 0=in, 1=out, etc.
                $table->tinyInteger('event')->default(0);
                $table->string('aliasid')->nullable();
                $table->string('image_path')->nullable();
                $table->string('note')->nullable();
                $table->json('raw_data')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $prefix = config('aiface.storage.table_prefix', 'aiface_');
        Schema::dropIfExists($prefix . 'attendance_logs');
    }
};
