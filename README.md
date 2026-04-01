# OT Record Selector — TYPO3 Backend Form Element

A custom backend form element for TYPO3 that lets editors search and select records by name — **showing translated titles and field values in the editor's own language**, with relevance ranking, configurable info fields, preview images, and TYPO3-native card display.

[![TYPO3](https://img.shields.io/badge/TYPO3-13.4-orange.svg)](https://typo3.org/)
[![Packagist Version](https://img.shields.io/packagist/v/oliverthiele/ot-recordselector.svg)](https://packagist.org/packages/oliverthiele/ot-recordselector)
[![PHP](https://img.shields.io/packagist/dependency-v/oliverthiele/ot-recordselector/php.svg)](https://php.net/)
[![License](https://img.shields.io/packagist/l/oliverthiele/ot-recordselector.svg)](LICENSE)
[![Changelog](https://img.shields.io/badge/Changelog-CHANGELOG.md-blue.svg)](CHANGELOG.md)

---

## Why not just use `type=group` or `selectMultipleSideBySide`?

TYPO3 core offers several ways to link records. They share a common limitation: **results are sorted alphabetically with no relevance ranking**, and **records always appear in the default language** regardless of which language the editor is currently working in.

OT Record Selector takes a different approach:

- **Label-field ranking.** Results where the search term appears in the record title rank above results where it only appears in a secondary field. Consider an address database where searching for `"peter mill"` returns two results: "Peter Miller" (rank 0 — both words match in the name) and "Peter Cooper" (rank 1 — "peter" matches in the name, but "mill" only matches in his job title "Mill supervisor"). Without ranking, alphabetical order would mix these arbitrarily. With ranking, the better match always appears first.

- **Configurable info fields.** "Peter Miller" and "Peter Cooper" are both valid results — but the editor needs to pick the right one. Any TCA field can be shown as labeled metadata directly on the card: `City: London · Email: p.miller@example.com`. Labels are resolved from TCA, so editors see human-readable field names. In the example above, showing city and email makes it immediately clear which Peter is which.

- **Three-line info display.** The card and dropdown show up to three lines: (1) system info (UID, PID), (2) content fields in the editor's language, (3) content fields in the default language — only when they differ from line 2. This lets editors immediately see both the translated and the original value side by side for context.

- **Translated titles and field values in search and cards.** The element detects the backend user's preferred language and overlays the translated title and all configured `infoFields` — in both the AJAX search results and the selected card. A German editor always sees German content, regardless of which language the edited record belongs to. `type=group` and `selectMultipleSideBySide` always show the default-language title regardless of the editor's working language.

- **Cross-language search.** The AJAX search covers both default-language records and their translations so editors can search in their own language. A German editor searching for "Müller" will find a contact stored as "Mueller" via the German translation of the name field.

- **Preview images.** When a FAL image field is configured (`previewImage`), the element shows a 64×64 thumbnail instead of the TYPO3 record icon — useful for any domain where visual recognition matters (contacts with portrait photos, products with product images, etc.).

- **TYPO3-native appearance.** Selected records render as Bootstrap `.card` elements with the record icon or preview image, an edit link, and a remove button — indistinguishable from core backend UI.

- **Multi-word AND search.** Searching for `"peter mill"` returns only records that contain both words, across all configured search fields.

---

## Features

- **Language-aware display** — search results and selected cards show translated titles and field values based on the backend user's preferred language; always stores the default-language UID
- **Cross-language search** — AJAX search covers default-language records and their translations simultaneously
- **Label-first relevance ranking** — matches in the label field rank above matches in secondary fields
- **Three-line info display** — (1) UID/PID, (2) translated content fields, (3) default-language content fields (only when different)
- **Preview images** — configurable FAL field for 64×64 thumbnails; falls back to TYPO3 record icon
- **TYPO3-native card UI** — record icon or preview image, title, hidden badge, configurable info lines, edit link, remove button
- **Multi-word AND search** — each space-separated word must match; ORed across all search fields
- **Configurable search fields** — restrict AJAX search to specific indexed columns (`searchFields`); falls back to `ctrl.searchFields` from TCA, then to the label field
- **Configurable info fields** — show any TCA fields as labeled metadata (`uid`, `pid`, or any column name)
- **Result limit** — configurable per field (`maxResults`), hard cap at 200
- **Permission-aware** — respects TYPO3 backend user `tables_select` permissions
- **Hidden record indicator** — shows a `hidden` badge (yellow) when all checked versions are hidden, or a `partially hidden` badge (grey) when only one side is hidden
- **Accessibility** — ARIA `role=combobox`, `aria-expanded`, `aria-activedescendant`, keyboard navigation (↑ ↓ Enter Escape)
- **Debug mode** — shows `[tablename]` and `[fieldname]` next to the element label (mirrors TYPO3 core behavior)
- **Single- and multi-select** — `maxitems=1` hides the search after selection; `maxitems>1` keeps it visible and stores a comma-separated list of UIDs

---

## Requirements

| Requirement | Version |
|---|---|
| TYPO3 | 13.4+ |
| PHP | 8.3+ |

No additional dependencies. The element uses `@typo3/core/ajax/ajax-request.js` and `<typo3-backend-icon>` from TYPO3 core.

---

## Installation

```bash
composer require oliverthiele/ot-recordselector
```

Then run the TYPO3 setup:

```bash
vendor/bin/typo3 extension:setup -e ot_recordselector
# or via DDEV:
ddev typo3 extension:setup -e ot_recordselector
```

---

## Configuration

Register the form element in your TCA column configuration:

```php
'my_field' => [
    'label' => 'My Record',
    'config' => [
        'type' => 'user',
        'renderType' => 'otRecordSelector',
        'foreign_table' => 'tx_myext_domain_model_record',
        'minitems' => 0,
        'maxitems' => 1,
    ],
],
```

### All TCA options

| Option | Type | Default | Description |
|---|---|---|---|
| `foreign_table` | `string` | — | **Required.** Target table name (must exist in TCA) |
| `maxitems` | `int` | `1` | `1` = single select, hides search after selection; `>1` = multi-select, stores comma-separated UIDs |
| `minitems` | `int` | `0` | Minimum required selections (not yet validated client-side) |
| `infoFields` | `string` | `uid` | Comma-separated list of fields to show as labeled metadata. Use `uid` and `pid` as special keywords. |
| `searchFields` | `string` | — | Comma-separated DB columns to search in. Falls back to `ctrl.searchFields` from TCA, then to the label field. Only whitelisted TCA columns are accepted. |
| `maxResults` | `int` | `20` | Maximum number of AJAX search results. Hard cap: 200. |
| `previewImage` | `string` | — | FAL field on the foreign table whose first image is shown as a 64×64 thumbnail instead of the record icon. |
| `allowRootLevel` | `bool` | `false` | When `true`, non-admin editors can see records stored at `pid=0` (site root level). Admin users always have access regardless of this setting. |

> **Naming convention:** All options added by this extension follow lowerCamelCase (`infoFields`, `searchFields`, `maxResults`, `previewImage`, `allowRootLevel`), consistent with newer TYPO3 core TCA options like `renderType`. The older core options `minitems`, `maxitems`, and `foreign_table` keep their original spelling.

### Example: address record selector

```php
'contact_address' => [
    'label' => 'Contact',
    'config' => [
        'type' => 'user',
        'renderType' => 'otRecordSelector',
        'foreign_table' => 'tt_address',
        'minitems' => 0,
        'maxitems' => 1,
        'infoFields' => 'uid,city,email',
        'searchFields' => 'first_name,last_name,email,company,title',
        'maxResults' => 30,
        'previewImage' => 'image',
    ],
],
```

The selected card will show three lines:

1. `ID: 42 · PID: 5 · /contacts/`
2. `Stadt: London · E-Mail: p.mueller@example.com` *(editor's language)*
3. `City: London · Email: p.miller@example.com` *(default language, only when different)*

---

## Language Handling

The element stores the **default-language UID** (`sys_language_uid = 0`) of the selected record — consistent with how TYPO3 handles language overlays throughout the system.

Display is language-aware:

- The element reads the **backend user's preferred language** from `be_users.lang` (not the language of the record being edited)
- Both the selected card (server-rendered on page load) and the AJAX search results display the **translated title and all configured `infoFields`** in the editor's own language
- If no translation exists for a field, the default-language value is used as fallback
- When translated and default values differ, both are shown side by side (line 2 = translated, line 3 = default, italic)

### Cross-language search

The AJAX search always runs two queries:

1. Default-language records matching the search term
2. Translation records (in any language) matching the search term → their default-language parent records are returned

This means editors can search in any language regardless of their backend language setting. A German editor searching for "Müller" will find the contact even when the backend is set to English.

---

## Hidden Record Indicators

The element shows a badge on the record title to indicate visibility problems:

| Badge | Color | Meaning |
|---|---|---|
| `hidden` | yellow | The default-language record **and** the editor's language translation are both hidden — or no translation exists and the default record is hidden |
| `partially hidden` | grey | One side is hidden: either the default-language record is hidden but the translation is visible, or the translation is hidden but the default record is visible |
| *(none)* | — | All checked versions are visible |

### Scope of the check

The check covers exactly **two records**:

1. The **default-language record** — the one whose UID is stored in the field
2. The **translation in the editor's display language** — resolved via `BackendUtility::getRecordLocalization()`

Hidden states of other language versions (e.g. a French translation when a German editor is working) are intentionally not checked. TYPO3's language model is hierarchical: the default-language record is the anchor, and editors are responsible for the language versions they work in. Checking all translations would require one extra DB query per result row, and surfacing a badge about a language the editor cannot even see in this context would be more confusing than helpful.

If a complete multi-language visibility check is required for a project, the `resolvePreviewImageUrl` approach — fetching all translation records in a single query — could be extended to also collect all hidden flags.

---

## Security

The element enforces TYPO3 backend permissions at two levels:

**Table-level:** The backend user must have `tables_select` permission for the foreign table. Requests for unknown or inaccessible tables are rejected with HTTP 400.

**Page-level:** Before running any record queries, the endpoint determines which pages (PIDs) the backend user may read. It first collects the distinct PIDs that contain matching records, then checks each one:

1. `isInWebMount()` — fast in-memory check against the user's configured web mounts
2. `readPageAccess()` — full page-permission DB check, only for pages that pass step 1

Records on inaccessible pages are excluded from all queries, not filtered after the fact. This ensures the result limit (`maxResults`) is not consumed by records the editor cannot see.

Root-level records (`pid=0`) are restricted to admin users by default. Set `allowRootLevel=true` in TCA to allow non-admin access.

Security-relevant settings (`allowRootLevel`) are baked into the server-generated AJAX URL at render time and are never sent as client-controlled parameters.

---

## How the AJAX Endpoint Works

The element registers a backend AJAX route (`ajax_ot_recordselector_search`) that accepts the following query parameters:

| Parameter | Description |
|---|---|
| `table` | Target table name |
| `search` | Search string (minimum 2 characters; multiple words are ANDed) |
| `lang` | `sys_language_uid` of the record being edited (default: `0`) |
| `backendLang` | `sys_language_uid` of the backend user's preferred language (default: `0`) |
| `searchFields` | Comma-separated columns to search in (optional) |
| `infoFields` | Comma-separated fields to include in the result info lines (optional) |
| `maxResults` | Maximum number of results (optional, default: 20, hard cap: 200) |
| `returnUrl` | URL to return to after editing a record (optional) |
| `previewImageField` | FAL field name for preview thumbnail (optional) |

The endpoint returns a JSON array:

```json
[
  {
    "uid": 42,
    "title": "Peter Müller",
    "title_secondary": "Peter Miller",
    "hidden_status": null,
    "icon_identifier": "tt-address",
    "image_url": "/fileadmin/_processed_/portrait_64x64.jpg",
    "pid": 5,
    "page_path": "/contacts/",
    "edit_url": "/typo3/record/edit?...",
    "info_system": [
      { "label": "ID", "field": "uid", "value": "42" },
      { "label": "PID", "field": "pid", "value": "5" }
    ],
    "info_translated": [
      { "label": "Stadt", "field": "city", "value": "London" },
      { "label": "E-Mail", "field": "email", "value": "p.mueller@example.com" }
    ],
    "info_default": [
      { "label": "City", "field": "city", "value": "London" },
      { "label": "Email", "field": "email", "value": "p.miller@example.com" }
    ]
  }
]
```

- `title_secondary` contains the default-language title when it differs from the translated title (or `null`)
- `image_url` is `null` when no `previewImageField` is configured or no image is found
- `info_translated` is empty when the backend user's language is the default language
- `info_default` is empty when its values are identical to `info_translated`

Security: only columns listed in `$GLOBALS['TCA'][$table]['columns']` are accepted as search fields (whitelist approach). Access is checked against TYPO3 backend user permissions (`tables_select`).

---

## License

GPL-2.0-or-later — see [LICENSE](LICENSE)

## Author

Oliver Thiele — [oliver-thiele.de](https://www.oliver-thiele.de)