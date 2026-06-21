<?php
namespace PixelCoda\FeEditor\Middleware;

use PixelCoda\FeEditor\Configuration\AiConfiguration;
use PixelCoda\FeEditor\Utility\PermissionChecker;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Frontend\Page\PageInformation;

final class FrontendEditOverlay implements MiddlewareInterface
{
    public function __construct(
        private readonly FormProtectionFactory $formProtectionFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly AiConfiguration $aiConfiguration
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (!$this->isEditableFrontendRequest($request)) {
            return $response;
        }

        $contentType = $response->getHeaderLine('Content-Type');
        if ($contentType !== '' && !str_contains(strtolower($contentType), 'text/html')) {
            return $response;
        }

        $body = (string)$response->getBody();
        if (!str_contains(strtolower($body), '</body>')) {
            return $response;
        }

        $csrfToken = $this->formProtectionFactory
            ->createForType('backend')
            ->generateToken('pixelcoda-fe-editor', 'fe-editor-action');
        $ajaxUrl = (string)$this->uriBuilder->buildUriFromRoute('ajax_fe_editor_save');
        $aiUrl = (string)$this->uriBuilder->buildUriFromRoute('ajax_fe_editor_ai');
        $pageId = $this->getPageId($request);
        $records = $this->getEditableContentRecords($pageId);
        $body = str_ireplace('</body>', $this->buildInjectionCode($csrfToken, $ajaxUrl, $aiUrl, $pageId, $records) . '</body>', $body);

        $response->getBody()->rewind();
        $response->getBody()->write($body);

        return $response->withHeader('Content-Length', (string)strlen($body));
    }

    private function isEditableFrontendRequest(ServerRequestInterface $request): bool
    {
        if (strtoupper($request->getMethod()) !== 'GET') {
            return false;
        }

        return PermissionChecker::mayEditFrontend($this->getBackendUser());
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        if (isset($GLOBALS['BE_USER']) && $GLOBALS['BE_USER'] instanceof BackendUserAuthentication) {
            return $GLOBALS['BE_USER'];
        }

        return null;
    }

