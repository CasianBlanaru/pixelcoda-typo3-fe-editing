<?php

declare(strict_types=1);

namespace PixelCoda\FeEditor\Middleware;

use Doctrine\DBAL\ParameterType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class HeadlessMetadataMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        
        $settings = $this->getHeadlessSettings();
        if (!$settings['enabled']) {
            return $response;
        }

        $contentType = $response->getHeaderLine('Content-Type');
        if (!str_contains($contentType, 'application/json')) {
            return $response;
        }

        $body = (string)$response->getBody();
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return $response;
        }

        $context = GeneralUtility::makeInstance(Context::class);
        $isBeUserLoggedIn = $context->getPropertyFromAspect('backend.user', 'isLoggedIn', false);

        // Inject metadata recursively into the JSON structure
        $data = $this->processRecursive($data, $isBeUserLoggedIn);

        $newBody = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        // Create a new stream for the modified body
        $stream = new Stream('php://temp', 'rw');
        $stream->write($newBody);
        $stream->rewind();

        // Return the modified response without the old Content-Length (it will be recalculated)
        return $response->withBody($stream)->withoutHeader('Content-Length');
    }

    protected function getHeadlessSettings(): array
    {
        try {
            $extensionConfiguration = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Configuration\ExtensionConfiguration::class);
            $config = $extensionConfiguration->get('pixelcoda_fe_editor');
            return [
                'enabled' => (bool)($config['headless']['enabled'] ?? true),
                'exposeBackendEditUrl' => (bool)($config['headless']['exposeBackendEditUrl'] ?? true),
                'exposePid' => (bool)($config['headless']['exposePid'] ?? false),
            ];
        } catch (\Exception) {
            return [
                'enabled' => true,
                'exposeBackendEditUrl' => true,
                'exposePid' => false,
            ];
        }
    }

    protected function processRecursive(array $data, bool $isBeUserLoggedIn): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // If this is a content element (has id and type)
                if (isset($value['id'], $value['type']) && is_numeric($value['id'])) {
                    $data[$key] = $this->enrichElement($value, $isBeUserLoggedIn);
                } else {
                    $data[$key] = $this->processRecursive($value, $isBeUserLoggedIn);
                }
            }
        }
        return $data;
    }

    protected function enrichElement(array $element, bool $isBeUserLoggedIn): array
    {
        $uid = (int)($element['id'] ?? 0);
        if ($uid <= 0) {
            return $element;
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        $row = $queryBuilder
            ->select('pid', 'CType', 'tx_pixelcodafeeditor_mobile', 'tx_pixelcodafeeditor_tablet', 'tx_pixelcodafeeditor_desktop')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid)))
            ->executeQuery()
            ->fetchAssociative();

        if (!$row) {
            return $element;
        }

        $pixelcoda = [
            'uid' => $uid,
            'ctype' => $row['CType'] ?? $element['type'] ?? '',
            'container' => false,
            'responsive' => [
                'mobile' => (int)($row['tx_pixelcodafeeditor_mobile'] ?? 12),
                'tablet' => (int)($row['tx_pixelcodafeeditor_tablet'] ?? 12),
                'desktop' => (int)($row['tx_pixelcodafeeditor_desktop'] ?? 12),
            ],
        ];

        // Container support detection
        if (str_contains($pixelcoda['ctype'], 'container') || str_contains($pixelcoda['ctype'], 'b13-')) {
            $pixelcoda['container'] = true;
            $pixelcoda['containerType'] = $pixelcoda['ctype'];
        }

        $settings = $this->getHeadlessSettings();

        // Security: Expose pid either when explicitly allowed via exposePid, OR to logged-in backend editors / in Development context
        if ($settings['exposePid'] || $isBeUserLoggedIn || \TYPO3\CMS\Core\Core\Environment::getContext()->isDevelopment()) {
            $pixelcoda['pid'] = (int)$row['pid'];
        }

        // Security: Expose backendEditUrl only to logged-in backend editors or in Development context, and if explicitly enabled
        if ($settings['exposeBackendEditUrl'] && ($isBeUserLoggedIn || \TYPO3\CMS\Core\Core\Environment::getContext()->isDevelopment())) {
            $pixelcoda['backendEditUrl'] = sprintf(
                '/typo3/record/edit?edit[tt_content][%d]=edit&returnUrl=%%2F',
                $uid
            );
        }

        $element['_pixelcoda'] = $pixelcoda;
        if (!isset($element['content']) || !is_array($element['content'])) {
            $element['content'] = [];
        }
        $element['content']['_pixelcoda'] = $pixelcoda;

        // Recursively process nested elements (e.g. in grid columns)
        foreach ($element as $key => $val) {
            if ($key !== 'content' && $key !== '_pixelcoda' && is_array($val)) {
                $element[$key] = $this->processRecursive($val, $isBeUserLoggedIn);
            }
        }
        // Don't recurse into _pixelcoda itself
        foreach ($element['content'] as $k => $v) {
            if ($k !== '_pixelcoda' && is_array($v)) {
                $element['content'][$k] = $this->processRecursive($v, $isBeUserLoggedIn);
            }
        }

        return $element;
    }
}
