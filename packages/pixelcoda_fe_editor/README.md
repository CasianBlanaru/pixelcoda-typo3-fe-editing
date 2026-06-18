# Pixelcoda FE Editor: Frontend Editing for TYPO3

Accessible frontend editing for TYPO3 12 LTS, TYPO3 13 LTS and TYPO3 14. Edit content directly on the rendered website with inline text editing, drag-and-drop sorting, contextual FormEngine records, image editing, headless-ready markers and an optional AI writing assistant.

> Status: stable. Version 1.2.4 supports TYPO3 12 LTS, TYPO3 13 LTS and TYPO3 14.

![Pixelcoda TYPO3 frontend editing overview](Documentation/Images/editor-overview.png)

## Features

- Responsive rounded action rail for authenticated TYPO3 backend users, with accessible contextual tooltips.
- Inline editing for `tt_content.header`, `bodytext` and related text fields.
- **Draft State**: Unsaved changes are persisted in `localStorage` and restored after page reloads.
- **Optimistic UI**: Immediate feedback for movement and deletion actions, with automatic rollback on error.
- Manual save through TYPO3 `DataHandler` with clear "dirty" state indication.
- CSRF protection through TYPO3 backend `FormProtection`.
- Content creation through the `+` button.
- Content sorting with drag-and-drop plus explicit `Up` / `Down` controls for large content elements.
- Stable insertion indicator and column-scoped persistence while dragging content elements.
- Automatic rollback and accessible status feedback when a reordered column cannot be saved.
- Per-element action menu for contextual editing, hiding and confirmed deletion.
- Image edit shortcut that opens the TYPO3 contextual record editor in an accessible side canvas.
- Automatic frontend refresh after saving the contextual TYPO3 record editor.
- Optional AI integration with OpenAI, Anthropic Claude, OpenRouter or Mistral through a server-side TYPO3 Ajax endpoint.
- TYPO3 page cache clearing after save, create and reorder operations.

![Premium TYPO3 frontend element actions](Documentation/Images/element-actions.png)

## Lighthouse

![Lighthouse scores: 100 Performance, Accessibility, Best Practices and SEO](Documentation/Images/lighthouse-scores.png)

The reproducible local Lighthouse desktop audit reaches 100 in all four categories. The first visible LCP element renders immediately; GSAP and ScrollTrigger remain active on following content.

- Performance: `100`
- Accessibility: `100`
- Best Practices: `100`
- SEO: `100`
- FCP: `0.2 s`
- LCP: `0.8 s`
- TBT: `0 ms`
- CLS: `0`

## Requirements

- TYPO3 `^12.4 || ^13.4 || ^14.0`
- PHP supported by the active TYPO3 version
- `typo3/cms-rte-ckeditor`
- Backend user with `tt_content` modify permission
- Optional: `OPENAI_API_KEY` for AI rewriting

## Installation

Stable Composer installation after the first TYPO3 Extension Repository
publication:

```bash
composer require pixelcoda/fe-editor
vendor/bin/typo3 extension:setup
vendor/bin/typo3 cache:flush
```

Extension key: `pixelcoda_fe_editor`

For local development:

Composer path repository example:

```bash
ddev composer config repositories.fe-editor path ./packages/typo3_fe_editing/packages/pixelcoda_fe_editor
ddev composer require pixelcoda/fe-editor:@dev
ddev exec ./vendor/bin/typo3 extension:setup
ddev exec ./vendor/bin/typo3 cache:flush
```

For this project the root `composer.json` already uses the local path package:

```json
{
  "repositories": {
    "fe-editor": {
      "type": "path",
      "url": "./packages/typo3_fe_editing/packages/pixelcoda_fe_editor"
    }
  },
  "require": {
    "pixelcoda/fe-editor": "@dev"
  }
}
```

## How It Works

The extension registers a frontend PSR-15 middleware. If a backend user is logged in and has edit rights, the middleware injects:

