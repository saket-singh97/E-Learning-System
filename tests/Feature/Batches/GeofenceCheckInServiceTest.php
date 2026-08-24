<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchGeofence;
use App\Domains\Batches\Services\GeofenceCheckInService;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
    $this->service = app(GeofenceCheckInService::class);
});

function batchGeofenceRow(float $lat, float $lng, int $radiusMeters): BatchGeofence
{
    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $batch = Batch::create(['course_id' => $course->id, 'title' => 'Batch', 'slug' => 'batch-'.uniqid(), 'starts_on' => now()->toDateString(), 'ends_on' => now()->addMonth()->toDateString(), 'self_enrol_mode' => 'manual_only', 'status' => 'published']);

    return BatchGeofence::create(['batch_id' => $batch->id, 'latitude' => $lat, 'longitude' => $lng, 'radius_meters' => $radiusMeters]);
}

it('accepts a check-in inside the radius', function () {
    // Bangalore MG Road, ~100m radius.
    $geofence = batchGeofenceRow(12.9752, 77.6068, 100);

    // A point ~30m away (small lat offset).
    expect($this->service->isWithinRadius($geofence, 12.9755, 77.6068))->toBeTrue();
});

it('refuses a check-in outside the radius', function () {
    $geofence = batchGeofenceRow(12.9752, 77.6068, 100);

    // ~1.1km away — well outside a 100m radius.
    expect($this->service->isWithinRadius($geofence, 12.9852, 77.6068))->toBeFalse();
});

it('accepts a check-in exactly at the geofence center', function () {
    $geofence = batchGeofenceRow(12.9752, 77.6068, 50);

    expect($this->service->isWithinRadius($geofence, 12.9752, 77.6068))->toBeTrue();
});

it('refuses a check-in just past the radius boundary', function () {
    // 0 radius means only the exact center point qualifies — any nonzero
    // offset is outside.
    $geofence = batchGeofenceRow(12.9752, 77.6068, 0);

    expect($this->service->isWithinRadius($geofence, 12.9752, 77.6069))->toBeFalse();
});
