<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->char('order_id', 26);
            $table->string('status', 20);
            $table->text('note')->nullable();
            $table->char('changed_by_user_id', 26)->nullable();
            $table->string('changed_by_name', 120)->default('System');
            $table->timestamp('created_at')->nullable();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('changed_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
    }
};
