# Changelog

## 1.3.0 - 2026-06-06

- Added TYPO3 Headless compatibility with a dedicated metadata layer (`_pixelcoda`).
- Added Next.js integration support via explicit `data-pixelcoda-*` attributes.
- Implemented `PixelCodaHeadlessDataProcessor` to inject editing metadata into JSON output.
- Added support for `b13/container` metadata (parent, allowed positions).
- Added native responsive column fields (mobile, tablet, desktop) to `tt_content`.
- Added custom backend route `record_edit_contextual` for deep-linking from decoupled frontends.
- Added feature flags to control metadata exposure and security.
- Improved `editor.js` to automatically detect headless markers.

## 1.2.4 - 2026-06-05

- Fixed drag-and-drop persistence for content elements with identical titles or text.
- Persisted ordering through TYPO3 DataHandler move commands instead of direct sorting values.
- Prefer stable TYPO3 content wrapper UIDs before fallback content matching.
- Added automatic rollback when reordering cannot be saved.
- Added clear accessible saving, success and failure feedback for reordering.
- Added the `pixelcoda/fe-editor` site set so `pc_demo` renders without manually including static TypoScript.
- Fixed the `pc_demo` TypoScript object copy and added deterministic content-element markup for frontend sorting.

## 1.2.3 - 2026-06-04

- Reworked the TER icon for clearer recognition at small listing sizes.
- Added TER author company, contact and supported PHP version metadata.
- Added TYPO3 documentation rendering configuration and TER resource links.

## 1.2.2 - 2026-06-04

- Added the root-level TER extension icon shown in TYPO3 Extension Repository listings.
- Made the automated TER publication workflow idempotent for existing versions.

## 1.2.1 - 2026-06-04

- Added validated TER packaging and an automated TYPO3 Extension Repository publishing workflow.
- Aligned extension license, author and discovery metadata for TER publication.
- Removed third-party attribution text from public Pixelcoda documentation.
- Updated repository package metadata for Packagist submission.
- Added Casian Blanaru (Pixelcoda) as repository package author.

## 1.2.0 - 2026-06-04

- Rebuilt the frontend toolbar as a compact rounded action rail for desktop and mobile.
- Added accessible contextual tooltips with synchronized ARIA labels and state-aware actions.
- Replaced the ambiguous AI icon with a dedicated Sparkles symbol.
- Added cache-busting asset URLs so updated toolbar assets appear immediately after deployment.
- Improved toolbar light/dark mode, focus states and responsive layout without changing editing APIs.

## 1.1.1 - 2026-06-04

- Improved GitHub, Composer and TYPO3 extension descriptions for discoverability.
- Added focused keywords for TYPO3 frontend editing, inline editing, headless CMS and accessibility.
- Replaced legacy and debug screenshots with consistent real-product workflow screenshots.
- Rebuilt the repository README as a concise stable-product overview.

## 1.1.0 - 2026-06-04

- Improved content-element drag-and-drop with a stable insertion indicator.
- Scoped order persistence to the affected TYPO3 content column.
- Replaced text movement controls with compact accessible arrow controls.
- Added a per-element premium action menu for contextual editing, hiding and confirmed deletion.
- Added content-element metadata to the action menu and dedicated contextual-editor titles.
- Added documentation screenshots and expanded Pixelcoda workflow documentation.

## 1.0.0 - 2026-06-04

- Stable TYPO3 12, 13 and 14 frontend editing release.
- Added automatic mapping for common existing TYPO3 content wrappers.
- Added accessible contextual record editing in a right-side canvas.
- Added automatic frontend refresh after contextual record saves.
- Added `Ctrl`/`Cmd`-click fallback to the full TYPO3 record editor.
- Added AI side canvas with clear field selection and error states.
- Added server-side OpenAI, Anthropic Claude, OpenRouter and Mistral configuration.
- Added responsive light/dark UI, reduced motion and keyboard focus management.
- Added PHPStan level-max configuration and expanded quality documentation.
