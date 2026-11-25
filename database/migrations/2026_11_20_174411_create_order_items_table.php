<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->char('order_id', 26);
            $table->char('product_id', 26)->nullable();
            $table->unsignedBigInteger('product_weight_id')->nullable();
            $table->string('product_slug', 160);
            $table->string('product_name_bn', 200);
            $table->string('weight_label', 80);
            $table->unsignedInteger('grams');
            $table->unsignedInteger('unit_price');
            $table->unsignedInteger('qty');
            $table->unsignedInteger('line_total');
            $table->string('image_path', 500)->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('product_weight_id')->references('id')->on('product_weights')->nullOnDelete();
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
