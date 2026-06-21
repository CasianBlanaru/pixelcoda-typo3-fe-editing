<?php
declare(strict_types=1);

use PixelCoda\FeEditor\Middleware\FrontendEditOverlay;
use PixelCoda\FeEditor\Middleware\HeadlessMetadataMiddleware;

return [
    'frontend' => [
        'pixelcoda/fe-editor-overlay' => [
            'target' => FrontendEditOverlay::class,
            'after' => [
                'typo3/cms-frontend/prepare-tsfe-rendering',
            ],
            'before' => [
                'typo3/cms-frontend/content-length-headers',
            ],
        ],
        'pixelcoda/headless-metadata' => [
            'target' => HeadlessMetadataMiddleware::class,
            'after' => [
                'pixelcoda/fe-editor-overlay',
            ],
            'before' => [
                'typo3/cms-frontend/content-length-headers',
            ],
        ],
    ],
];
