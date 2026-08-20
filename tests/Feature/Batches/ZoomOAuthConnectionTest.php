<?php

use App\Domains\Batches\Models\InstituteZoomConnection;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Admin\Pages\ZoomConnection;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config([
        'services.zoom.oauth_client_id' => 'test-client-id',
        'services.zoom.oauth_client_secret' => 'test-client-secret',
    ]);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');
});

it('redirects an institute admin to zooms own authorize url and remembers tenant/user in session', function () {
    $response = $this->actingAs($this->admin)
        ->get('http://physics.lms.test/zoom/connect');

    $response->assertRedirect();
    expect($response->headers->get('Location'))
        ->toContain('zoom.us/oauth/authorize')
        ->toContain('client_id=test-client-id');
    expect(session('zoom_oauth.tenant_id'))->toBe($this->tenant->id);
    expect(session('zoom_oauth.user_id'))->toBe($this->admin->id);
});

it('403s a non-institute-admin hitting the connect route', function () {
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');

    $this->actingAs($student)
        ->get('http://physics.lms.test/zoom/connect')
        ->assertForbidden();
});

it('exchanges the code for tokens and stores the connection on callback', function () {
    Http::fake([
        'zoom.us/oauth/token' => Http::response([
            'access_token' => 'access-123',
            'refresh_token' => 'refresh-456',
            'expires_in' => 3600,
        ], 200),
        'api.zoom.us/v2/users/me' => Http::response(['account_id' => 'ZoomAcct123'], 200),
    ]);

    $this->withSession([
        'zoom_oauth.tenant_id' => $this->tenant->id,
        'zoom_oauth.user_id' => $this->admin->id,
    ]);

    $response = $this->get('http://lms.test/auth/zoom/callback?code=auth-code-xyz');

    $connection = InstituteZoomConnection::where('tenant_id', $this->tenant->id)->sole();
    expect($connection->account_id)->toBe('ZoomAcct123')
        ->and($connection->access_token)->toBe('access-123')
        ->and($connection->refresh_token)->toBe('refresh-456')
        ->and($connection->connected_by_user_id)->toBe($this->admin->id)
        ->and($connection->token_expires_at->diffInSeconds(now()->addSeconds(3600)))->toBeLessThan(5);

    $response->assertRedirect(ZoomConnection::getUrl(['subdomain' => 'physics'], panel: 'admin'));
});

it('updates the existing connection on a re-connect rather than creating a duplicate', function () {
    Http::fake([
        'zoom.us/oauth/token' => Http::response([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_in' => 3600,
        ], 200),
        'api.zoom.us/v2/users/me' => Http::response(['account_id' => 'ZoomAcct123'], 200),
    ]);

    $this->withSession(['zoom_oauth.tenant_id' => $this->tenant->id, 'zoom_oauth.user_id' => $this->admin->id]);
    $this->get('http://lms.test/auth/zoom/callback?code=first-code');

    $this->withSession(['zoom_oauth.tenant_id' => $this->tenant->id, 'zoom_oauth.user_id' => $this->admin->id]);
    $this->get('http://lms.test/auth/zoom/callback?code=second-code');

    expect(InstituteZoomConnection::where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

it('aborts the callback when the session has no pending zoom oauth tenant', function () {
    $this->get('http://lms.test/auth/zoom/callback?code=whatever')->assertStatus(419);
});

it('shows not-connected and the connect action before any connection exists', function () {
    $this->actingAs($this->admin);

    Livewire::test(ZoomConnection::class)
        ->assertSee('Not connected')
        ->assertActionVisible('connect')
        ->assertActionHidden('disconnect');
});

it('shows connected and the disconnect action once a connection exists, and disconnect deletes it', function () {
    InstituteZoomConnection::create([
        'account_id' => 'ZoomAcct123',
        'access_token' => 'access-123',
        'refresh_token' => 'refresh-456',
        'token_expires_at' => now()->addHour(),
        'connected_by_user_id' => $this->admin->id,
    ]);

    $this->actingAs($this->admin);

    Livewire::test(ZoomConnection::class)
        ->assertSee('Connected')
        ->assertSee('ZoomAcct123')
        ->assertActionHidden('connect')
        ->assertActionVisible('disconnect')
        ->callAction('disconnect');

    expect(InstituteZoomConnection::where('tenant_id', $this->tenant->id)->exists())->toBeFalse();
});

it('is visible on the real admin panel page', function () {
    $this->actingAs($this->admin)
        ->get('http://physics.lms.test/admin/zoom-connection')
        ->assertOk();
});
