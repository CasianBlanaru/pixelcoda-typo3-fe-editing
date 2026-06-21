<?php

declare(strict_types=1);

namespace PixelCoda\FeEditor\DataProcessing;

use TYPO3\CMS\Core\Attribute\AsAllowedCallable;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\DataProcessorInterface;

class PixelCodaHeadlessDataProcessor implements DataProcessorInterface
{
    public ?ContentObjectRenderer $cObj = null;

    public function process(
        ContentObjectRenderer $cObj,
        array $contentObjectConfiguration,
        array $processorConfiguration,
        array $processedData
    ): array {
        $settings = $this->getHeadlessSettings();
        if (!$settings['enabled']) {
            return $processedData;
        }

        $data = $processedData['data'] ?? [];
        $pixelcoda = $this->generatePixelcodaData($data);

        $targetVariableName = $processorConfiguration['as'] ?? '_pixelcoda';
        $processedData[$targetVariableName] = $pixelcoda;

        return $processedData;
    }

    #[AsAllowedCallable]
    public function processUserFunc(string $content, array $conf): string
    {
        $settings = $this->getHeadlessSettings();
        if (!$settings['enabled']) {
            return '';
        }

        $data = $this->cObj->data ?? [];
        $pixelcoda = $this->generatePixelcodaData($data);
        
        return json_encode($pixelcoda, JSON_THROW_ON_ERROR);
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

    protected function generatePixelcodaData(array $data): array
    {
        $context = GeneralUtility::makeInstance(Context::class);
        $isBeUserLoggedIn = $context->getPropertyFromAspect('backend.user', 'isLoggedIn', false);
        $settings = $this->getHeadlessSettings();

        $pixelcoda = [
            'uid' => (int)($data['uid'] ?? 0),
            'ctype' => $data['CType'] ?? '',
            'container' => false,
            'responsive' => [
                'mobile' => (int)($data['tx_pixelcodafeeditor_mobile'] ?? 12),
                'tablet' => (int)($data['tx_pixelcodafeeditor_tablet'] ?? 12),
                'desktop' => (int)($data['tx_pixelcodafeeditor_desktop'] ?? 12),
            ],
        ];

        if (isset($data['tx_container_parent']) || str_contains($pixelcoda['ctype'], 'container') || str_contains($pixelcoda['ctype'], 'b13-')) {
            $pixelcoda['container'] = true;
            $pixelcoda['containerType'] = $pixelcoda['ctype'];
            $pixelcoda['children'] = [];
        }

        // Security: Expose pid either when explicitly allowed via exposePid, OR to logged-in backend editors / in Development context
        if ($settings['exposePid'] || $isBeUserLoggedIn || \TYPO3\CMS\Core\Core\Environment::getContext()->isDevelopment()) {
            $pixelcoda['pid'] = (int)($data['pid'] ?? 0);
        }

        // Security: Expose backendEditUrl only to logged-in backend editors or in Development context, and if explicitly enabled
        if ($settings['exposeBackendEditUrl'] && ($isBeUserLoggedIn || \TYPO3\CMS\Core\Core\Environment::getContext()->isDevelopment())) {
            $pixelcoda['backendEditUrl'] = sprintf(
                '/typo3/record/edit?edit[tt_content][%d]=edit&returnUrl=%%2F',
                $pixelcoda['uid']
            );
        }

        return $pixelcoda;
    }
}

