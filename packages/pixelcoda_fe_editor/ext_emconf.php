<?php
$EM_CONF[$_EXTKEY] = [
  'title' => 'PixelCoda FE Editor',
  'description' => 'Accessible TYPO3 frontend editing with inline editing, drag-and-drop, contextual records, headless support and optional AI.',
  'category' => 'fe',
  'state' => 'stable',
  'author' => 'Casian Blanaru (Pixelcoda)',
  'author_email' => 'casianus@me.com',
  'author_company' => 'Pixelcoda',
  'version' => '1.2.5',
  'icon' => 'EXT:pixelcoda_fe_editor/ext_icon.svg',
  'constraints' => [
    'depends' => [
      'php' => '8.1.0-8.5.99',
      'typo3' => '12.4.0-14.9.99',
    ],
    'suggests' => [
      'headless' => '',
    ],
  ],
];
