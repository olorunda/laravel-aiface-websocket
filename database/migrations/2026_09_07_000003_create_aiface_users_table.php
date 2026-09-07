<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('aiface.storage.table_prefix', 'aiface_');
        $tableName = $prefix . 'users';

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->string('sn')->index();
                $table->string('enrollid')->index();
                $table->string('name')->nullable();
                $table->tinyInteger('admin')->default(0);
                $table->string('card')->nullable();
                $table->string('pwd')->nullable();
                $table->tinyInteger('enable')->default(1);
                $table->string('aliasid')->nullable();
                $table->timestamps();

                $table->unique(['sn', 'enrollid']);
            });
        }
    }

    public function down(): void
    {
        $prefix = config('aiface.storage.table_prefix', 'aiface_');
        Schema::dropIfExists($prefix . 'users');
    }
};
