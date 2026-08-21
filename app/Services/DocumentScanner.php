<?php

namespace App\Services;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIStatusException;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Module 1 — reads a scanned 201-file document and proposes the fields.
 *
 * The scanner **never writes to the database.** It returns a suggestion; the
 * upload form shows it, HR corrects anything wrong, and the existing
 * StoreEmployeeDocumentRequest validates it on save exactly as it does a
 * hand-typed entry. Same shape as the rest of the system: PayrollReadiness
 * warns without blocking, salary bands flag without enforcing.
 *
 * Why it matters: `CredentialExpiryScanner` is only as good as the
 * `expires_at` someone typed. A licence keyed a year late is a driver the
 * system believes is legal to dispatch.
 */
class DocumentScanner
{
    /**
     * Kept low deliberately. The answer is a handful of short fields, and a
     * cap this size cannot truncate one — but it does stop a runaway
     * response from costing real money on a blurry photo.
     */
    private const MAX_TOKENS = 1024;

    public function __construct(private readonly ?Client $client = null) {}

    /** False when no API key is configured — the feature stays dark. */
    public function isEnabled(): bool
    {
        return filled(config('scanner.api_key'));
    }

    /** Whether this particular upload is worth spending a request on. */
    public function canScan(UploadedFile $file): bool
    {
        return $this->isEnabled()
            && in_array($file->getMimeType(), config('scanner.accepts', []), true)
            && $file->getSize() <= (int) config('scanner.max_bytes');
    }

    /**
     * @return array{
     *     type: string|null,
     *     title: string|null,
     *     document_number: string|null,
     *     issued_at: string|null,
     *     expires_at: string|null,
     *     name_on_document: string|null,
     *     name_matches: bool|null,
     *     confidence: string|null,
     *     note: string|null,
     * }|null  null when the scan could not run at all
     */
    public function scan(UploadedFile $file, ?Employee $employee = null): ?array
    {
        if (! $this->canScan($file)) {
            return null;
        }

        $raw = $this->read($file);

        return $raw === null ? null : $this->normalise($raw, $employee);
    }

