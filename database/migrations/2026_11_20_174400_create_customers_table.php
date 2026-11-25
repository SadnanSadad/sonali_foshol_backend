<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('name', 120);
            $table->string('phone', 20)->unique();
            $table->string('email', 190)->nullable()->unique();
            $table->string('password')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('district', 120);
            $table->text('address');
            $table->boolean('flagged')->default(false)->index();
            $table->rememberToken();
            $table->timestamp('last_order_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
