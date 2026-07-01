<?php

use App\Models\User;
use App\Support\Installation;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->withoutVite();

    if (File::exists(Installation::markerPath())) {
        File::delete(Installation::markerPath());
    }

    Installation::forceUninstalledForTesting(true);
});

afterEach(function (): void {
    Installation::forceUninstalledForTesting(false);

    if (File::exists(Installation::markerPath())) {
        File::delete(Installation::markerPath());
    }
});

it('redirects guests to the setup wizard before installation', function (): void {
    $this->get(route('home'))
        ->assertRedirect(route('setup.show'));
});

it('shows the setup wizard with requirements', function (): void {
    $this->get(route('setup.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('setup/index')
            ->has('requirements')
            ->has('defaults')
            ->where('requirements.ready', true));
});

it('returns errors when database connection fails', function (): void {
    $this->mock(\App\Services\SetupService::class, function ($mock): void {
        $mock->shouldReceive('testDatabaseConnection')
            ->once()
            ->andThrow(\Illuminate\Validation\ValidationException::withMessages([
                'db_password' => 'Could not connect to the database. Check your host, username, and password.',
            ]));
    });

    $this->postJson(route('setup.database.test'), [
        'db_host' => '127.0.0.1',
        'db_port' => 3306,
        'db_database' => 'invalid',
        'db_username' => 'invalid',
        'db_password' => 'wrong-password',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['db_password']);
});

it('returns database test result when connection succeeds', function (): void {
    $this->mock(\App\Services\SetupService::class, function ($mock): void {
        $mock->shouldReceive('testDatabaseConnection')->once();
    });

    $this->postJson(route('setup.database.test'), [
        'db_host' => '127.0.0.1',
        'db_port' => 3306,
        'db_database' => 'statements_tracker',
        'db_username' => 'root',
        'db_password' => '',
    ])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Database connection successful.',
        ]);
});

it('rejects invalid install input', function (): void {
    $this->from(route('setup.show'))
        ->post(route('setup.install'), [
            'db_host' => '',
            'db_port' => '',
            'db_database' => '',
            'db_username' => '',
            'app_name' => '',
            'app_url' => 'not-a-url',
            'name' => '',
            'email' => 'invalid-email',
            'password' => 'short',
            'password_confirmation' => 'mismatch',
        ])
        ->assertRedirect(route('setup.show'))
        ->assertSessionHas('errors');
});

it('installs the application and signs in the administrator', function (): void {
    $payload = [
        'db_host' => '127.0.0.1',
        'db_port' => 3306,
        'db_database' => 'testing',
        'db_username' => 'root',
        'db_password' => '',
        'app_name' => 'Statement Analyzer',
        'app_url' => 'http://localhost',
        'name' => 'Setup Admin',
        'email' => 'admin@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ];

    $this->postJson(route('setup.install'), $payload)
        ->assertOk()
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonStructure(['redirect']);

    expect(File::exists(Installation::markerPath()))->toBeTrue();

    $this->assertAuthenticated();
    expect(User::query()->where('email', 'admin@example.com')->exists())->toBeTrue();
});

it('redirects away from setup after installation', function (): void {
    Installation::markInstalled();

    $this->get(route('setup.show'))
        ->assertRedirect(route('home'));
});
