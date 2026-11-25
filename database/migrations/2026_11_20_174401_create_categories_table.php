<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('slug', 120)->unique();
            $table->string('name_en', 120);
            $table->string('name_bn', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('hidden')->default(false);
            $table->timestamps();

            $table->index(['hidden', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