- `Resources/Public/editor.css`
- `Resources/Public/editor.js`
- toolbar markup
- TYPO3 Ajax URLs
- CSRF token
- current page id
- metadata for editable `tt_content` records

Saving, creating, reordering and AI actions run through TYPO3 backend Ajax routes:

```text
POST /typo3/ajax/fe-editor/save
POST /typo3/ajax/fe-editor/ai
```

### Headless & Next.js Integration

Pixelcoda FE Editor provides a dedicated DataProcessor to inject visual editing metadata into your JSON output (e.g., when using `typo3-headless/headless`).

#### 1. Enable Metadata in TYPO3

The metadata layer is automatically registered for all content elements via TypoScript. You can control its behavior in the Extension Configuration:

- **Enable Metadata:** Toggle `headless.enabled`.
- **Sensitive Information:** Toggle `headless.exposeSensitive` to include/exclude PID, language, and workspace IDs.

#### 2. Metadata Schema

Each content element will include a `_pixelcoda` key:

```json
{
  "_pixelcoda": {
    "uid": 123,
    "ctype": "textmedia",
    "backendEditUrl": "https://typo3-inst.localhost/typo3/record/edit?...",
    "container": true,
    "containerType": "2col",
    "allowedColPos": [0, 1],
    "responsive": {
      "mobile": 1,
      "tablet": 2,
      "desktop": 4
    }
  }
}
```

#### 3. Frontend Implementation (Next.js)

To enable visual editing in your Next.js frontend, add the corresponding data attributes to your React components:

```jsx
<section
  data-pixelcoda-uid={content._pixelcoda?.uid}
  data-pixelcoda-ctype={content._pixelcoda?.ctype}
  data-pixelcoda-edit-url={content._pixelcoda?.backendEditUrl}
>
  {/* Your content */}
</section>
```

Include `EXT:pixelcoda_fe_editor/Resources/Public/editor.js` and `editor.css` in your frontend application to activate the editing overlays.

#### 4. Disabling in Production

It is highly recommended to disable the metadata layer in production if you don't need frontend editing there. You can do this in your sitepackage TypoScript:

```typoscript
[getTSFE().id > 0 && env("TYPO3_CONTEXT") == "Production"]
  tt_content.stdWrap.dataProcessing.159 >
[END]
```

Or globally via Extension Configuration:

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['pixelcoda_fe_editor']['headless']['enabled'] = 0;
```

### Known Limitations

- **Cross-Origin Requests:** If your Next.js frontend is on a different domain, ensure that your TYPO3 instance sends appropriate CORS headers.
- **Backend URL session:** The `backendEditUrl` requires an active TYPO3 backend session.
- **Container depth:** Deeply nested containers might require manual layout adjustments in the frontend.

### Upgrade Notes (v1.2.x -> v1.3.0)

- **Database Changes:** Run `./vendor/bin/typo3 extension:setup` to add the new responsive column fields.
- **TypoScript:** The `PixelCodaHeadlessDataProcessor` is now registered automatically. If you have custom DataProcessors on `stdWrap.dataProcessing.159`, please check for conflicts.

## Editing Text

Click `Edit`, select a marked headline or body text and edit directly in the frontend.

### Draft State

PixelCoda FE Editor implements a browser-side draft state. As you type, changes are automatically saved to `localStorage`. If you accidentally reload the page or navigate away, your changes will be restored when you return. The "Save" button in the toolbar will highlight (dirty state) and show the number of unsaved changes.

The extension detects editable fields in two ways:

- Native markers: `data-pc-field`, `data-table`, `data-uid`, `data-field`
- TYPO3 standard wrappers such as `id="c123"`
- Common existing wrappers using `data-content-element-uid` or `data-table="tt_content" data-uid`
- Fallback matching from current page `tt_content` records

This makes inline editing work on many existing classic TYPO3 frontends without changing Fluid templates. A fully headless frontend still needs a stable content-record identifier because rendered text alone cannot guarantee an unambiguous database mapping.

This follows the established TYPO3 frontend-editing convention of using standard content element `c-ids`, which are already emitted by Fluid Styled Content.

Frontend editing can be disabled for individual backend users or groups with UserTSconfig:

```typoscript
tx_pixelcodafeeditor.disabled = 1
```

Recommended Fluid markup:

```html
<h2 data-pc-field data-table="tt_content" data-uid="{data.uid}" data-field="header">
    {data.header}
