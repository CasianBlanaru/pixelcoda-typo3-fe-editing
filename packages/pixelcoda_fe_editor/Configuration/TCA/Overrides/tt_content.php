<?php
defined('TYPO3') || die();
call_user_func(function () {
    $GLOBALS['TCA']['tt_content']['columns']['tx_pixelcoda_responsive_mobile'] = [
        'label' => 'Responsive: Mobile Columns',
        'config' => [
            'type' => 'number',
            'size' => 2,
            'default' => 1,
        ],
    ];
    $GLOBALS['TCA']['tt_content']['columns']['tx_pixelcoda_responsive_tablet'] = [
        'label' => 'Responsive: Tablet Columns',
        'config' => [
            'type' => 'number',
            'size' => 2,
            'default' => 2,
        ],
    ];
    $GLOBALS['TCA']['tt_content']['columns']['tx_pixelcoda_responsive_desktop'] = [
        'label' => 'Responsive: Desktop Columns',
        'config' => [
            'type' => 'number',
            'size' => 2,
            'default' => 4,
        ],
    ];

    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
        'tt_content',
        '--palette--;Responsive;tx_pixelcoda_responsive',
        '',
        'after:layout'
    );

    $GLOBALS['TCA']['tt_content']['palettes']['tx_pixelcoda_responsive'] = [
        'showitem' => 'tx_pixelcoda_responsive_mobile, tx_pixelcoda_responsive_tablet, tx_pixelcoda_responsive_desktop',
    ];
});

call_user_func(function () {
    /** @var array{tt_content: array{columns: array{bodytext: array{config: array<string, mixed>}}}} $tca */
    $tca = &$GLOBALS['TCA'];
    // Enable RTE with our configuration for bodytext
    $tca['tt_content']['columns']['bodytext']['config']['enableRichtext'] = true;
    $tca['tt_content']['columns']['bodytext']['config']['richtextConfiguration'] =
        'default:EXT:pixelcoda_fe_editor/Configuration/RTE/Editor.yaml';
});
