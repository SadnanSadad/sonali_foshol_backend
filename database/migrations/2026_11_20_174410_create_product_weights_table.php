<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_weights', function (Blueprint $table) {
            $table->id();
            $table->char('product_id', 26);
            $table->string('label', 80);
            $table->unsignedInteger('grams');
            $table->unsignedInteger('price');
            $table->unsignedInteger('mrp');
            $table->unsignedInteger('stock')->default(0);
            $table->string('sku', 80)->nullable()->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->unique(['product_id', 'grams']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_weights');
    }
};
