<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('product_id', 26)->nullable();
            $table->string('product_name_bn', 200)->nullable();
            $table->string('author', 120);
            $table->string('location', 160)->nullable();
            $table->unsignedTinyInteger('rating');
            $table->text('text');
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->index('status');
            $table->index(['status', 'product_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
