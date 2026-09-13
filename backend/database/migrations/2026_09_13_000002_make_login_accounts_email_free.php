<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A login account no longer needs an email address.
 *
 * Sign-in is a username and a role, and the emailed reset link and email
 * verification are gone, so nothing on the web side reads `users.email` any
 * more. The column stays, nullable, because existing values are still what the
 * API's token login accepts from other ISMERS systems — dropping it would wipe
 * data that cannot be put back. `email_verified_at` and the reset-token table
 * served only the removed flows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_verified_at');
        });

        Schema::dropIfExists('password_reset_tokens');
    }

    public function down(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('email');
        });
    }
};
