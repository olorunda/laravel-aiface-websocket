<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('aiface.storage.table_prefix', 'aiface_');
        $tableName = $prefix . 'devices';

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->string('sn')->unique()->index();
                $table->string('ip')->nullable();
                $table->integer('port')->nullable();
                $table->string('model')->default('AiFace');
                $table->string('manufacturer')->nullable();
                $table->string('firmware')->nullable();
                $table->integer('usersize')->default(0);
                $table->integer('facesize')->default(0);
                $table->integer('fpsize')->default(0);
                $table->integer('logsize')->default(0);
                $table->integer('useduser')->default(0);
                $table->integer('usedface')->default(0);
                $table->integer('usedfp')->default(0);
                $table->integer('usedlog')->default(0);
                $table->string('status', 30)->default('offline')->index();
                $table->json('devinfo')->nullable();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->timestamp('registered_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $prefix = config('aiface.storage.table_prefix', 'aiface_');
        Schema::dropIfExists($prefix . 'devices');
    }
};
