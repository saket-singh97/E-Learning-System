<?php

use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Finder\Finder;

uses(RefreshDatabase::class);

it('has CSRF protection registered on the web middleware group', function () {
    expect(Route::getMiddlewareGroups()['web'])->toContain(PreventRequestForgery::class);
});

it('has no $guarded = [] on any domain model', function () {
    $files = Finder::create()->in(app_path('Domains'))->name('*.php')->files();

    $offenders = [];
    foreach ($files as $file) {
        if (str_contains($file->getContents(), '$guarded = []')) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});

it('has no raw SQL in domain folders', function () {
    $files = Finder::create()->in(app_path('Domains'))->name('*.php')->files();

    $offenders = [];
    foreach ($files as $file) {
        $contents = $file->getContents();
        foreach (['DB::raw', 'DB::statement', 'whereRaw'] as $needle) {
            if (str_contains($contents, $needle)) {
                $offenders[] = $file->getRelativePathname().' uses '.$needle;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('strips a script tag from a course description before it is stored', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create([
        'title' => 'Mechanics',
        'slug' => 'mechanics',
        'description' => 'Intro <script>alert(1)</script> to motion.',
    ]);

    expect($course->fresh()->description)
        ->not->toContain('<script>')
        ->toBe('Intro alert(1) to motion.');
});
