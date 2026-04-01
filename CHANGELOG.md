# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] — 2026-04-01

### Added

- Initial release of the OT Record Selector backend form element for TYPO3 13.4+
- AJAX autocomplete search with debounce (250 ms) and minimum 2-character threshold
- Multi-word AND search — each space-separated word must appear across all configured search fields
- Label-first relevance ranking — matches in the label field rank above matches in secondary fields
- Language-aware display — search results and selected cards show translated titles and field values based on the backend user's preferred language; always stores the default-language UID
- Cross-language search — AJAX search covers default-language records and all translation records simultaneously, so editors can find records regardless of their backend language setting
- Three-line info display on cards and dropdown items:
  - Line 1: system info (UID, PID, page path)
  - Line 2: content fields in the editor's language (only when a translation is active)
  - Line 3: default-language content fields in italic (only when different from line 2)
- Preview images — configurable FAL field (`previewImage`) renders a 64×64 thumbnail instead of the TYPO3 record icon
- Hidden record indicators — `hidden` badge (yellow) when both default and editor-language versions are hidden; `partially hidden` badge (grey) when only one side is hidden
- Single-select mode (`maxitems=1`) — hides the search input after selection
- Multi-select mode (`maxitems>1`) — keeps search visible, stores comma-separated UIDs
- Configurable search fields (`searchFields`) — restricts AJAX search to specific indexed columns; falls back to `ctrl.searchFields` from TCA, then to the label field
- Configurable info fields (`infoFields`) — shows any TCA fields as labeled metadata on cards
- Configurable result limit (`maxResults`) — per-field setting, hard cap at 200
- Permission-aware — respects TYPO3 backend user `tables_select` permissions
- Accessibility — ARIA `role=combobox`, `aria-expanded`, `aria-activedescendant`, keyboard navigation (↑ ↓ Enter Escape)
- Debug mode — shows `[tablename]` and `[fieldname]` next to the element label (mirrors TYPO3 core behavior)
- TYPO3-native card UI — record icon or preview image, title, hidden badge, info lines, edit link, remove button
- Page-level permission check — before running record queries, accessible PIDs are determined via `isInWebMount()` (in-memory) followed by `readPageAccess()` (DB), so non-admin editors only see records on pages they are allowed to read
- `allowRootLevel` TCA option — controls whether non-admin editors can access records stored at `pid=0`; defaults to `false`; security-relevant value is baked into the server-generated AJAX URL, never sent as a client parameter
- PHPStan Level 8 compliance

[1.0.0]: https://github.com/oliverthiele/ot-recordselector/releases/tag/1.0.0