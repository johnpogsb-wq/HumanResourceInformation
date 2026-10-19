<?php

namespace Tests\Feature\Settings;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Pointing an existing login at a 201 file from Users & Access — which is
 * what lets an administrator's own account time in, enroll a face and have
 * records of its own. A login could only be linked when it was created.
 */
class LinkLoginToEmployeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_links_their_own_login_to_a_201_file(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = Employee::factory()->create(['user_id' => null]);

        $this->actingAs($admin)
            ->put("/settings/users/{$admin->id}/profile", $this->profile($admin, $employee->id))
            ->assertSessionHasNoErrors();

        $this->assertSame($admin->id, $employee->fresh()->user_id);
    }

    public function test_relinking_moves_the_login_and_frees_the_old_file(): void
    {
        $admin = User::factory()->admin()->create();
        $old = Employee::factory()->create(['user_id' => $admin->id]);
        $new = Employee::factory()->create(['user_id' => null]);

        $this->actingAs($admin)
            ->put("/settings/users/{$admin->id}/profile", $this->profile($admin, $new->id))
            ->assertSessionHasNoErrors();

        $this->assertNull($old->fresh()->user_id);
        $this->assertSame($admin->id, $new->fresh()->user_id);
    }

    public function test_a_file_another_login_holds_is_not_taken(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create();
        $theirs = Employee::factory()->create(['user_id' => $other->id]);

        $this->actingAs($admin)
            ->put("/settings/users/{$admin->id}/profile", $this->profile($admin, $theirs->id))
            ->assertSessionHasErrors('employee_id');

        $this->assertSame($other->id, $theirs->fresh()->user_id);
    }

    public function test_choosing_none_unlinks_and_leaving_the_field_out_changes_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = Employee::factory()->create(['user_id' => $admin->id]);

        $profile = $this->profile($admin, null);
        unset($profile['employee_id']);

        $this->actingAs($admin)->put("/settings/users/{$admin->id}/profile", $profile)->assertSessionHasNoErrors();
        $this->assertSame($admin->id, $employee->fresh()->user_id);

        $this->actingAs($admin)
            ->put("/settings/users/{$admin->id}/profile", $this->profile($admin, ''))
            ->assertSessionHasNoErrors();
        $this->assertNull($employee->fresh()->user_id);
    }

    public function test_the_kiosk_tells_an_unlinked_operator_why_they_cannot_clock_in(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/hr/timekeeping/clock')
            ->assertInertia(fn (Assert $page) => $page
                ->where('mode', 'kiosk')
                ->where('operator.has_file', false)
                ->where('can.linkAccounts', true));
    }

    private function profile(User $user, int|string|null $employeeId): array
    {
        return [
            'name' => $user->name,
            'username' => $user->username,
            'otp_email' => '',
            'employee_id' => $employeeId,
        ];
    }
}
