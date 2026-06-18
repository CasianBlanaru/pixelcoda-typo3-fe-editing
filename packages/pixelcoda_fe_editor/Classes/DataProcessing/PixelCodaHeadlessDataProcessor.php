<?php

declare(strict_types=1);

namespace PixelCoda\FeEditor\DataProcessing;

use PixelCoda\FeEditor\Utility\PermissionChecker;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\DataProcessorInterface;

final class PixelCodaHeadlessDataProcessor implements DataProcessorInterface
{
    public function __construct(
        private readonly UriBuilder $uriBuilder,
        private readonly ExtensionConfiguration $extensionConfiguration
    ) {}

    public function process(
        ContentObjectRenderer $cObj,
        array $contentObjectConfiguration,
        array $processorConfiguration,
        array $processedData
    ): array {
        if (!$this->isMetadataEnabled()) {
            return $processedData;
        }

        if (!PermissionChecker::mayEditFrontend($this->getBackendUser())) {
            return $processedData;
        }

        $data = $cObj->data;
        $uid = (int) ($data['uid'] ?? 0);

        if ($uid <= 0) {
            return $processedData;
        }

        $exposeSensitive = $this->isSensitiveExposed();
        $metadata = [
            'uid' => $uid,
            'ctype' => (string) ($data['CType'] ?? ''),
            'backendEditUrl' => (string) $this->uriBuilder->buildUriFromRoute('record_edit_contextual', [
                'edit' => [
                    'tt_content' => [
                        $uid => 'edit',
                    ],
                ],
                'returnUrl' => '/',
            ], UriBuilder::ABSOLUTE_URL),
        ];

        if ($exposeSensitive) {
            $metadata['pid'] = (int) ($data['pid'] ?? 0);
            $metadata['language'] = (int) ($data['sys_language_uid'] ?? 0);
            $metadata['workspace'] = (int) ($data['t3ver_wsid'] ?? 0);
        }

        // Container support (b13/container)
        if (isset($data['tx_container_parent']) && (int) $data['tx_container_parent'] > 0) {
            $metadata['containerChild'] = true;
            $metadata['containerParent'] = (int) $data['tx_container_parent'];
        }

        if (str_starts_with($metadata['ctype'], 'container_') || (isset($data['tx_container_configuration']) && $data['tx_container_configuration'])) {
            $metadata['container'] = true;
            $metadata['containerType'] = str_replace('container_', '', $metadata['ctype']);
            // Simple mapping for common column layouts
            $metadata['allowedColPos'] = match ($metadata['containerType']) {
                '2col' => [0, 1],
                '3col' => [0, 1, 2],
                '4col' => [0, 1, 2, 3],
                default => [0],
            };
        }

        // Responsive columns
        $metadata['responsive'] = [
            'mobile' => (int) ($data['tx_pixelcoda_responsive_mobile'] ?? 1),
            'tablet' => (int) ($data['tx_pixelcoda_responsive_tablet'] ?? 2),
            'desktop' => (int) ($data['tx_pixelcoda_responsive_desktop'] ?? 4),
        ];

        $processedData['_pixelcoda'] = $metadata;

        return $processedData;
    }

    private function isMetadataEnabled(): bool
    {
        try {
            $config = $this->extensionConfiguration->get('pixelcoda_fe_editor');
            return (bool) ($config['headless']['enabled'] ?? true);
        } catch (\Exception) {
            return true;
        }
    }

    private function isSensitiveExposed(): bool
    {
        try {
            $config = $this->extensionConfiguration->get('pixelcoda_fe_editor');
            return (bool) ($config['headless']['exposeSensitive'] ?? false);
        } catch (\Exception) {
            return false;
        }
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
