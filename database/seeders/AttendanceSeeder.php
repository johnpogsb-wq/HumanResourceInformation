<?php

namespace Database\Seeders;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\Holiday;
use App\Models\Shift;
use App\Services\AttendanceCalculator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gives every employee a weekday schedule and two weeks of realistic DTR
 * history, so the Timekeeping screen has something to show.
 */
class AttendanceSeeder extends Seeder
{
    private const DAYS_OF_HISTORY = 14;

    public function run(AttendanceCalculator $calculator): void
    {
        if (AttendanceLog::exists()) {
            return;
        }

        $dayShift = Shift::where('name', 'Day Shift')->first();
        $nightShift = Shift::where('name', 'Night Shift')->first();

        if (! $dayShift) {
            $this->command?->warn('No shifts found — run ShiftSeeder first.');

            return;
        }

        $employees = Employee::where('status', 'active')->get();
        $holidays = Holiday::pluck('date')->map(fn ($date) => Carbon::parse($date)->toDateString())->all();

        $rows = [];

        foreach ($employees->values() as $index => $employee) {
            // Every fifth employee runs nights; the rest are on days.
            $shift = ($nightShift && $index % 5 === 4) ? $nightShift : $dayShift;

            EmployeeSchedule::firstOrCreate(
                ['employee_id' => $employee->id, 'shift_id' => $shift->id],
                [
                    'effective_from' => Carbon::now()->subYear()->toDateString(),
                    'days_of_week' => [1, 2, 3, 4, 5],
                ],
            );

            // Runs through today (>= 0), so the dashboard's "today" figures are
            // populated the moment the seeder finishes.
            for ($back = self::DAYS_OF_HISTORY; $back >= 0; $back--) {
                $date = Carbon::today()->subDays($back);
                $isWeekend = $date->dayOfWeekIso >= 6;
                $isHoliday = in_array($date->toDateString(), $holidays, true);

                $punches = $this->punchesFor($date, $shift, $isWeekend, $isHoliday);

                $computed = $calculator->compute(
                    date: $date,
                    shift: $shift,
                    timeIn: $punches['in'],
                    timeOut: $punches['out'],
                    isRestDay: $isWeekend,
                    isHoliday: $isHoliday,
                );

                $rows[] = [
                    ...$computed,
                    'employee_id' => $employee->id,
                    'shift_id' => $shift->id,
                    'log_date' => $date->toDateString(),
                    'time_in' => $punches['in'],
                    'time_out' => $punches['out'],
                    'source' => 'biometric',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('attendance_logs')->insert($chunk);
        }

        $this->command?->info('Seeded '.count($rows).' attendance records.');
    }

    /**
     * Produces believable punches: mostly on time, sometimes late, occasionally
     * absent, with a little overtime.
     *
     * @return array{in: ?Carbon, out: ?Carbon}
     */
    private function punchesFor(Carbon $date, Shift $shift, bool $isWeekend, bool $isHoliday): array
    {
        if ($isWeekend || $isHoliday) {
            return ['in' => null, 'out' => null];
        }

        // Roughly one absence per employee per month.
        if (random_int(1, 100) <= 4) {
            return ['in' => null, 'out' => null];
        }

        [$startHour, $startMinute] = array_map('intval', explode(':', $shift->start_time));
        [$endHour, $endMinute] = array_map('intval', explode(':', $shift->end_time));

        $in = $date->copy()->setTime($startHour, $startMinute)
            ->addMinutes(random_int(-10, 100) > 70 ? random_int(16, 45) : random_int(-10, 10));

        $out = $date->copy()->setTime($endHour, $endMinute);

        if ($shift->crossesMidnight()) {
            $out->addDay();
        }

        // A quarter of days run a little over.
        $out->addMinutes(random_int(1, 100) <= 25 ? random_int(30, 180) : random_int(-5, 5));

        return ['in' => $in, 'out' => $out];
    }
}
