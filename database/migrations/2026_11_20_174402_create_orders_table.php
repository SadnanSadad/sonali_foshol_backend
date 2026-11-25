<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('order_number', 40)->unique();
            $table->char('client_request_id', 36)->nullable()->unique();
            $table->char('customer_id', 26)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('payment_method', 20);
            $table->string('delivery_zone', 20);
            $table->string('customer_name', 120);
            $table->string('customer_phone', 20);
            $table->string('customer_email', 190)->nullable();
            $table->string('district', 120);
            $table->text('address');
            $table->text('note')->nullable();
            $table->unsignedInteger('subtotal');
            $table->unsignedInteger('delivery_fee');
            $table->unsignedInteger('discount')->default(0);
            $table->unsignedInteger('total');
            $table->timestamp('expected_delivery_date')->nullable();
            $table->string('placed_by', 20)->default('guest');
            $table->string('rejection_reason', 40)->nullable();
            $table->text('rejection_feedback')->nullable();
            $table->string('rejected_by', 120)->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('courier_name', 120)->nullable();
            $table->string('courier_tracking_id', 120)->nullable();
            $table->timestamp('stock_restored_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->index('status');
            $table->index('created_at');
            $table->index(['status', 'created_at']);
            $table->index('customer_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
