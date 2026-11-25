<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('store_name', 160)->default('Sonali Foshol Organics');
            $table->string('store_name_bn', 160)->default('সোনালী ফসল অর্গানিকস');
            $table->string('brand_tagline_bn', 255)->default('খাঁটি গ্রামের খাবার, সরাসরি কৃষকের কাছ থেকে');
            $table->string('whatsapp_number', 20)->default('8801712345678');
            $table->string('facebook_url', 500)->default('https://facebook.com/sonalifoshol');
            $table->string('messenger_url', 500)->default('https://m.me/sonalifoshol');
            $table->unsignedInteger('delivery_fee_inside')->default(60);
            $table->unsignedInteger('delivery_fee_outside')->default(120);
            $table->unsignedInteger('free_shipping_threshold')->default(2000);
            $table->unsignedInteger('low_stock_threshold')->default(10);
            $table->string('phone_display', 40)->default('+৮৮০ ১৭১২-৩৪৫৬৭৮');
            $table->string('email', 190)->default('hello@sonalifoshol.com');
            $table->string('address_bn', 255)->default('চিরিরবন্দর, দিনাজপুর, বাংলাদেশ');
            $table->unsignedTinyInteger('inside_delivery_days')->default(2);
            $table->unsignedTinyInteger('outside_delivery_days')->default(4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