    /**
     * The one method that talks to the API, kept alone and `protected` so the
     * rules around it — type validation, date parsing, the name check — can
     * be tested without a network call or an API key. The SDK's
     * MessagesService is `final`, so there is nothing to mock otherwise.
     *
     * @return array<string, mixed>|null null when the call failed
     */
    protected function read(UploadedFile $file): ?array
    {
        try {
            $message = $this->client()->messages->create(
                model: config('scanner.model'),
                maxTokens: self::MAX_TOKENS,
                system: $this->systemPrompt(),
                messages: [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'mediaType' => $file->getMimeType(),
                                'data' => base64_encode(file_get_contents($file->getRealPath())),
                            ],
                        ],
                        ['type' => 'text', 'text' => 'Read this document.'],
                    ],
                ]],
                outputConfig: ['format' => $this->schema()],
            );
        } catch (APIStatusException $exception) {
            // A failed scan must never block the upload — HR types the fields
            // and carries on, exactly as before the feature existed.
            Log::warning('Document scan failed', [
                'type' => $exception->type?->value,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        return $this->firstJson($message->content);
    }

    private function systemPrompt(): string
    {
        $types = collect(config('scanner.document_types'))
            ->map(fn (string $description, string $key) => "- {$key}: {$description}")
            ->implode("\n");

        return <<<PROMPT
        You read scanned Philippine HR documents for a 201 file and report what
        is printed on them. You are filling in a form a person will check.

        Classify the document as exactly one of these keys:
        {$types}

        Rules that matter:
        - Report only what is legible on the document. Never infer, complete, or
          invent a number or a date that is not printed there.
        - If a field is unreadable or absent, return null for it. A null is
          correct and useful; a guess is not.
        - Dates must be ISO (YYYY-MM-DD). Philippine documents commonly print
          "March 24, 1997" or "07/31/2025" — those are month/day/year.
        - NBI clearances print "VALID UNTIL" — that is the expiry.
        - A sample or specimen document (placeholder text like "JANE DOE",
          "YYYY/MM/DD", or a SAMPLE watermark) has no real data: set
          confidence to "low" and say so in the note.
        - confidence is "high" only when the document is clearly legible and
          you are reporting text you can actually read.
        PROMPT;
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'json_schema',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'type' => [
                        'type' => 'string',
                        'enum' => array_keys(config('scanner.document_types')),
                        'description' => 'Which document this is.',
                    ],
                    'title' => [
                        'type' => ['string', 'null'],
                        'description' => 'A short human label, e.g. "Non-Professional Driver\'s Licence".',
                    ],
                    'document_number' => [
                        'type' => ['string', 'null'],
                        'description' => 'Licence No., NBI ID No., PhilSys number, or certificate number.',
                    ],
                    'issued_at' => [
                        'type' => ['string', 'null'],
                        'description' => 'Date of issue in YYYY-MM-DD, or null.',
                    ],
                    'expires_at' => [
                        'type' => ['string', 'null'],
                        'description' => 'Expiry / "valid until" date in YYYY-MM-DD, or null.',
                    ],
                    'name_on_document' => [
                        'type' => ['string', 'null'],
                        'description' => 'The person named on the document, as printed.',
                    ],
                    'confidence' => [
                        'type' => 'string',
                        'enum' => ['high', 'medium', 'low'],
                    ],
                    'note' => [
                        'type' => ['string', 'null'],
                        'description' => 'One short sentence if something is worth flagging.',
                    ],
                ],
                'required' => [
                    'type', 'title', 'document_number', 'issued_at',
                    'expires_at', 'name_on_document', 'confidence', 'note',
                ],
                'additionalProperties' => false,
            ],
        ];
    }

    /** @param array<int, mixed> $content */
    private function firstJson(array $content): array
    {
        foreach ($content as $block) {
            if ($block->type === 'text') {
                // Always json_decode — never string-match a model's output.
                return json_decode($block->text, true) ?? [];
            }
        }

        return [];
    }

    /**
     * Everything the model returned is treated as untrusted input: the type is
     * checked against the real list, dates are re-parsed, and the name is
     * compared here rather than asked of the model.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalise(array $raw, ?Employee $employee): array
    {
        $type = $raw['type'] ?? null;

        return [
            'type' => in_array($type, EmployeeDocument::TYPES, true) ? $type : null,
            'title' => $this->text($raw['title'] ?? null),
            'document_number' => $this->text($raw['document_number'] ?? null),
            'issued_at' => $this->date($raw['issued_at'] ?? null),
            'expires_at' => $this->date($raw['expires_at'] ?? null),
            'name_on_document' => $this->text($raw['name_on_document'] ?? null),
            // Compared in PHP, not by the model: filing a document under the
            // wrong employee is a real mistake, and the check for it should
            // not itself depend on the thing being checked.
            'name_matches' => $this->nameMatches($raw['name_on_document'] ?? null, $employee),
            'confidence' => in_array($raw['confidence'] ?? null, ['high', 'medium', 'low'], true)
                ? $raw['confidence']
                : 'low',
            'note' => $this->text($raw['note'] ?? null),
        ];
    }

    private function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    /** Re-parsed rather than trusted: a malformed date becomes null, not a crash. */
    private function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Null when there is nothing to compare. Matches on surname plus first
     * name rather than the whole string — Philippine documents order names
     * inconsistently ("DOE, JANE" vs "Jane Doe") and carry middle names the
     * 201 file may not.
     */
    private function nameMatches(mixed $onDocument, ?Employee $employee): ?bool
    {
        if (! is_string($onDocument) || ! $employee) {
            return null;
        }

        $haystack = $this->simplify($onDocument);

        if ($haystack === '') {
            return null;
        }

        return str_contains($haystack, $this->simplify($employee->last_name))
            && str_contains($haystack, $this->simplify($employee->first_name));
    }

    /** Lower-cased letters only, so punctuation and ordering stop mattering. */
    private function simplify(?string $value): string
    {
        return preg_replace('/[^a-z]/', '', mb_strtolower((string) $value)) ?? '';
    }

    private function client(): Client
    {
        return $this->client ?? new Client(apiKey: config('scanner.api_key'));
    }
}
