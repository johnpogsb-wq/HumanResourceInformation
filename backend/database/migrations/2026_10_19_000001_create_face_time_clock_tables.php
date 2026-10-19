<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The face-verified time clock: break punches on the day, the enrolled face,
 * and the raw punches the day is computed from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dateTime('break_start')->nullable()->after('time_in');
            $table->dateTime('break_end')->nullable()->after('break_start');
        });

        Schema::create('face_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            // Encrypted: a face template is biometric data, sensitive personal
            // information under RA 10173, and text because the ciphertext is long.
            $table->text('descriptors');
            $table->string('snapshot_path')->nullable();
            $table->timestamp('consented_at');
            $table->foreignId('enrolled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_log_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 20);
            $table->dateTime('punched_at');
            $table->decimal('match_distance', 5, 4);
            $table->string('snapshot_path')->nullable();
            // Who was signed in on the kiosk the face was read at; null when
            // the employee punched from their own login.
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'punched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_punches');
        Schema::dropIfExists('face_enrollments');

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropColumn(['break_start', 'break_end']);
        });
    }
};
