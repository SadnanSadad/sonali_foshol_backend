<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('title', 200);
            $table->string('type', 20)->default('admin');
            $table->timestamp('start_at');
            $table->timestamp('end_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->text('note')->nullable();
            $table->char('order_id', 26)->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            $table->index('start_at');
            $table->index('end_at');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
