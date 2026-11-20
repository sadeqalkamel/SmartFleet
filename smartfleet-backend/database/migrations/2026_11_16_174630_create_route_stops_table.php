<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_stops', function (Blueprint $table) {
            $table->id();

            // Parent route
            $table->foreignId('route_id')
                ->constrained('routes')
                ->restrictOnDelete();

            // Assigned order
            $table->foreignId('order_id')
                ->constrained('orders')
                ->restrictOnDelete();

            // Stop order within the route
            $table->unsignedInteger('stop_sequence');

            // Planned arrival time
            $table->timestampTz('estimated_arrival_at')
                ->nullable();

            // Actual arrival time
            $table->timestampTz('actual_arrival_at')
                ->nullable();

            // Delivery completion time
            $table->timestampTz('completed_at')
                ->nullable();

            // Distance from previous stop
            $table->unsignedInteger(
                'distance_from_previous_meters'
            )->nullable();

            // Estimated travel duration
            $table->unsignedInteger(
                'travel_duration_minutes'
            )->nullable();

            // Stop status
            $table->string('status', 30)
                ->default('pending');

            $table->text('notes')->nullable();

            $table->timestamps();

            // Prevent duplicate sequence numbers
            $table->unique([
                'route_id',
                'stop_sequence'
            ]);

            // Prevent assigning the same order
            // twice to the same route
            $table->unique([
                'route_id',
                'order_id'
            ]);

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_stops');
    }
};