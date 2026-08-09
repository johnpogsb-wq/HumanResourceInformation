<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DepartmentSeeder::class,
            LeaveTypeSeeder::class,
            ShiftSeeder::class,
        ]);

        $this->seedAdminUsers();
        $this->seedEmployees();

        // Both need employees; leave also reads schedules to skip rest days.
        $this->call(AttendanceSeeder::class);
        $this->call(LeaveSeeder::class);

        // Reads the attendance and leave the two seeders above just created.
        $this->call(PayrollSeeder::class);
        $this->call(PerformanceSeeder::class);
    }

    private function seedAdminUsers(): void
    {
        $accounts = [
            ['name' => 'System Administrator', 'email' => 'admin@primepower.test', 'role' => User::ROLE_ADMIN],
            ['name' => 'Maria Santos', 'email' => 'hr@primepower.test', 'role' => User::ROLE_HR_STAFF],
        ];

        foreach ($accounts as $account) {
            User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'role' => $account['role'],
                    'password' => 'password',
                    'is_active' => true,
                    'email_verified_at' => now(),
                ],
            );
        }
    }

    private function seedEmployees(): void
    {
        if (Employee::exists()) {
            return;
        }

        $departments = Department::with('positions')->get();

        // Supervisors first so the rest have someone to report to.
        $supervisors = collect();

        foreach ($departments as $department) {
            $position = $department->positions->first();

            $employee = Employee::factory()->create([
                'department_id' => $department->id,
                'position_id' => $position?->id,
                'employment_status' => 'regular',
                'basic_salary' => 65000,
            ]);

            $user = User::create([
                'name' => $employee->full_name,
                'email' => $employee->email,
                'role' => User::ROLE_SUPERVISOR,
                'password' => 'password',
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            $employee->update(['user_id' => $user->id]);
            $department->update(['head_employee_id' => $employee->id]);

            $supervisors->push($employee);
        }

        foreach ($departments as $department) {
            $supervisor = $supervisors->firstWhere('department_id', $department->id);
            $positions = $department->positions;
            $isOperations = str_contains(strtolower($department->name), 'operations');

            $count = $isOperations ? 12 : random_int(3, 6);

            $factory = Employee::factory()->count($count);

            if ($isOperations) {
                $factory = $factory->driver();
            }

            $factory->create([
                'department_id' => $department->id,
                'position_id' => $positions->skip(1)->random()?->id ?? $positions->first()?->id,
                'supervisor_id' => $supervisor?->id,
            ]);
        }

        // A couple of records in non-active states to exercise the filters.
        Employee::query()->inRandomOrder()->limit(3)->update(['status' => 'on_leave']);

        $this->command?->info('Seeded '.Employee::count().' employees.');
    }
}
