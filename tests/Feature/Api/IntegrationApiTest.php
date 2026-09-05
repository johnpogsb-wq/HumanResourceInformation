<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What Core 2 publishes to the rest of ISMERS, and what it refuses to.
 *
 * The interesting assertions are the refusals and the agreements. An
 * integration is only worth anything if the figure a consumer reads is the
 * same figure our own screen shows — so these check that the endpoints are
 * wired to the services behind those screens rather than to a second copy of
 * the rules — and if it hands over no more than the consumer needs.
 */
class IntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    /*
     * -----------------------------------------------------------------
     * Fleet & Transportation — /drivers
     * -----------------------------------------------------------------
     */

    public function test_fleet_gets_the_licence_facts_a_dispatcher_needs(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        Employee::factory()->create([
            'drivers_license_number' => 'N02-24-001292',
            'license_dl_codes' => 'B,C',
            'license_conditions' => '4',
            'license_expiry' => now()->addYear(),
            'status' => 'active',
        ]);

        $this->getJson('/api/v1/drivers')
            ->assertOk()
            ->assertJsonPath('data.0.licence.dl_codes.0.code', 'B')
            // Spelled out, not just coded: sending only "C" would make every
            // consumer keep its own copy of the LTO table.
            ->assertJsonPath('data.0.licence.dl_codes.1.label', 'Goods over 3500 kgs GVW')
            ->assertJsonPath('data.0.may_drive', true)
            // Condition 4 reaches scheduling — that driver cannot take a night
            // run, and nothing else in the payload says so.
            ->assertJsonPath('data.0.operational_restrictions.0', 'Daylight driving only');
    }

    public function test_a_lapsed_licence_says_the_driver_may_not_drive(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        Employee::factory()->create([
            'drivers_license_number' => 'N02-20-000001',
            'license_expiry' => now()->subMonth(),
            'status' => 'active',
        ]);

        $this->getJson('/api/v1/drivers')
            ->assertOk()
            ->assertJsonPath('data.0.licence.is_expired', true)
            // The single field a dispatch screen keys on. False means
            // assigning this driver would be unlawful, not merely untidy.
            ->assertJsonPath('data.0.may_drive', false);
    }

    public function test_drivers_never_carry_salary_or_government_numbers(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        Employee::factory()->create([
            'drivers_license_number' => 'N02-24-001292',
            'basic_salary' => 30000,
            'sss_number' => '34-1234567-8',
        ]);

        $body = $this->getJson('/api/v1/drivers')->assertOk()->json('data.0');

        /*
         * Fleet has no reason to hold these. An integration that hands over
         * more than the consumer needs is the failure noticed after a breach
         * rather than before one — and an admin token is exactly the case
         * where a lazier resource would have leaked them.
         */
        $this->assertArrayNotHasKey('basic_salary', $body);
        $this->assertArrayNotHasKey('sss_number', $body);
        $this->assertArrayNotHasKey('bank_account_number', $body);
    }

    public function test_fleet_can_ask_for_one_clients_drivers(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $client = Client::create(['code' => 'MFL', 'name' => 'Metro Fleet', 'is_active' => true]);

        Employee::factory()->create([
            'drivers_license_number' => 'N02-24-000001',
            'employment_category' => Employee::CATEGORY_EXTERNAL,
            'client_id' => $client->id,
        ]);
        Employee::factory()->create(['drivers_license_number' => 'N02-24-000002']);

        $this->getJson("/api/v1/drivers?client_id={$client->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /*
     * -----------------------------------------------------------------
     * Core 1 and Fleet — /deployment-readiness
     * -----------------------------------------------------------------
     */

    public function test_deployment_readiness_agrees_with_the_screen_it_comes_from(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        Employee::factory()->count(3)->create();

        $body = $this->getJson('/api/v1/deployment-readiness')->assertOk()->json();

        // The same service `/hr/deployment` renders, so the counts cannot
        // drift between the screen and the consumer.
        $this->assertSame(3, $body['meta']['total']);
        $this->assertSame(
            3,
            $body['meta']['ready'] + $body['meta']['warning'] + $body['meta']['blocked'],
        );
    }

    /*
     * -----------------------------------------------------------------
     * Financial Management — /payroll/runs
     * -----------------------------------------------------------------
     */

    public function test_finance_only_sees_runs_this_system_has_agreed_to(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->payrollRun(PayrollRun::STATUS_DRAFT);
        $approved = $this->payrollRun(PayrollRun::STATUS_APPROVED);

        /*
         * A draft is still being corrected. Finance disbursing against one
         * would be paying a figure this system has not agreed to — which is
         * why `scopeReportable()` is read rather than the condition rewritten.
         */
        $this->getJson('/api/v1/payroll/runs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $approved->id);
    }

    public function test_a_draft_run_refuses_to_produce_a_register(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $draft = $this->payrollRun(PayrollRun::STATUS_DRAFT);

        // 409, not 404: the run exists, it is just not disbursable yet, and
        // saying so is the difference between "retry later" and "wrong id".
        $this->getJson("/api/v1/payroll/runs/{$draft->id}/register")->assertStatus(409);
    }

    /*
     * -----------------------------------------------------------------
     * Core 3 — /loans
     * -----------------------------------------------------------------
     */

    public function test_core_three_can_post_a_loan_for_payroll_to_deduct(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $employee = Employee::factory()->create();

        $this->postJson('/api/v1/loans', [
            'reference_number' => 'C3-LOAN-0001',
            'employee_id' => $employee->id,
            'type' => 'sss',
            'principal_amount' => 24000,
            'monthly_amortization' => 2000,
            'start_date' => '2026-09-01',
        ])
            ->assertCreated()
            ->assertJsonPath('data.outstanding_balance', 24000)
            // Stated rather than left to be inferred: a balance read mid-cycle
            // is the balance *before* this period's deduction.
            ->assertJsonPath('data.amortised_on_payroll_approval', true);

        $this->assertDatabaseHas('employee_loans', [
            'reference_number' => 'C3-LOAN-0001',
            'status' => EmployeeLoan::STATUS_ACTIVE,
        ]);
    }

    public function test_a_resent_loan_does_not_deduct_twice(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $body = [
            'reference_number' => 'C3-LOAN-RETRY',
            'employee_id' => Employee::factory()->create()->id,
            'type' => 'company',
            'principal_amount' => 12000,
            'monthly_amortization' => 1000,
            'start_date' => '2026-09-01',
        ];

        $this->postJson('/api/v1/loans', $body)->assertCreated();

        // A timeout on their side is indistinguishable from a failure, so they
        // resend — and two rows for one loan is the employee paying it twice.
        $this->postJson('/api/v1/loans', $body)->assertOk();

        $this->assertDatabaseCount('employee_loans', 1);
    }

    public function test_an_amortisation_larger_than_the_loan_is_refused(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        // A keying error that would otherwise take one period to surface, on
        // somebody's pay.
        $this->postJson('/api/v1/loans', [
            'reference_number' => 'C3-LOAN-BAD',
            'employee_id' => Employee::factory()->create()->id,
            'type' => 'company',
            'principal_amount' => 5000,
            'monthly_amortization' => 9000,
            'start_date' => '2026-09-01',
        ])->assertStatus(422)->assertJsonValidationErrors('monthly_amortization');
    }

    /*
     * -----------------------------------------------------------------
     * Core 4 and BI — /analytics/workforce
     * -----------------------------------------------------------------
     */

    public function test_analytics_returns_shapes_and_never_people(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        Employee::factory()->count(4)->create();

        $body = $this->getJson('/api/v1/analytics/workforce')->assertOk()->json('data');

        $this->assertSame(4, $body['headcount']['total']);
        $this->assertArrayHasKey('attendance', $body);

        /*
         * A dashboard needs shapes, not people. An endpoint that hands over
         * the directory to draw a bar chart is the endpoint that will one day
         * be the way the directory left.
         */
        $this->assertStringNotContainsString(
            'employee_number',
            json_encode($body),
        );
    }

    /*
     * -----------------------------------------------------------------
     * The doors that are shut
     * -----------------------------------------------------------------
     */

    public function test_every_integration_endpoint_is_closed_to_anonymous_callers(): void
    {
        foreach ([
            '/api/v1/drivers',
            '/api/v1/deployment-readiness',
            '/api/v1/payroll/runs',
            '/api/v1/analytics/workforce',
        ] as $endpoint) {
            $this->getJson($endpoint)->assertUnauthorized();
        }

        $this->postJson('/api/v1/loans', [])->assertUnauthorized();
    }

    public function test_a_rank_and_file_token_cannot_read_payroll_or_post_loans(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]));

        // The token's role decides what a consuming team may see. Issue Core
        // 3's token from an HR account, not from anybody's.
        $this->getJson('/api/v1/payroll/runs')->assertForbidden();
        $this->postJson('/api/v1/loans', [])->assertForbidden();
    }

    /**
     * A payroll run in a given state.
     *
     * Built rather than faked because there is no factory for one, and there
     * should not be: a run is produced by `PayrollService::generate()` from a
     * period, and a factory would invite tests to invent runs that could never
     * exist. These two only need the status.
     */
    private function payrollRun(string $status): PayrollRun
    {
        static $sequence = 0;
        $sequence++;

        /*
         * A fresh fortnight each time. (start_date, end_date) is unique — two
         * runs over the same cut-off is not a thing payroll allows, and the
         * constraint says so before a second one can exist.
         */
        $start = now()->startOfYear()->addDays(($sequence - 1) * 15);

        $period = PayrollPeriod::create([
            'name' => "Period {$sequence}",
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(14)->toDateString(),
            'pay_date' => $start->copy()->addDays(19)->toDateString(),
            'frequency' => 'semi_monthly',
            'status' => PayrollPeriod::STATUS_DRAFT,
        ]);

        return PayrollRun::create([
            'payroll_period_id' => $period->id,
            'run_number' => sprintf('PR-2026-%04d', $sequence),
            'status' => $status,
            'employee_count' => 0,
            'total_gross' => 0,
            'total_deductions' => 0,
            'total_net' => 0,
        ]);
    }
}
