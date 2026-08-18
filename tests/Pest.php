<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature', 'Isolation');

/*
|--------------------------------------------------------------------------
| Cache isolation
|--------------------------------------------------------------------------
|
| S12 B12 introduced this app's first-ever Cache:: usage (AggregateCache).
| RefreshDatabase resets the database between tests, but not Redis — and
| auto-increment ids restart from 1 each time, so a tenant/student id from
| one test can collide with a stale AggregateCache entry left by an
| earlier test that happened to reuse the same id. Flushed on the 'cache'
| Redis connection only (config/database.php's own REDIS_CACHE_DB, a
| separate logical database from 'default', which session/queue use), so
| this never touches session or queue state.
|
*/
afterEach(function () {
    \Illuminate\Support\Facades\Cache::flush();
});

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
