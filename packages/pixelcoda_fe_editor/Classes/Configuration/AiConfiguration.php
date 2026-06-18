<?php

declare(strict_types=1);

namespace PixelCoda\FeEditor\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final readonly class AiConfiguration
{
    public function __construct(private ExtensionConfiguration $extensionConfiguration) {}

    /**
     * @return array{enabled: bool, provider: string, apiKey: string, model: string, endpoint: string}
     */
    public function get(): array
    {
        $rawConfiguration = $this->extensionConfiguration->get('pixelcoda_fe_editor');
        /** @var array<string, mixed> $configuration */
        $configuration = is_array($rawConfiguration) ? $rawConfiguration : [];
        $ai = is_array($configuration['ai'] ?? null) ? $configuration['ai'] : [];
        $provider = strtolower($this->stringValue(getenv('AI_PROVIDER') ?: ($ai['provider'] ?? 'openai')));
        $providers = [
            'openai' => ['model' => 'gpt-4.1-mini', 'endpoint' => 'https://api.openai.com/v1/responses'],
            'anthropic' => ['model' => 'claude-3-5-haiku-latest', 'endpoint' => 'https://api.anthropic.com/v1/messages'],
            'openrouter' => ['model' => 'openai/gpt-4.1-mini', 'endpoint' => 'https://openrouter.ai/api/v1/chat/completions'],
            'mistral' => ['model' => 'mistral-small-latest', 'endpoint' => 'https://api.mistral.ai/v1/chat/completions'],
        ];
        if (!isset($providers[$provider])) {
            $provider = 'openai';
        }

        return [
            'enabled' => (bool) ($ai['enabled'] ?? true),
            'provider' => $provider,
            'apiKey' => $this->stringValue(getenv('OPENAI_API_KEY') ?: getenv('AI_API_KEY') ?: ($ai['apiKey'] ?? '')),
            'model' => $this->stringValue(getenv('OPENAI_MODEL') ?: getenv('AI_MODEL') ?: ($ai['model'] ?? '') ?: $providers[$provider]['model']),
            'endpoint' => $providers[$provider]['endpoint'],
        ];
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
