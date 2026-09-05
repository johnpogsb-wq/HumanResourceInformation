<?php

namespace Tests\Feature\HR;

use App\Services\DocumentScanner;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What each driver actually puts on the wire.
 *
 * Every other scanner test stubs `read()` so the rules around it can be
 * exercised without a network call — which is right, and is also precisely
 * why the Gemini driver shipped sending a request Google has no endpoint for.
 * It was posting an OpenAI-shaped body (`input`, `response_format`) to
 * `/v1beta/interactions`, and nothing failed, because the only driver anyone
 * runs locally is Ollama. The one that exists *for deployment* was the one
 * with no test.
 *
 * These assert the envelope, not the answer. A model's output is its own
 * business; the request shape is ours, and it is the part that silently rots.
 */
class ScannerDriverRequestTest extends TestCase
{
    /**
     * Gemini names the model in the URL and takes the image as a `part`
     * inside `contents`. Getting any of this wrong is a 400 in production and
     * a silent empty form for HR.
     */
    public function test_the_gemini_driver_posts_a_generate_content_request(): void
    {
        config([
            'scanner.driver' => 'gemini',
            'scanner.gemini.api_key' => 'test-key',
            'scanner.gemini.endpoint' => 'https://generativelanguage.googleapis.com/v1beta',
            'scanner.gemini.model' => 'gemini-3.7-flash',
        ]);

        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"type":"drivers_license"}']]],
                ]],
            ]),
        ]);

        $this->scan();

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            $this->assertSame(
                'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.7-flash:generateContent',
                $request->url(),
                'The model belongs in the URL, not the body.',
            );
            $this->assertSame('test-key', $request->header('x-goog-api-key')[0]);

            // The prompt is a first-class field, not a message.
            $this->assertArrayHasKey('systemInstruction', $body);
            $this->assertNotEmpty($body['systemInstruction']['parts'][0]['text']);

            // The image rides as inline_data beside the text.
            $parts = $body['contents'][0]['parts'];
            $this->assertSame('Read this document.', $parts[0]['text']);
            $this->assertArrayHasKey('inline_data', $parts[1]);
            $this->assertNotEmpty($parts[1]['inline_data']['data']);

            // Structured output, or the reply comes back as prose.
            $this->assertSame(
                'application/json',
                $body['generationConfig']['responseMimeType'],
            );
            /*
             * `responseJsonSchema`, and **not** `responseSchema` beside it.
             *
             * The two fields take different dialects: `responseSchema` is an
             * OpenAPI 3.0 subset whose `type` is a single value, so the
             * `["string", "null"]` unions this schema is full of are rejected
             * with "Proto field is not repeating" — and `additionalProperties`
             * is not a field it knows at all. Every request 400'd on that, and
             * because Gemini validates the body *before* the API key, a wrong
             * schema and a wrong key looked identical from outside. Only one of
             * the two fields may be sent.
             */
            $this->assertArrayHasKey('responseJsonSchema', $body['generationConfig']);
            $this->assertArrayNotHasKey('responseSchema', $body['generationConfig']);

            // The union types that forced the switch, still going out intact.
            $properties = $body['generationConfig']['responseJsonSchema']['properties'];
            $this->assertSame(['string', 'null'], $properties['title']['type']);

            // The shapes the old code sent, which Gemini has no endpoint for.
            $this->assertArrayNotHasKey('input', $body);
            $this->assertArrayNotHasKey('response_format', $body);

            return true;
        });
    }

    /** The reply is dug out of Gemini's envelope, not read off the top level. */
    public function test_the_gemini_driver_reads_the_answer_out_of_candidates(): void
    {
        config([
            'scanner.driver' => 'gemini',
            'scanner.gemini.api_key' => 'test-key',
        ]);

        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => '{"type":"clearance","title":"NBI Clearance"}',
                    ]]],
                ]],
            ]),
        ]);

        $fields = app(DocumentScanner::class)
            ->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertSame('clearance', $fields['type']);
    }

    /** Ollama's own shape, so fixing one driver cannot quietly break the other. */
    public function test_the_ollama_driver_posts_a_generate_request(): void
    {
        config([
            'scanner.driver' => 'ollama',
            'scanner.ollama.host' => 'http://127.0.0.1:11434',
            'scanner.ollama.model' => 'glm-ocr',
        ]);

        Http::fake([
            '*' => Http::response(['response' => '{"type":"drivers_license"}']),
        ]);

        $this->scan();

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            $this->assertSame('http://127.0.0.1:11434/api/generate', $request->url());
            $this->assertSame('glm-ocr', $body['model']);
            $this->assertNotEmpty($body['images'][0]);
            $this->assertFalse($body['stream'], 'A streamed reply cannot be json_decoded in one go.');
            $this->assertArrayHasKey('format', $body);

            return true;
        });
    }

    /** A driver that cannot be reached leaves the form empty; it never throws. */
    public function test_an_unreachable_driver_returns_null(): void
    {
        config(['scanner.driver' => 'gemini', 'scanner.gemini.api_key' => 'test-key']);

        Http::fake(['*' => Http::response('quota exceeded', 429)]);

        $this->assertNull(app(DocumentScanner::class)->scan(UploadedFile::fake()->image('x.jpg')));
    }

    private function scan(): void
    {
        app(DocumentScanner::class)->scan(UploadedFile::fake()->image('licence.jpg'));
    }
}
