<?php

use App\Models\Client;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->withoutVite();
});

test('guests cannot access database backup settings', function () {
    $this->get(route('data.edit'))->assertRedirect(route('login'));
});

test('users can view database backup settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('data.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/data')
            ->has('summary.driver')
            ->has('summary.database')
            ->has('summary.tables')
            ->has('summary.records.clients'));
});

test('users can wipe application data but keep user accounts', function () {
    $user = User::factory()->create(['email' => 'owner@example.com']);
    Client::factory()->forUser($user)->create(['name' => 'Wipe Me Client']);

    expect(User::query()->count())->toBe(1)
        ->and(Client::query()->count())->toBe(1);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('data.wipe'), ['confirm' => '1'])
        ->assertRedirect(route('data.edit'));

    expect(User::query()->where('email', 'owner@example.com')->exists())->toBeTrue()
        ->and(Client::query()->count())->toBe(0)
        ->and(DB::table('branches')->count())->toBe(0)
        ->and(DB::table('statement_entries')->count())->toBe(0);
});

test('users can restore application data after wiping', function () {
    $user = User::factory()->create();
    Client::factory()->forUser($user)->create(['name' => 'After Wipe Restore']);

    $exportResponse = $this->actingAs($user)->get(route('data.export'));
    $backupPath = $exportResponse->baseResponse->getFile()->getPathname();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('data.wipe'), ['confirm' => '1'])
        ->assertRedirect(route('data.edit'));

    expect(Client::query()->count())->toBe(0);

    $extensionlessUploadPath = sys_get_temp_dir().'/php'.uniqid('', true);
    copy($backupPath, $extensionlessUploadPath);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('data.restore'), [
            'backup' => new UploadedFile(
                $extensionlessUploadPath,
                'database-backup.sql.gz',
                'application/gzip',
                null,
                true,
            ),
            'confirm' => '1',
        ])
        ->assertRedirect(route('login'));

    expect(Client::query()->where('name', 'After Wipe Restore')->exists())->toBeTrue();
});

test('wipe requires confirmation', function () {
    $user = User::factory()->create();
    Client::factory()->forUser($user)->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('data.edit'))
        ->post(route('data.wipe'), [])
        ->assertRedirect(route('data.edit'))
        ->assertSessionHasErrors('confirm');

    expect(Client::query()->count())->toBe(1);
});

test('users can export and restore a full database backup', function () {
    $user = User::factory()->create();
    Client::factory()->forUser($user)->create(['name' => 'Lulu Hyper Market']);

    $exportResponse = $this->actingAs($user)->get(route('data.export'));

    $exportResponse->assertSuccessful();
    $exportResponse->assertDownload();

    $backupPath = $exportResponse->baseResponse->getFile()->getPathname();

    expect($backupPath)->toBeFile();

    DB::table('clients')->delete();

    expect(Client::query()->count())->toBe(0);

    $restorePath = sys_get_temp_dir().'/db-restore-'.uniqid('', true).'.sql.gz';
    copy($backupPath, $restorePath);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('data.restore'), [
            'backup' => new UploadedFile(
                $restorePath,
                'database-backup.sql.gz',
                'application/gzip',
                null,
                true,
            ),
            'confirm' => '1',
        ])
        ->assertRedirect(route('login'))
        ->assertSessionHas('status');

    expect(Client::query()->where('name', 'Lulu Hyper Market')->exists())->toBeTrue();
});

test('restore accepts sql.gz uploads stored without a file extension', function () {
    $user = User::factory()->create();
    Client::factory()->forUser($user)->create(['name' => 'Restore Me Shop']);

    $exportResponse = $this->actingAs($user)->get(route('data.export'));
    $backupPath = $exportResponse->baseResponse->getFile()->getPathname();

    DB::table('clients')->delete();

    $extensionlessUploadPath = sys_get_temp_dir().'/php'.uniqid('', true);
    copy($backupPath, $extensionlessUploadPath);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('data.restore'), [
            'backup' => new UploadedFile(
                $extensionlessUploadPath,
                'database-backup-statement_tracker-2026-06-28-072241.sql.gz',
                'application/gzip',
                null,
                true,
            ),
            'confirm' => '1',
        ])
        ->assertRedirect(route('login'));

    expect(Client::query()->where('name', 'Restore Me Shop')->exists())->toBeTrue();
});

test('restore rejects invalid database backup files', function () {
    $user = User::factory()->create();

    $invalidPath = tempnam(sys_get_temp_dir(), 'backup');
    file_put_contents($invalidPath, 'not-a-database-backup');

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('data.edit'))
        ->post(route('data.restore'), [
            'backup' => new UploadedFile(
                $invalidPath,
                'backup.sql.gz',
                'application/gzip',
                null,
                true,
            ),
            'confirm' => '1',
        ])
        ->assertRedirect(route('data.edit'))
        ->assertSessionHasErrors('backup');
});
