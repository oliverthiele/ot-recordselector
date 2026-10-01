# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Remove `ext_emconf.php`: TYPO3 14.2+ reads the extension metadata from
  `composer.json` in classic mode as well (#108345), so the version and
  `providesPackages` are declared there now

## [2.0.1] — 2026-09-30

### Changed

- Write out the extension key in `ext_emconf.php` instead of relying on the
  `$_EXTKEY` variable set by the extension manager

### Fixed

- Read the return URL of the edit link from the PSR-7 request instead of the
  deprecated `GeneralUtility::getIndpEnv()` (TYPO3 v14.3, removed in v15)

## [2.0.0] — 2026-07-31

### Changed

- **Breaking:** Drop TYPO3 v13 support, require TYPO3 `^14.3`
- **Breaking:** Raise the PHP minimum to `>=8.4`
- **Breaking:** Rename the label keys that used underscores to lowerCamelCase:
  `badge.no_access` → `badge.noAccess`, `error.unknown_table` →
  `error.unknownTable`, `error.access_denied` → `error.accessDenied` and
  `modal.remove_inaccessible.*` → `modal.removeInaccessible.*`. Only relevant
  for projects that override these labels
- Migrate the language files from XLIFF 1.2 to XLIFF 2.0
- Reference labels via translation domain mapping — `ot_recordselector.messages:`
  replaces the full `LLL:EXT:` paths

### Fixed

- The badges for hidden, partially hidden and inaccessible records were
  hardcoded in English in the JavaScript, so search results and newly selected
  records showed English labels even in a German backend, while the
  server-rendered card next to them was translated. The labels are now handed to
  the client via data attributes
- `partially hidden` was hardcoded in the PHP card renderer as well and had no
  label key at all. Added as `badge.partiallyHidden`
- The edit button's `title` attribute was hardcoded as `Edit record`. Added as
  `button.edit`
- Removed the hardcoded `version` field from `composer.json`. It conflicts with
  the Git tag as the version source and prevents the package from being aliased
  when it is consumed from a path repository
- `ext_emconf.php` declared no PHP constraint, and its TYPO3 constraint was
  capped at `13.4.99` while `composer.json` already allowed v14



### Added

- Permission-aware edit button — the edit link on selected cards is hidden when the backend user lacks `tables_modify` permission for the record's table
- "No access" badge — cards for records on pages the backend user cannot access display a `no access` badge (info/blue)
- Confirmation modal before removing inaccessible records — when a record is on a page the editor cannot access, a TYPO3-native warning modal warns that removing the record makes the selection unrestorable
- `allowRemoveInaccessible` TCA option — controls whether non-admin editors can remove inaccessible records; when `false`, the remove button is hidden entirely; defaults to `true` (show button with confirmation modal)
- PHPStan Level 9 compliance — upgraded from Level 8; all type-narrowing issues resolved

## [1.0.1] — 2026-04-02

### Fixed

- CSS class names renamed from BEM double-underscore (`ot-recordselector__*`) to single-hyphen (`ot-recordselector-*`) — consistent with Bootstrap and TYPO3 core conventions

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

[Unreleased]: https://github.com/oliverthiele/ot-recordselector/compare/2.0.1...HEAD
[2.0.1]: https://github.com/oliverthiele/ot-recordselector/releases/tag/2.0.1
[2.0.0]: https://github.com/oliverthiele/ot-recordselector/releases/tag/2.0.0
[1.1.0]: https://github.com/oliverthiele/ot-recordselector/releases/tag/1.1.0
[1.0.1]: https://github.com/oliverthiele/ot-recordselector/releases/tag/1.0.1
[1.0.0]: https://github.com/oliverthiele/ot-recordselector/releases/tag/1.0.0