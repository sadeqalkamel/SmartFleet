<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->string('order_number', 50)->unique();

            // Customer information
            $table->string('customer_name', 150);
            $table->string('customer_phone', 30)->nullable();

            // Delivery destination
            $table->text('delivery_address');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            // Shipment information
            $table->decimal('weight_kg', 10, 2);
            $table->unsignedSmallInteger('service_minutes')
                ->default(5);

            // Delivery time window
            $table->timestampTz('delivery_window_start')
                ->nullable();

            $table->timestampTz('delivery_window_end')
                ->nullable();

            // Priority and status
            $table->string('priority', 20)
                ->default('normal');

            $table->string('status', 30)
                ->default('pending');

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('priority');
            $table->index(['latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};