    /**
     * @param array<int, array<string, int|string>> $records
     */
    private function buildInjectionCode(string $csrfToken, string $ajaxUrl, string $aiUrl, int $pageId, array $records): string
    {
        $cssPath = htmlspecialchars($this->getAssetWebPath('EXT:pixelcoda_fe_editor/Resources/Public/editor.css'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $scriptPath = htmlspecialchars($this->getAssetWebPath('EXT:pixelcoda_fe_editor/Resources/Public/editor.js'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $editIconPath = $this->getAssetWebPath('EXT:pixelcoda_fe_editor/Resources/Public/Icons/edit.svg');
        $aiIconPath = $this->getAssetWebPath('EXT:pixelcoda_fe_editor/Resources/Public/Icons/ai.svg');
        $addIconPath = $this->getAssetWebPath('EXT:pixelcoda_fe_editor/Resources/Public/Icons/add.svg');
        $editIconHtmlPath = htmlspecialchars($editIconPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $aiIconHtmlPath = htmlspecialchars($aiIconPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $addIconHtmlPath = htmlspecialchars($addIconPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $encodedToken = json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $encodedAjaxUrl = json_encode($ajaxUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $encodedAiUrl = json_encode($aiUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $encodedPageId = json_encode($pageId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $encodedRecords = json_encode($records, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $aiConfiguration = $this->aiConfiguration->get();
        $encodedAiConfigured = json_encode(
            $aiConfiguration['enabled'] && $aiConfiguration['apiKey'] !== '',
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
        );
        $encodedAiProvider = json_encode($aiConfiguration['provider'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $encodedIcons = json_encode([
            'edit' => $editIconPath,
            'ai' => $aiIconPath,
            'add' => $addIconPath,
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        return <<<HTML
<link rel="stylesheet" href="{$cssPath}">
<div id="pc-fe-toolbar-root" class="pc-fe-toolbar" role="toolbar" aria-label="Frontend Editing">
  <div class="pc-fe-toolbar-actions">
    <button id="pc-edit-toggle" class="pc-fe-button" type="button" data-label="Frontend Editing aktivieren" aria-label="Frontend Editing aktivieren" aria-pressed="false"><img class="pc-fe-icon" src="{$editIconHtmlPath}" alt="" onerror="this.hidden=true"><span>Edit</span></button>
    <button id="pc-save" class="pc-fe-button pc-fe-save" type="button" data-label="Änderungen speichern" aria-label="Änderungen speichern" disabled><img class="pc-fe-icon" src="{$editIconHtmlPath}" alt="" onerror="this.hidden=true"><span>Save</span></button>
    <span class="pc-fe-toolbar-divider" aria-hidden="true"></span>
    <button id="pc-ai" class="pc-fe-button pc-fe-ai" type="button" data-label="AI-Schreibassistent öffnen" aria-label="AI-Schreibassistent öffnen"><img class="pc-fe-icon" src="{$aiIconHtmlPath}" alt="" onerror="this.hidden=true"><span>AI</span></button>
    <button id="pc-add-global" class="pc-fe-button pc-fe-icon-button" type="button" data-label="Neues Element hinzufügen" aria-label="Neues Element hinzufügen"><img class="pc-fe-icon" src="{$addIconHtmlPath}" alt="" onerror="this.hidden=true"></button>
  </div>
  <div class="pc-fe-toolbar-feedback">
    <span id="pc-fe-selection" class="pc-fe-selection">Editieren aktivieren</span>
    <span id="pc-fe-status" class="pc-fe-status" aria-live="polite" aria-atomic="true"></span>
  </div>
</div>
<div id="pc-fe-drawer-backdrop" class="pc-fe-drawer-backdrop" hidden></div>
<aside id="pc-fe-drawer" class="pc-fe-drawer" role="dialog" aria-modal="true" aria-labelledby="pc-fe-drawer-title" hidden>
  <header class="pc-fe-drawer-header">
    <div>
      <span class="pc-fe-drawer-kicker">Pixelcoda FE Editor</span>
      <h2 id="pc-fe-drawer-title" class="pc-fe-drawer-title">Bearbeiten</h2>
    </div>
    <button id="pc-fe-drawer-close" class="pc-fe-drawer-close" type="button" aria-label="Seitenleiste schliessen" title="Schliessen">×</button>
  </header>
  <div id="pc-fe-image-panel" class="pc-fe-drawer-panel" hidden>
    <p class="pc-fe-drawer-hint">Der TYPO3-Datensatz bleibt auf dieser Seite geöffnet. Nach dem Speichern wird die Vorschau automatisch aktualisiert.</p>
    <iframe id="pc-fe-record-frame" class="pc-fe-record-frame" title="TYPO3 Datensatz bearbeiten"></iframe>
  </div>
  <div id="pc-fe-ai-panel" class="pc-fe-drawer-panel pc-fe-ai-panel" hidden>
    <div id="pc-fe-ai-config" class="pc-fe-ai-config" role="status"></div>
    <p class="pc-fe-drawer-hint">Verbessert nur das aktuell ausgewählte Textfeld. Der Vorschlag wird vor dem Speichern im Inhalt angezeigt.</p>
    <fieldset class="pc-fe-ai-actions">
      <legend>AI-Aktion</legend>
      <button type="button" class="pc-fe-ai-action active" data-pc-ai-action="rewrite" aria-pressed="true">Verbessern</button>
      <button type="button" class="pc-fe-ai-action" data-pc-ai-action="shorten" aria-pressed="false">Kürzen</button>
      <button type="button" class="pc-fe-ai-action" data-pc-ai-action="expand" aria-pressed="false">Erweitern</button>
    </fieldset>
    <div class="pc-fe-ai-field">
      <span>Ausgewähltes Feld</span>
      <strong id="pc-fe-ai-field-name">Kein Feld ausgewählt</strong>
    </div>
    <button id="pc-fe-ai-run" class="pc-fe-drawer-primary" type="button">AI-Vorschlag erstellen</button>
  </div>
</aside>
<script>
window.TYPO3 = window.TYPO3 || { settings: {}, security: {} };
window.TYPO3.settings.ajaxUrls = window.TYPO3.settings.ajaxUrls || {};
window.TYPO3.settings.ajaxUrls['fe_editor_save'] = {$encodedAjaxUrl};
window.TYPO3.settings.ajaxUrls['fe_editor_ai'] = {$encodedAiUrl};
window.TYPO3.security.feEditorToken = {$encodedToken};
window.TYPO3.settings.feEditorPageId = {$encodedPageId};
window.TYPO3.settings.feEditorRecords = {$encodedRecords};
window.TYPO3.settings.feEditorIcons = {$encodedIcons};
window.TYPO3.settings.feEditorAiConfigured = {$encodedAiConfigured};
window.TYPO3.settings.feEditorAiProvider = {$encodedAiProvider};
window.TYPO3.settings.DateConfiguration = window.TYPO3.settings.DateConfiguration || {
  formats: { date: 'dd.MM.yyyy', time: 'HH:mm', datetime: 'dd.MM.yyyy HH:mm' }
};
</script>
<script src="{$scriptPath}" defer></script>
HTML;
    }

    private function getAssetWebPath(string $assetPath): string
    {
        $absolutePath = GeneralUtility::getFileAbsFileName($assetPath);
        $webPath = PathUtility::getAbsoluteWebPath($absolutePath);
        $modifiedAt = filemtime($absolutePath);

        return $modifiedAt === false ? $webPath : $webPath . '?v=' . $modifiedAt;
    }

    private function getPageId(ServerRequestInterface $request): int
    {
        $pageInformation = $request->getAttribute('frontend.page.information');
        if ($pageInformation instanceof PageInformation) {
            return $pageInformation->getId();
        }

        return 0;
    }

    /**
     * @return array<int, array<string, int|string>>
     */
    private function getEditableContentRecords(int $pageId): array
    {
        if ($pageId <= 0) {
            return [];
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $rows = $queryBuilder
            ->select('uid', 'pid', 'CType', 'header', 'bodytext', 'colPos', 'sorting')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId)),
                $queryBuilder->expr()->eq('colPos', $queryBuilder->createNamedParameter(0))
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();

        $records = [];
        foreach ($rows as $row) {
            $uid = $this->intValue($row['uid'] ?? 0);
            $records[] = [
                'uid' => $uid,
                'pid' => $this->intValue($row['pid'] ?? 0),
                'colPos' => $this->intValue($row['colPos'] ?? 0),
                'CType' => $this->stringValue($row['CType'] ?? ''),
                'header' => trim($this->stringValue($row['header'] ?? '')),
                'bodytextText' => trim(html_entity_decode(strip_tags($this->stringValue($row['bodytext'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
                'editUrl' => (string)$this->uriBuilder->buildUriFromRoute('record_edit_contextual', [
                    'edit' => [
                        'tt_content' => [
                            $uid => 'edit',
                        ],
                    ],
                    'returnUrl' => '/',
                ]),
            ];
        }

        return $records;
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }
}
