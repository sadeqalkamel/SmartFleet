<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_locations', function (Blueprint $table) {
            $table->id();

            // Driver
            $table->foreignId('driver_id')
                ->constrained('drivers')
                ->restrictOnDelete();

            // Current route, if assigned
            $table->foreignId('route_id')
                ->nullable()
                ->constrained('routes')
                ->nullOnDelete();

            // GPS coordinates
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            // GPS metadata
            $table->decimal('accuracy_meters', 8, 2)
                ->nullable();

            $table->decimal('speed_mps', 8, 2)
                ->nullable();

            $table->decimal('heading_degrees', 5, 2)
                ->nullable();

            // Time recorded by the device
            $table->timestampTz('recorded_at');

            // Time received by the server
            $table->timestampTz('received_at')
                ->useCurrent();

            $table->timestamps();

            // Indexes for efficient tracking
            $table->index([
                'driver_id',
                'recorded_at'
            ]);

            $table->index([
                'route_id',
                'recorded_at'
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_locations');
    }
};