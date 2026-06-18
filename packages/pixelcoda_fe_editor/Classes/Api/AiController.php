<?php

declare(strict_types=1);

namespace PixelCoda\FeEditor\Api;

use GuzzleHttp\Exception\GuzzleException;
use PixelCoda\FeEditor\Configuration\AiConfiguration;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class AiController
{
    private const MAX_INPUT_LENGTH = 6000;

    public function __construct(private readonly AiConfiguration $aiConfiguration) {}

    public function handle(ServerRequestInterface $request): JsonResponse
    {
        try {
            $beUser = $GLOBALS['BE_USER'] ?? null;
            if (!$beUser instanceof BackendUserAuthentication || empty($beUser->user)) {
                return new JsonResponse(['ok' => false, 'error' => 'auth_required'], 401);
            }
            if (!$beUser->isAdmin() && !$beUser->check('tables_modify', 'tt_content')) {
                return new JsonResponse(['ok' => false, 'error' => 'no_modify_permission'], 403);
            }

            $parsedBody = $request->getParsedBody();
            /** @var array<string, mixed> $parsed */
            $parsed = is_array($parsedBody) ? $parsedBody : [];
            $formToken = $this->stringValue($parsed['formToken'] ?? '');
            $formProtection = GeneralUtility::makeInstance(FormProtectionFactory::class)->createFromRequest($request);
            if (!$formProtection->validateToken($formToken, 'pixelcoda-fe-editor', 'fe-editor-action')) {
                return new JsonResponse(['ok' => false, 'error' => 'invalid_token'], 403);
            }

            $text = trim($this->stringValue($parsed['text'] ?? ''));
            $field = $this->stringValue($parsed['field'] ?? 'bodytext');
            $action = $this->stringValue($parsed['action'] ?? 'rewrite');
            $language = trim($this->stringValue($parsed['language'] ?? 'German'));
            if ($text === '') {
                return new JsonResponse(['ok' => false, 'error' => 'empty_text'], 400);
            }
            if (mb_strlen($text) > self::MAX_INPUT_LENGTH) {
                return new JsonResponse(['ok' => false, 'error' => 'text_too_long'], 400);
            }

            $configuration = $this->aiConfiguration->get();
            if (!$configuration['enabled'] || $configuration['apiKey'] === '') {
                return new JsonResponse([
                    'ok' => false,
                    'error' => 'openai_api_key_missing',
                    'message' => 'AI provider is disabled or its API key is not configured',
                ]);
            }

            $prompt = $this->buildPrompt($text, $field, $action, $language);
            [$headers, $payload] = $this->buildRequest($configuration, $prompt);

            try {
                $response = GeneralUtility::makeInstance(RequestFactory::class)->request(
                    $configuration['endpoint'],
                    'POST',
                    [
                        'headers' => $headers,
                        'body' => json_encode($payload, JSON_THROW_ON_ERROR),
                        'timeout' => 30,
                        'connect_timeout' => 8,
                    ]
                );
            } catch (GuzzleException $e) {
                return new JsonResponse([
                    'ok' => false,
                    'error' => 'openai_request_failed',
                    'message' => $e->getMessage(),
                ], 502);
            }

            $statusCode = $response->getStatusCode();
            $rawBody = (string) $response->getBody();
            $decoded = json_decode($rawBody, true);
            if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded)) {
                /** @var array<string, mixed>|null $decoded */
                return new JsonResponse([
                    'ok' => false,
                    'error' => 'openai_request_failed',
                    'status' => $statusCode,
                    'message' => is_array($decoded) ? $this->extractErrorMessage($decoded) : 'OpenAI request failed',
                ], 502);
            }

            /** @var array<string, mixed> $decoded */
            $result = trim($this->extractText($decoded, $configuration['provider']));
            if ($result === '') {
                return new JsonResponse(['ok' => false, 'error' => 'empty_ai_response'], 502);
            }

            return new JsonResponse([
                'ok' => true,
                'text' => $result,
                'model' => $configuration['model'],
                'provider' => $configuration['provider'],
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @param array{enabled: bool, provider: string, apiKey: string, model: string, endpoint: string} $configuration
     * @return array{0: array<string, string>, 1: array<string, mixed>}
     */
    private function buildRequest(array $configuration, string $prompt): array
    {
        $instruction = 'You are an expert TYPO3 content editor. Return only the improved content, no markdown fences, no explanations.';
        if ($configuration['provider'] === 'anthropic') {
            return [[
                'x-api-key' => $configuration['apiKey'],
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ], [
                'model' => $configuration['model'],
                'system' => $instruction,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'max_tokens' => 900,
            ]];
        }
        if (in_array($configuration['provider'], ['openrouter', 'mistral'], true)) {
            return [[
                'Authorization' => 'Bearer ' . $configuration['apiKey'],
                'Content-Type' => 'application/json',
            ], [
                'model' => $configuration['model'],
                'messages' => [
                    ['role' => 'system', 'content' => $instruction],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens' => 900,
            ]];
        }

        return [[
            'Authorization' => 'Bearer ' . $configuration['apiKey'],
            'Content-Type' => 'application/json',
        ], [
            'model' => $configuration['model'],
            'instructions' => $instruction,
            'input' => $prompt,
            'max_output_tokens' => 900,
        ]];
    }

    private function buildPrompt(string $text, string $field, string $action, string $language): string
    {
        $formatInstruction = $field === 'bodytext'
            ? 'Keep valid lightweight HTML paragraphs if the input contains HTML. Do not add scripts, styles, classes, or external links.'
            : 'Return plain headline text only.';

        $task = match ($action) {
            'shorten' => 'Shorten the content while preserving the core message.',
            'expand' => 'Expand the content slightly with concrete, useful wording.',
            'translate' => 'Translate the content to ' . ($language !== '' ? $language : 'German') . '.',
            default => 'Improve the content for clarity, premium tone, grammar, and conversion without changing facts.',
        };

        return $task . "\n" . $formatInstruction . "\n\nContent:\n" . $text;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractText(array $response, string $provider): string
    {
        if ($provider === 'anthropic') {
            $contentItems = is_array($response['content'] ?? null) ? $response['content'] : [];
            $chunks = [];
            foreach ($contentItems as $content) {
                if (is_array($content) && is_string($content['text'] ?? null)) {
                    $chunks[] = $content['text'];
                }
            }
            return implode("\n", $chunks);
        }
        if (in_array($provider, ['openrouter', 'mistral'], true)) {
            $choices = is_array($response['choices'] ?? null) ? $response['choices'] : [];
            $choice = is_array($choices[0] ?? null) ? $choices[0] : [];
            $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
            return is_string($message['content'] ?? null) ? $message['content'] : '';
        }
        if (isset($response['output_text']) && is_string($response['output_text'])) {
            return $response['output_text'];
        }

        $chunks = [];
        $output = is_array($response['output'] ?? null) ? $response['output'] : [];
        foreach ($output as $item) {
            if (!is_array($item)) {
                continue;
            }
            $contentItems = is_array($item['content'] ?? null) ? $item['content'] : [];
            foreach ($contentItems as $content) {
                if (is_array($content) && isset($content['text']) && is_string($content['text'])) {
                    $chunks[] = $content['text'];
                }
            }
        }

        return implode("\n", $chunks);
    }

    /** @param array<string, mixed> $response */
    private function extractErrorMessage(array $response): string
    {
        $error = is_array($response['error'] ?? null) ? $response['error'] : [];
        return is_string($error['message'] ?? null) ? $error['message'] : 'AI request failed';
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
