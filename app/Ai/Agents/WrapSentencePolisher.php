<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

#[Provider(Lab::Anthropic)]
#[MaxTokens(16000)]
#[Timeout(20)]
class WrapSentencePolisher implements Agent, HasProviderOptions
{
    use Promptable;

    public function instructions(): string
    {
        return 'Write one sentence of at most 25 words in British English, addressing the listener as you. Use only the supplied facts, preserve the time phrase, and use no emoji or quotation marks.';
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        return $provider === Lab::Anthropic || $provider === Lab::Anthropic->value
            ? ['output_config' => ['effort' => 'low']]
            : [];
    }
}
