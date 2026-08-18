<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Messages\Message;

/**
 * The one place the app talks to Anthropic.
 *
 * Everything else — the credential scanner, the assistant — depends on this
 * rather than on the SDK directly, which is what lets the tests swap in a
 * fake and run without a key or a network. It also means the model name and
 * token ceiling are decided once, in config, instead of at each call site.
 */
class AnthropicGateway
{
    private ?Client $client = null;

    /**
     * Whether the AI features are usable at all.
     *
     * Both features check this and hide themselves when it is false — a
     * deployment with no key is a supported state, not a broken one.
     */
    public function configured(): bool
    {
        return filled(config('ai.key'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>|null  $tools
     */
    public function send(array $messages, string $system, ?array $tools = null): Message
    {
        return $this->client()->messages->create(
            maxTokens: (int) config('ai.max_tokens'),
            messages: $messages,
            model: (string) config('ai.model'),
            system: $system,
            tools: $tools,
        );
    }

    private function client(): Client
    {
        if (! $this->configured()) {
            throw new AiUnavailableException(
                'No Anthropic API key is configured. Set ANTHROPIC_API_KEY in .env.',
            );
        }

        return $this->client ??= new Client(apiKey: (string) config('ai.key'));
    }
}
