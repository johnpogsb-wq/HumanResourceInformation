<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Usernames take the company's shape: `admin` becomes `admin@primepower.test`.
 *
 * It reads like an address and is not one — nothing is mailed to it. HR's seed
 * login is renamed `hrstaff@primepower.test` to say which role it is. Inlined
 * rather than calling the model, so the migration keeps producing the same
 * result however the model changes later.
 */
return new class extends Migration
{
    private const DOMAIN = '@primepower.test';

    public function up(): void
    {
        $taken = DB::table('users')->pluck('username')->all();

        foreach (DB::table('users')->where('username', 'not like', '%@%')->orderBy('id')->get(['id', 'username']) as $user) {
            $base = $user->username === 'hr' ? 'hrstaff' : $user->username;
            $candidate = $base.self::DOMAIN;

            for ($n = 2; in_array($candidate, $taken, true); $n++) {
                $candidate = $base.$n.self::DOMAIN;
            }

            DB::table('users')->where('id', $user->id)->update(['username' => $candidate]);
            $taken[] = $candidate;
        }
    }

    public function down(): void
    {
        foreach (DB::table('users')->where('username', 'like', '%'.self::DOMAIN)->get(['id', 'username']) as $user) {
            $base = substr($user->username, 0, -strlen(self::DOMAIN));

            DB::table('users')->where('id', $user->id)->update([
                'username' => $base === 'hrstaff' ? 'hr' : $base,
            ]);
        }
    }
};
