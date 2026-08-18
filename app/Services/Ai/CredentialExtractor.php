<?php

namespace App\Services\Ai;

use Anthropic\Messages\TextBlock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reads the expiry date and reference number off a photographed credential.
 *
 * The point is not convenience. HR types those expiry dates by hand today,
 * and CredentialExpiryScanner is only as good as what was typed: a licence
 * keyed in with the wrong year is a driver who stays green on the dashboard
 * after their licence has lapsed. This removes the keystroke that can be
 * wrong.
 *
 * It **suggests, never saves.** The extraction fills the form; a person
 * still reviews and submits it. A model reading a creased photo through
 * glare can be wrong, and the cost of a wrong expiry date is exactly what
 * the Credentials screen exists to prevent.
 */
class CredentialExtractor
{
    public function __construct(private readonly AnthropicGateway $gateway) {}

    public function available(): bool
    {
        return $this->gateway->configured();
    }

    /** Only documents that actually carry a printed expiry are worth scanning. */
    public function scannable(string $type): bool
    {
        return in_array($type, config('ai.scannable_types', []), true);
    }

    /**
     * @return array{
     *     ok: bool,
     *     fields: array<string, string|null>,
     *     confidence: float,
     *     needs_review: bool,
     *     notes: string|null
     * }
     */
    public function extract(UploadedFile $file, string $type): array
    {
        if (! $this->available()) {
            throw new AiUnavailableException('Document scanning is not enabled.');
        }

        $media = $this->mediaType($file);

        $response = $this->gateway->send(
            messages: [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $media,
                            'data' => base64_encode($file->get()),
                        ],
                    ],
                    ['type' => 'text', 'text' => $this->instruction($type)],
                ],
            ]],
            system: $this->system(),
        );

        return $this->parse($response->content);
    }

    private function system(): string
    {
        return <<<'PROMPT'
        You read Philippine employment documents — professional driver's
        licences, medical certificates, NBI and police clearances, government
        IDs, and training certificates — and return the fields printed on them.

        Return a single JSON object and nothing else. No prose, no code fence.

        {
          "title": string|null,        // what the document is, e.g. "Professional Driver's Licence"
          "reference_no": string|null, // licence/clearance/ID number exactly as printed
          "issued_at": string|null,    // YYYY-MM-DD
          "expires_at": string|null,   // YYYY-MM-DD
          "confidence": number,        // 0.0-1.0, your own confidence in expires_at
          "notes": string|null         // one short sentence if something is unreadable
        }

        Rules that matter more than filling every field:

        - Report only what you can actually read. A field you cannot make out
          is null. Never infer a date from context, never complete a partly
          visible one, and never guess a year.
        - Philippine licences print dates as MM/DD/YYYY. If the order is
          genuinely ambiguous, set the date to null, lower confidence, and say
          so in notes rather than picking one.
        - Confidence is about the expiry date specifically, since that is the
          field this system acts on. Glare, blur, a crease across the date, or
          a cropped edge should all lower it.
        PROMPT;
    }

    private function instruction(string $type): string
    {
        $label = str_replace('_', ' ', $type);

        return "This is meant to be a {$label}. Read the fields printed on it. "
            .'If it is clearly a different kind of document, still report what '
            .'you can read and say what it appears to be in notes.';
    }

    /**
     * @param  array<int, mixed>  $content
     * @return array<string, mixed>
     */
    private function parse(array $content): array
    {
        $text = '';

        foreach ($content as $block) {
            if ($block instanceof TextBlock) {
                $text .= $block->text;
            }
        }

        // Models sometimes wrap JSON in a fence despite being asked not to.
        // Pulling the outermost braces is cheaper than failing the scan.
        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $text = $matches[0];
        }

        try {
            $data = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $this->failure('The document could not be read.');
        }

        if (! is_array($data)) {
            return $this->failure('The document could not be read.');
        }

        $confidence = (float) ($data['confidence'] ?? 0);

        return [
            'ok' => true,
            'fields' => [
                'title' => $this->string($data['title'] ?? null),
                'reference_no' => $this->string($data['reference_no'] ?? null),
                'issued_at' => $this->date($data['issued_at'] ?? null),
                'expires_at' => $this->date($data['expires_at'] ?? null),
            ],
            'confidence' => round($confidence, 2),
            // Below the bar the fields still arrive, but the screen presents
            // them as something to check rather than something to accept.
            'needs_review' => $confidence < (float) config('ai.min_confidence'),
            'notes' => $this->string($data['notes'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function failure(string $note): array
    {
        return [
            'ok' => false,
            'fields' => ['title' => null, 'reference_no' => null, 'issued_at' => null, 'expires_at' => null],
            'confidence' => 0.0,
            'needs_review' => true,
            'notes' => $note,
        ];
    }

    private function string(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    /** A date the form can't use is worse than no date — drop it rather than pass it on. */
    private function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', trim($value))->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function mediaType(UploadedFile $file): string
    {
        return match (strtolower($file->getClientOriginalExtension())) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
