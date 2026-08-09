<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Baseline shifts and 2026 regular holidays (Module 2).
 */
class ShiftSeeder extends Seeder
{
    private const SHIFTS = [
        ['Day Shift', '08:00', '17:00', 60, 15, false],
        ['Early Dispatch', '06:00', '15:00', 60, 15, false],
        ['Mid Shift', '14:00', '23:00', 60, 15, false],
        ['Night Shift', '22:00', '07:00', 60, 15, true],
    ];

    private const HOLIDAYS = [
        ['New Year\'s Day', '2026-01-01', 'regular'],
        ['Araw ng Kagitingan', '2026-04-09', 'regular'],
        ['Labor Day', '2026-05-01', 'regular'],
        ['Independence Day', '2026-06-12', 'regular'],
        ['National Heroes Day', '2026-08-31', 'regular'],
        ['Bonifacio Day', '2026-11-30', 'regular'],
        ['Christmas Day', '2026-12-25', 'regular'],
        ['Rizal Day', '2026-12-30', 'regular'],
        ['Ninoy Aquino Day', '2026-08-21', 'special_non_working'],
        ['All Saints\' Day', '2026-11-01', 'special_non_working'],
        ['Feast of the Immaculate Conception', '2026-12-08', 'special_non_working'],
        ['Last Day of the Year', '2026-12-31', 'special_non_working'],
    ];

    public function run(): void
    {
        foreach (self::SHIFTS as [$name, $start, $end, $break, $grace, $isNight]) {
            DB::table('shifts')->updateOrInsert(
                ['name' => $name],
                [
                    'start_time' => $start,
                    'end_time' => $end,
                    'break_minutes' => $break,
                    'grace_period_minutes' => $grace,
                    'is_night_shift' => $isNight,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        foreach (self::HOLIDAYS as [$name, $date, $type]) {
            DB::table('holidays')->updateOrInsert(
                ['date' => $date, 'name' => $name],
                [
                    'type' => $type,
                    'is_nationwide' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }
}
