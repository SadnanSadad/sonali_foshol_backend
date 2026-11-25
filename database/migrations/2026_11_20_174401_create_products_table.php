<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('category_id', 26);
            $table->string('slug', 160)->unique();
            $table->string('name_bn', 200);
            $table->string('name_en', 200)->nullable();
            $table->string('origin_bn', 200)->nullable();
            $table->text('short_desc_bn');
            $table->longText('full_desc_bn');
            $table->string('image_path', 500);
            $table->string('badge_bn', 120)->nullable();
            $table->boolean('in_stock')->default(true);
            $table->string('status', 20)->default('active');
            $table->json('nutrition_highlights')->nullable();
            $table->json('nutrition_facts')->nullable();
            $table->text('cooking_tips_bn')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('category_id')->references('id')->on('categories')->restrictOnDelete();
            $table->index('status');
            $table->index(['category_id', 'status']);
            $table->index(['status', 'in_stock']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
