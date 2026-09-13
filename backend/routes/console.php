<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Seeds a database that has never been seeded, and does nothing to one that has.
 *
 * A container host with no terminal cannot run `db:seed` by hand, so the first
 * deploy would otherwise come up with no accounts and nobody able to sign in.
 * Re-seeding on every restart is not the answer either: outside `local` the
 * seeder issues fresh passwords, so each restart would lock everybody out of
 * the passwords they had chosen. An existing login is the signal to stop.
 */
Artisan::command('hris:seed-if-empty', function () {
    if (User::query()->exists()) {
        $this->info('Accounts already exist — not seeding.');

        return;
    }

    $this->call('db:seed', ['--force' => true]);
})->purpose('Seed the database only if it has no accounts yet');
