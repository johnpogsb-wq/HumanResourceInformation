<?php

namespace Tests\Feature\Settings;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    // --- Access -----------------------------------------------------------

    public function test_the_settings_root_lands_on_general(): void
    {
        $this->actingAs($this->admin())->get('/settings')->assertRedirect('/settings/general');
    }

    public function test_the_old_profile_url_now_points_at_security(): void
    {
        $this->actingAs($this->admin())->get('/profile')->assertRedirect('/settings/security');
    }

    public function test_company_settings_are_administrator_only(): void
    {
        $hr = User::factory()->hrStaff()->create();

        $this->actingAs($hr)->get('/settings/general')->assertForbidden();
        $this->actingAs($hr)->get('/settings/users')->assertForbidden();
        $this->actingAs($hr)->get('/settings/integrations')->assertForbidden();
    }

    public function test_everyone_reaches_their_own_appearance_and_security(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/settings/appearance')->assertOk();
        $this->actingAs($user)->get('/settings/security')->assertOk();
    }

    public function test_hr_staff_may_maintain_the_org_structure(): void
    {
        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/settings/organization')
            ->assertOk();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/settings/general')->assertRedirect('/login');
    }

    // --- General ----------------------------------------------------------

    public function test_company_settings_are_saved_and_read_back(): void
    {
        $this->actingAs($this->admin())
            ->put('/settings/general', [
                'company' => [
                    'name' => 'PrimePower Manpower Inc.',
                    'tin' => '123-456-789-000',
                ],
                'regional' => [
                    'timezone' => 'Asia/Manila',
                    'date_format' => 'Y-m-d',
                    'currency' => 'PHP',
                    'week_starts_on' => 1,
                ],
            ])
            ->assertRedirect();

        $this->assertSame('PrimePower Manpower Inc.', Setting::get('company.name'));
        $this->assertSame('Y-m-d', Setting::get('regional.date_format'));
    }

    public function test_unset_settings_fall_back_to_their_defaults(): void
    {
        $this->assertSame(
            Setting::DEFAULTS['company.name'],
            Setting::get('company.name'),
        );
        $this->assertSame(30, Setting::get('notifications.expiry_lead_days'));
    }

    public function test_a_company_name_is_required(): void
    {
        $this->actingAs($this->admin())
            ->put('/settings/general', [
                'company' => ['name' => ''],
                'regional' => [
                    'timezone' => 'Asia/Manila',
                    'date_format' => 'Y-m-d',
                    'currency' => 'PHP',
                    'week_starts_on' => 1,
                ],
            ])
            ->assertSessionHasErrors('company.name');
    }

    // --- Organization -----------------------------------------------------

    public function test_hr_can_create_a_department_and_position(): void
    {
        $hr = User::factory()->hrStaff()->create();

        $this->actingAs($hr)->post('/settings/organization/departments', [
            'code' => 'ops',
            'name' => 'Fleet Operations',
        ])->assertRedirect();

        // Codes are normalised to upper case.
        $department = Department::firstOrFail();
        $this->assertSame('OPS', $department->code);

        $this->actingAs($hr)->post('/settings/organization/positions', [
            'department_id' => $department->id,
            'code' => 'ops-drv',
            'title' => 'Professional Driver',
            'min_salary' => 18000,
            'max_salary' => 25000,
        ])->assertRedirect();

        $this->assertDatabaseHas('positions', ['code' => 'OPS-DRV']);
    }

    public function test_department_codes_must_be_unique(): void
    {
        Department::create(['code' => 'OPS', 'name' => 'Operations']);

        $this->actingAs(User::factory()->hrStaff()->create())
            ->post('/settings/organization/departments', ['code' => 'OPS', 'name' => 'Duplicate'])
            ->assertSessionHasErrors('code');
    }

    public function test_a_maximum_salary_cannot_sit_below_the_minimum(): void
    {
        $department = Department::create(['code' => 'OPS', 'name' => 'Operations']);

        $this->actingAs(User::factory()->hrStaff()->create())
            ->post('/settings/organization/positions', [
                'department_id' => $department->id,
                'code' => 'OPS-X',
                'title' => 'Backwards Band',
                'min_salary' => 30000,
                'max_salary' => 20000,
            ])
            ->assertSessionHasErrors('max_salary');
    }

    public function test_a_department_in_use_is_deactivated_rather_than_deleted(): void
    {
        $department = Department::create(['code' => 'OPS', 'name' => 'Operations']);
        Employee::factory()->create(['department_id' => $department->id]);

        $this->actingAs(User::factory()->hrStaff()->create())
            ->delete("/settings/organization/departments/{$department->id}")
            ->assertRedirect();

        $this->assertDatabaseHas('departments', ['id' => $department->id, 'is_active' => false]);
    }

    public function test_an_unused_position_is_deleted(): void
    {
        $department = Department::create(['code' => 'OPS', 'name' => 'Operations']);
        $position = Position::create([
            'department_id' => $department->id,
            'code' => 'OPS-X',
            'title' => 'Unused',
        ]);

        $this->actingAs(User::factory()->hrStaff()->create())
            ->delete("/settings/organization/positions/{$position->id}")
            ->assertRedirect();

        $this->assertDatabaseCount('positions', 0);
    }

    // --- Users & Access ---------------------------------------------------

    public function test_an_admin_can_create_an_account(): void
    {
        $this->actingAs($this->admin())
            ->post('/settings/users', [
                'name' => 'Nina Cruz',
                'email' => 'nina@primepower.test',
                'role' => User::ROLE_HR_STAFF,
            ])
            ->assertRedirect()
            // The temporary password is handed over once, in the flash message.
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'Temporary password'));

        $this->assertDatabaseHas('users', ['email' => 'nina@primepower.test', 'role' => 'hr_staff']);
    }

    public function test_creating_an_account_can_link_an_employee(): void
    {
        $employee = Employee::factory()->create(['user_id' => null, 'email' => 'juan@primepower.test']);

        $this->actingAs($this->admin())->post('/settings/users', [
            'employee_id' => $employee->id,
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@primepower.test',
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $this->assertNotNull($employee->fresh()->user_id);
    }

    public function test_an_admin_cannot_demote_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put("/settings/users/{$admin->id}/role", ['role' => User::ROLE_EMPLOYEE])
            ->assertSessionHas('error');

        $this->assertSame(User::ROLE_ADMIN, $admin->fresh()->role);
    }

    public function test_an_admin_cannot_deactivate_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post("/settings/users/{$admin->id}/toggle")
            ->assertSessionHas('error');

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_deactivating_an_account_revokes_its_api_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('device');

        $this->actingAs($this->admin())
            ->post("/settings/users/{$user->id}/toggle")
            ->assertRedirect();

        $this->assertFalse($user->fresh()->is_active);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // --- Security ---------------------------------------------------------

    public function test_a_user_can_change_their_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/settings/security/password', [
                'current_password' => 'password',
                'password' => 'a-much-longer-secret',
                'password_confirmation' => 'a-much-longer-secret',
            ])
            ->assertRedirect();

        $this->assertTrue(
            Hash::check('a-much-longer-secret', $user->fresh()->password),
        );
    }

    public function test_the_wrong_current_password_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->put('/settings/security/password', [
                'current_password' => 'not-my-password',
                'password' => 'a-much-longer-secret',
                'password_confirmation' => 'a-much-longer-secret',
            ])
            ->assertSessionHasErrors('current_password');
    }

    public function test_changing_the_email_clears_its_verification(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings/security/profile', [
            'name' => $user->name,
            'email' => 'moved@primepower.test',
        ]);

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_the_last_administrator_cannot_delete_their_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->delete('/settings/security/account', ['password' => 'password'])
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_the_audit_log_is_hidden_from_non_hr_roles(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/settings/security')
            ->assertInertia(fn (Assert $page) => $page
                ->where('canViewAudit', false)
                ->has('auditLog', 0),
            );
    }

    // --- Integrations -----------------------------------------------------

    public function test_an_admin_can_issue_and_revoke_an_api_token(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/settings/integrations/tokens', ['name' => 'Biometric device'])
            ->assertRedirect()
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'copy it now'));

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $token = $admin->tokens()->firstOrFail();

        $this->actingAs($admin)
            ->delete("/settings/integrations/tokens/{$token->id}")
            ->assertRedirect();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // --- Data -------------------------------------------------------------

    public function test_the_employee_export_streams_csv(): void
    {
        Employee::factory()->create(['first_name' => 'Elena', 'last_name' => 'Marquez']);

        $response = $this->actingAs($this->admin())
            ->get('/settings/data/export/employees')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString('Elena', $response->streamedContent());
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }
}
