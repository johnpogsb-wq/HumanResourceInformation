<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Standard Philippine leave entitlements (Module 3).
 */
class LeaveTypeSeeder extends Seeder
{
    private const TYPES = [
        ['SL', 'Sick Leave', 15, true, true, null, false],
        // Convertible: unused VL is paid out in the back pay. It stands in for
        // the Service Incentive Leave, and Article 95 makes unused SIL
        // commutable to cash — so a VL that were not convertible would leave
        // every departing employee's leave conversion at ₱0.
        ['VL', 'Vacation Leave', 15, true, false, 15, true],
        ['EL', 'Emergency Leave', 3, true, false, 3, false],
        ['ML', 'Maternity Leave', 105, true, true, 105, false],
        ['PL', 'Paternity Leave', 7, true, true, 7, false],
        ['BL', 'Bereavement Leave', 3, true, false, 3, false],
        // Named "Absence Without Leave" per the owner's own usage rather than
        // the more common "Leave Without Pay" — same unpaid type underneath,
        // still filed and decided through the ordinary Leave Requests flow.
        ['AWOL', 'Absence Without Leave', 0, false, false, null, false],
    ];

    public function run(): void
    {
        foreach (self::TYPES as [$code, $name, $credits, $isPaid, $needsAttachment, $maxDays, $convertible]) {
            DB::table('leave_types')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => $name,
                    'default_credits' => $credits,
                    'is_paid' => $isPaid,
                    'requires_attachment' => $needsAttachment,
                    'max_consecutive_days' => $maxDays,
                    'is_convertible_to_cash' => $convertible,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }
}