</h2>

<div data-pc-field data-table="tt_content" data-uid="{data.uid}" data-field="bodytext">
    {data.bodytext -> f:format.raw()}
</div>
```

## Creating Content

The `+` button creates a new `tt_content` record on the current page. In the current demo layout it creates `CType=textpic`, because the frontend renders the demo cards from `textpic` records.

If your project renders all content types, adapt `createContentElement()` in `Resources/Public/editor.js` or add a proper content-type picker.

## Moving Content

In edit mode every content element gets a small control group:

- drag handle for mouse and pointer interaction
- arrow-up button to move one element up
- arrow-down button to move one element down

Dragging shows a stable insertion line and only changes the DOM when the element is dropped. The explicit arrow buttons remain the accessible keyboard alternative. Reordering updates `tt_content.sorting` through the save endpoint and is scoped to the affected content column. Stable TYPO3 wrapper UIDs keep elements with identical headings or text unambiguous. A visible live status confirms saving and success; failed requests restore the previous order.

Enable the `Pixelcoda FE Editor` site set (`pixelcoda/fe-editor`) for the included editable `pc_demo` content element. The set provides its rendering definition and new-content wizard configuration.

Each element also provides a compact action menu for opening the contextual editor, hiding the element, or deleting it after an accessible confirmation dialog. All writes use TYPO3 DataHandler and existing backend-user permissions.

## Editing Images And Records

The image button does not write FAL relations directly from the browser. It opens TYPO3's contextual record editor in a right-side canvas on the current page. Saving uses TYPO3's native `typo3:editform:saved` message, closes the canvas and reloads the frontend preview.

This keeps FAL references, permissions, workspaces and backend validation inside TYPO3 while avoiding a new browser tab.

Normal clicks stay in the contextual canvas. `Ctrl`/`Cmd`-click opens the full TYPO3 editor in a new tab as an explicit fallback for advanced FormEngine workflows.

The automatic wrapper detection, administrative UserTSconfig switch and contextual editor behavior are maintained by Pixelcoda and keep the existing inline editing, AI and headless marker APIs.

## AI Service

The AI button opens a side canvas and sends the active editable field to the server-side `AiController`. The browser never receives the API key.

Configure provider, model and API key in:

```text
Admin Tools > Settings > Extension Configuration > pixelcoda_fe_editor
```

Supported providers:

- OpenAI
- Anthropic Claude
- OpenRouter
- Mistral

Environment variables remain recommended for production and override backend configuration:

Configure in DDEV:

```bash
echo 'OPENAI_API_KEY=sk-...' >> .ddev/.env.web
echo 'OPENAI_MODEL=gpt-4.1-mini' >> .ddev/.env.web
ddev restart
ddev exec ./vendor/bin/typo3 cache:flush
```

Behavior:

- `header`: returns plain headline text
- `bodytext`: returns lightweight valid HTML
- select a marked text field before starting AI
- the toolbar keeps the selected-field state visible
- missing key: the toolbar shows `AI nicht konfiguriert: OPENAI_API_KEY fehlt`
- request and permission errors remain distinguishable from a missing field selection

The endpoint uses the OpenAI Responses API:

```text
POST https://api.openai.com/v1/responses
```

## Screenshots

| Premium element actions | Drag-and-drop sorting |
| --- | --- |
| ![Premium element actions](Documentation/Images/element-actions.png) | ![Content drag-and-drop](Documentation/Images/content-drag-drop.png) |

| Contextual TYPO3 record editor | AI writing assistant |
| --- | --- |
| ![Contextual TYPO3 record editor](Documentation/Images/contextual-editor.png) | ![AI writing assistant](Documentation/Images/ai-assistant.png) |

Pixelcoda FE Editor is developed and maintained by Casian Blanaru (Pixelcoda). Pixelcoda keeps its own inline editing, AI provider integration, automatic wrapper detection, headless marker API and visual design.

## Contact

- Email: [casianus@me.com](mailto:casianus@me.com)
- Website: [https://pixelcoda.de](https://pixelcoda.de)

## Troubleshooting

### Toolbar Is Not Visible

- Log into TYPO3 backend first.
- Check that the user is admin or has `tables_modify` for `tt_content`.
- Flush TYPO3 caches.

```bash
ddev exec ./vendor/bin/typo3 cache:flush
```

### AI Is Not Configured

Configure a provider and key in TYPO3 Extension Configuration or provide the key inside the DDEV web container.

```bash
ddev exec printenv OPENAI_API_KEY
```

The UI displays configuration problems as a user-facing status. A missing provider key is intentionally not logged as a browser console error.

## Accessibility

- Dialog semantics and labelled side canvas
- Keyboard focus trap while the canvas is open
- `Escape` closes the canvas
- Focus returns to the triggering control
- Visible `:focus-visible` states
- Live status messages
- Reduced-motion support
- Responsive desktop and mobile layouts
- Automatic light and dark color schemes

If empty, add it to `.ddev/.env.web` and restart DDEV.

### Changes Disappear After Reload

Check that the frontend renders real `tt_content` records and not hard-coded TypoScript or Fluid demo markup. The save endpoint writes to the database; the frontend must render the same records.

### Image Icon Is Too Large

The extension CSS forces toolbar icons to fixed dimensions with `!important`, because site CSS such as `.ce-gallery img { width: 100%; }` can otherwise affect overlay icons.

## Development Checks

Run the project-local check script:

```bash
packages/typo3_fe_editing/packages/pixelcoda_fe_editor/Build/check.sh
```

Manual checks:

```bash
find packages/typo3_fe_editing/packages/pixelcoda_fe_editor -name '*.php' -print0 | xargs -0 -n1 php -l
node --check packages/typo3_fe_editing/packages/pixelcoda_fe_editor/Resources/Public/editor.js
ddev exec ./vendor/bin/typo3 debug:backend:routes | rg 'ajax_fe_editor_(save|ai)'
ddev exec ./vendor/bin/phpstan analyse -c packages/typo3_fe_editing/packages/pixelcoda_fe_editor/Build/phpstan.neon --no-progress
```

Accessibility and Lighthouse checks:

```bash
npx --yes lighthouse https://typo3-inst.localhost/ \
  --chrome-flags="--headless --ignore-certificate-errors" \
  --only-categories=performance,accessibility,best-practices,seo \
  --output=html --output=json \
  --output-path=packages/typo3_fe_editing/packages/pixelcoda_fe_editor/Build/reports/lighthouse

npx --yes pa11y https://typo3-inst.localhost/ \
  --config packages/typo3_fe_editing/packages/pixelcoda_fe_editor/Build/pa11y.config.cjs \
  --reporter cli
```

## Release Checklist

1. Update README and screenshots.
2. Run `Build/check.sh`.
3. Run Lighthouse and pa11y.
4. Flush TYPO3 caches and verify frontend manually.
5. Commit with a release-oriented message.
6. Tag release if needed.
7. Push branch and tags.

## Security Notes

- All writes go through TYPO3 `DataHandler`.
- CSRF is validated via backend `FormProtection`.
- AI API keys must be server-side environment variables.
- Ajax routes require an authenticated backend user.
- Direct FAL writes from frontend are intentionally avoided.
