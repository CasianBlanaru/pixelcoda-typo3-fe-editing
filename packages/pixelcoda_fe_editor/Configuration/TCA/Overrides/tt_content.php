<?php
defined('TYPO3') || die();

call_user_func(function () {
    /** @var array{tt_content: array{columns: array{bodytext: array{config: array<string, mixed>}}}} $tca */
    $tca = &$GLOBALS['TCA'];
    // Enable RTE with our configuration for bodytext
    $tca['tt_content']['columns']['bodytext']['config']['enableRichtext'] = true;
    $tca['tt_content']['columns']['bodytext']['config']['richtextConfiguration'] =
        'default:EXT:pixelcoda_fe_editor/Configuration/RTE/Editor.yaml';

    // Add pixelcoda responsive fields
    $pixelcodaFields = [
        'tx_pixelcodafeeditor_mobile' => [
            'exclude' => true,
            'label' => 'Mobile Cols (1-12)',
            'config' => [
                'type' => 'number',
                'size' => 2,
                'default' => 12,
                'range' => ['lower' => 1, 'upper' => 12],
            ],
        ],
        'tx_pixelcodafeeditor_tablet' => [
            'exclude' => true,
            'label' => 'Tablet Cols (1-12)',
            'config' => [
                'type' => 'number',
                'size' => 2,
                'default' => 12,
                'range' => ['lower' => 1, 'upper' => 12],
            ],
        ],
        'tx_pixelcodafeeditor_desktop' => [
            'exclude' => true,
            'label' => 'Desktop Cols (1-12)',
            'config' => [
                'type' => 'number',
                'size' => 2,
                'default' => 12,
                'range' => ['lower' => 1, 'upper' => 12],
            ],
        ],
    ];

    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('tt_content', $pixelcodaFields);
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes('tt_content', '--palette--;Pixelcoda Responsive;pixelcoda_responsive', '', 'after:layout');

    $tca['tt_content']['palettes']['pixelcoda_responsive'] = [
        'showitem' => 'tx_pixelcodafeeditor_mobile, tx_pixelcodafeeditor_tablet, tx_pixelcodafeeditor_desktop',
    ];
});
