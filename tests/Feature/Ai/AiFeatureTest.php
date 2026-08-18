<?php

namespace Tests\Feature\Ai;

use Anthropic\Messages\Message;
use Anthropic\Messages\TextBlock;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\Ai\AnthropicGateway;
use App\Services\Ai\HrAssistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The gateway is faked throughout — these tests never hold an API key and
 * never reach the network, so they run in CI exactly as they do here.
 */
class AiFeatureTest extends TestCase
{
    use RefreshDatabase;

    // ── Availability ─────────────────────────────────────────────────────

    public function test_the_features_are_off_when_no_key_is_configured(): void
    {
        config(['ai.key' => null]);

        $this->assertFalse(app(AnthropicGateway::class)->configured());

        $this->actingAs($this->hr())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('aiEnabled', false));
    }

    public function test_the_features_announce_themselves_when_a_key_is_present(): void
    {
        config(['ai.key' => 'sk-ant-test']);

        $this->actingAs($this->hr())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('aiEnabled', true));
    }

    // ── Assistant ────────────────────────────────────────────────────────

    public function test_the_assistant_answers_a_question(): void
    {
        $this->fakeReply('Three licences have lapsed.');

        $this->actingAs($this->hr())
            ->postJson('/hr/assistant', ['question' => 'How many licences have expired?'])
            ->assertOk()
            ->assertJsonPath('answer', 'Three licences have lapsed.');
    }

    public function test_the_assistant_requires_a_question(): void
    {
        config(['ai.key' => 'sk-ant-test']);

        $this->actingAs($this->hr())
            ->postJson('/hr/assistant', ['question' => ''])
            ->assertJsonValidationErrors('question');
    }

    public function test_the_assistant_is_unavailable_without_a_key(): void
    {
        config(['ai.key' => null]);

        $this->actingAs($this->hr())
            ->postJson('/hr/assistant', ['question' => 'How many employees?'])
            ->assertStatus(503);
    }

    public function test_guests_cannot_reach_the_assistant(): void
    {
        $this->postJson('/hr/assistant', ['question' => 'How many employees?'])
            ->assertUnauthorized();
    }

    /**
     * The security property the whole design rests on: the model never chooses
     * whose data it reads. An employee's lookup runs against their own scope,
     * so a question about everyone can only ever return their own row.
     */
    public function test_an_employees_lookup_only_ever_sees_their_own_records(): void
    {
        $user = User::factory()->create();
        $own = Employee::factory()->create(['user_id' => $user->id]);
        Employee::factory()->count(4)->create();

        // Inside the licence warning window, so the scanner actually flags it.
        $soon = now()->addDays(20)->toDateString();

        $this->document($own, $soon);
        foreach (Employee::whereKeyNot($own->id)->get() as $other) {
            $this->document($other, $soon);
        }

        $assistant = app(HrAssistant::class);
        $method = new \ReflectionMethod($assistant, 'run');
        (new \ReflectionProperty($assistant, 'user'))->setValue($assistant, $user);

        $result = $method->invoke($assistant, 'expiring_credentials', []);

        // Five employees hold a credential; this user may see exactly one.
        $this->assertSame(1, $result['total']);
        $this->assertSame($own->full_name, $result['items'][0]['employee']);
    }

    public function test_payroll_totals_are_declined_for_an_employee(): void
    {
        $user = User::factory()->create();
        Employee::factory()->create(['user_id' => $user->id]);

        $assistant = app(HrAssistant::class);
        $method = new \ReflectionMethod($assistant, 'run');
        (new \ReflectionProperty($assistant, 'user'))->setValue($assistant, $user);

        $result = $method->invoke($assistant, 'payroll_totals', []);

        // Declined, not empty — an empty result would read as "payroll is zero".
        $this->assertArrayHasKey('declined', $result);
        $this->assertArrayNotHasKey('runs', $result);
    }

    public function test_payroll_totals_are_returned_for_hr(): void
    {
        $assistant = app(HrAssistant::class);
        $method = new \ReflectionMethod($assistant, 'run');
        (new \ReflectionProperty($assistant, 'user'))->setValue($assistant, $this->hr());

        $result = $method->invoke($assistant, 'payroll_totals', []);

        $this->assertArrayHasKey('runs', $result);
        $this->assertArrayNotHasKey('declined', $result);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Binds a gateway that returns the given text without touching the network. */
    private function fakeReply(string $text): void
    {
        config(['ai.key' => 'sk-ant-test']);

        $message = Message::with(
            id: 'msg_test',
            container: null,
            content: [TextBlock::with(text: $text, citations: null)],
            model: 'claude-opus-5',
            stopDetails: null,
            stopReason: 'end_turn',
            stopSequence: null,
            usage: ['input_tokens' => 10, 'output_tokens' => 10],
        );

        $this->app->bind(AnthropicGateway::class, function () use ($message) {
            return new class($message) extends AnthropicGateway
            {
                public function __construct(private readonly Message $reply) {}

                public function configured(): bool
                {
                    return true;
                }

                public function send(array $messages, string $system, ?array $tools = null): Message
                {
                    return $this->reply;
                }
            };
        });
    }

    private function document(Employee $employee, string $expiresAt): EmployeeDocument
    {
        return EmployeeDocument::create([
            'employee_id' => $employee->id,
            'type' => 'drivers_license',
            'title' => "Driver's Licence",
            'file_path' => 'documents/licence.pdf',
            'file_name' => 'licence.pdf',
            'expires_at' => $expiresAt,
        ]);
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }
}
