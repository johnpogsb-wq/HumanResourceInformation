<?php

namespace App\Services\Ai;

use Anthropic\Messages\TextBlock;
use Anthropic\Messages\ToolUseBlock;
use App\Models\EmployeeDocument;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\CredentialExpiryScanner;
use App\Services\EmployeeService;
use App\Services\LeaveService;
use App\Services\TimekeepingService;
use Illuminate\Support\Carbon;

/**
 * Answers questions about the company's own data, in plain language.
 *
 * The security property this rests on: **the model never chooses whose data
 * it reads.** Every tool below runs against `scopedQuery($this->user)` — the
 * same narrowing the screens use — and none of them accepts an employee or a
 * scope as a parameter. So an employee asking "what is everyone's salary"
 * does not get a refusal that could be argued with; the query simply returns
 * their own row, because that is all the query can return. Role scoping is
 * enforced by the data layer, not by the prompt.
 *
 * Anything genuinely privileged (payroll totals) is additionally gated on
 * the same Policy the screens use, and reports that it declined rather than
 * returning an empty result that reads like "there is no payroll".
 */
class HrAssistant
{
    /** A loop this shallow is enough for "look something up, then answer". */
    private const MAX_TURNS = 6;

    private User $user;

    public function __construct(
        private readonly AnthropicGateway $gateway,
        private readonly EmployeeService $employees,
        private readonly TimekeepingService $timekeeping,
        private readonly LeaveService $leave,
        private readonly CredentialExpiryScanner $credentials,
    ) {}

    public function available(): bool
    {
        return $this->gateway->configured();
    }

    /**
     * @return array{answer: string, used: array<int, string>}
     */
    public function ask(User $user, string $question): array
    {
        if (! $this->available()) {
            throw new AiUnavailableException('The assistant is not enabled.');
        }

        $this->user = $user;

        $messages = [['role' => 'user', 'content' => $question]];
        $used = [];

        for ($turn = 0; $turn < self::MAX_TURNS; $turn++) {
            $response = $this->gateway->send(
                messages: $messages,
                system: $this->system(),
                tools: $this->tools(),
            );

            $messages[] = ['role' => 'assistant', 'content' => $response->content];

            $calls = array_filter(
                $response->content,
                fn ($block) => $block instanceof ToolUseBlock,
            );

            if ($calls === []) {
                return ['answer' => $this->text($response->content), 'used' => $used];
            }

            $results = [];

            foreach ($calls as $call) {
                $used[] = $call->name;
                $results[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $call->id,
                    'content' => json_encode(
                        $this->run($call->name, (array) $call->input),
                        JSON_UNESCAPED_UNICODE,
                    ),
                ];
            }

            // All results go back in one user message; splitting them teaches
            // the model to stop asking for several things at once.
            $messages[] = ['role' => 'user', 'content' => $results];
        }

        return [
            'answer' => 'I could not finish that lookup. Try asking for one thing at a time.',
            'used' => $used,
        ];
    }

    private function system(): string
    {
        $today = Carbon::today()->toDateString();

        return <<<PROMPT
        You answer questions about PrimePower Manpower's HR data for the person
        asking. Today is {$today}.

        Answer only from tool results. You have no knowledge of this company
        outside them, so if a tool returns nothing, say the records show nothing
        — never estimate, extrapolate, or fill a gap from what seems plausible.
        These are payroll and compliance figures; a number that sounds right and
        is wrong is worse here than "I don't have that".

        The tools already return only what this person is allowed to see, so
        answer with whatever comes back without second-guessing it. If a tool
        reports that it declined, say plainly that the figure is restricted —
        do not describe it as missing or as zero.

        Lead with the answer in one sentence. Add the figures that support it
        after. Keep it short: this is a search box, not a report. Peso amounts
        as ₱1,234.56, dates as 25 December 2026.
        PROMPT;
    }

