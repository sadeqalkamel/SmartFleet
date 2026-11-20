<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routes', function (Blueprint $table) {
            $table->id();

            // Route identification
            $table->string('route_number', 50)->unique();

            // Assigned driver
            $table->foreignId('driver_id')
                ->constrained('drivers')
                ->restrictOnDelete();

            // Assigned vehicle
            $table->foreignId('vehicle_id')
                ->constrained('vehicles')
                ->restrictOnDelete();

            // Route schedule
            $table->date('route_date');

            $table->string('status', 20)
                ->default('planned');

            // Starting location
            $table->decimal('start_latitude', 10, 7);
            $table->decimal('start_longitude', 10, 7);

            $table->text('start_address')->nullable();

            // Estimated route metrics
            $table->unsignedInteger('total_distance_meters')
                ->nullable();

            $table->unsignedInteger('estimated_duration_minutes')
                ->nullable();

            // Actual execution times
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['route_date', 'status']);
            $table->index(['driver_id', 'route_date']);
            $table->index(['vehicle_id', 'route_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routes');
    }
};