    /**
     * Deliberately small, and deliberately without a scope parameter — see the
     * class docblock. Date ranges are the only thing the model gets to choose.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tools(): array
    {
        $range = [
            'from' => ['type' => 'string', 'description' => 'Start date, YYYY-MM-DD.'],
            'to' => ['type' => 'string', 'description' => 'End date, YYYY-MM-DD.'],
        ];

        return [
            [
                'name' => 'headcount',
                'description' => 'Headcount and its breakdown by employment status and department. '
                    .'Use for "how many employees", "how many drivers", "how many are regular".',
                'input_schema' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'expiring_credentials',
                'description' => 'Licences, medicals, and clearances that have lapsed or are inside '
                    .'their renewal window, with the employee names. Use for anything about expiring '
                    .'or expired documents, and for who cannot legally drive.',
                'input_schema' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'attendance_summary',
                'description' => 'Attendance totals for a date range: days present and absent, '
                    .'lateness, undertime, overtime, and night differential. Use for questions '
                    .'about attendance, lateness, absences, or overtime hours.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => $range,
                    'required' => ['from', 'to'],
                ],
            ],
            [
                'name' => 'leave_summary',
                'description' => 'Leave requests and their approval state. Use for questions about '
                    .'leave, who is on leave, or requests awaiting a decision.',
                'input_schema' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'payroll_totals',
                'description' => 'Totals for the most recent finalised payroll runs: gross, '
                    .'deductions, and net pay. Restricted to HR and administrators.',
                'input_schema' => ['type' => 'object', 'properties' => (object) []],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function run(string $tool, array $input): array
    {
        return match ($tool) {
            'headcount' => $this->employees->statistics(
                $this->employees->scopedQuery($this->user),
            ),

            'expiring_credentials' => $this->expiringCredentials(),

            'attendance_summary' => $this->timekeeping->summary(
                $this->timekeeping->scopedQuery($this->user)->filter([
                    'from' => $this->date($input['from'] ?? null, Carbon::now()->startOfMonth()),
                    'to' => $this->date($input['to'] ?? null, Carbon::now()->endOfMonth()),
                ]),
            ),

            'leave_summary' => $this->leave->summary(
                $this->leave->scopedQuery($this->user),
            ),

            'payroll_totals' => $this->payrollTotals(),

            default => ['error' => "No such tool: {$tool}"],
        };
    }

    /** @return array<string, mixed> */
    private function expiringCredentials(): array
    {
        $rows = $this->credentials->scan(
            EmployeeDocument::query()->whereIn(
                'employee_id',
                $this->employees->scopedQuery($this->user)->select('employees.id'),
            ),
        );

        return [
            'total' => $rows->count(),
            'expired' => $rows->where('status', CredentialExpiryScanner::STATUS_EXPIRED)->count(),
            'expiring_soon' => $rows->where('status', CredentialExpiryScanner::STATUS_EXPIRING)->count(),
            'stops_work' => $rows->where('blocking', true)->count(),
            'items' => $rows->take(20)->map(fn (array $row) => [
                'employee' => $row['employee_name'],
                'document' => $row['type_label'],
                'expires_at' => $row['expires_at'],
                'days_remaining' => $row['days_remaining'],
                'status' => $row['status'],
                'stops_work' => $row['blocking'],
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function payrollTotals(): array
    {
        // Gated on the same ability as the payroll screens. Reported as a
        // refusal rather than an empty result, so the answer can't come back
        // as "payroll is zero".
        if ($this->user->cannot('viewAny', PayrollRun::class)) {
            return ['declined' => 'Payroll totals are restricted to HR and administrators.'];
        }

        $runs = PayrollRun::with('period:id,name')
            ->whereIn('status', [PayrollRun::STATUS_APPROVED, PayrollRun::STATUS_PAID])
            ->latest('id')
            ->take(6)
            ->get();

        return [
            'runs' => $runs->map(fn (PayrollRun $run) => [
                'run_number' => $run->run_number,
                'period' => $run->period?->name,
                'status' => $run->status,
                'employees' => $run->employee_count,
                'gross' => (float) $run->total_gross,
                'deductions' => (float) $run->total_deductions,
                'net' => (float) $run->total_net,
            ])->values()->all(),
        ];
    }

    private function date(mixed $value, Carbon $fallback): string
    {
        if (! is_string($value) || trim($value) === '') {
            return $fallback->toDateString();
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return $fallback->toDateString();
        }
    }

    /** @param array<int, mixed> $content */
    private function text(array $content): string
    {
        $text = '';

        foreach ($content as $block) {
            if ($block instanceof TextBlock) {
                $text .= $block->text;
            }
        }

        return trim($text) !== '' ? trim($text) : 'I did not find an answer to that.';
    }
}
