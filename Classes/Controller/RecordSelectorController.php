<?php

declare(strict_types=1);

namespace OliverThiele\OtRecordselector\Controller;

use Doctrine\DBAL\ParameterType;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * AJAX endpoint for the otRecordSelector form element.
 *
 * Returns a JSON array of records. Each entry contains:
 *   uid, title, title_secondary, hidden_status ('hidden'|'partial'|null),
 *   icon_identifier, image_url, pid, page_path, edit_url,
 *   info_system, info_translated, info_default (each a list of {label, field, value})
 *
 * The search always covers both default-language records and all translation records,
 * so editors can find records regardless of which language their backend is set to.
 * Display overlay uses the backend user's preferred language (backendLang).
 *
 * Query parameters:
 *   table              — target table name (required)
 *   search             — search string, space-separated words are combined with AND (required, min 2 chars)
 *   lang               — sys_language_uid of the record being edited (optional, default 0)
 *   backendLang        — sys_language_uid of the backend user's preferred language (optional, default 0)
 *   searchFields       — comma-separated DB fields to search in (optional, falls back to ctrl.searchFields, then label field)
 *   infoFields         — comma-separated fields to include in the result info lines (optional, default: uid)
 *   maxResults         — maximum number of results (optional, default 20, max 200)
 *   returnUrl          — URL to return to after editing (optional)
 *   previewImageField  — FAL field on the target table for the preview thumbnail (optional)
 */
final class RecordSelectorController
{
    private const DEFAULT_MAX_RESULTS = 20;
    private const HARD_MAX_RESULTS = 200;
    private const MIN_SEARCH_LENGTH = 2;

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly ConnectionPool $connectionPool,
        private readonly IconFactory $iconFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly FileRepository $fileRepository,
    ) {}

    public function searchAction(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $tableName = (string)($queryParams['table'] ?? '');
        $searchTerm = trim((string)($queryParams['search'] ?? ''));
        $languageUid = (int)($queryParams['lang'] ?? 0);
        $backendLanguageUid = (int)($queryParams['backendLang'] ?? 0);
        $returnUrl = (string)($queryParams['returnUrl'] ?? $request->getHeaderLine('Referer'));
        $searchFieldsParam = (string)($queryParams['searchFields'] ?? '');
        $infoFields = GeneralUtility::trimExplode(',', (string)($queryParams['infoFields'] ?? 'uid'), true);
        $maxResults = min(
            (int)($queryParams['maxResults'] ?? self::DEFAULT_MAX_RESULTS),
            self::HARD_MAX_RESULTS
        );

        $error = $this->validateRequest($tableName, $searchTerm);
        if ($error !== null) {
            return $this->jsonResponse(['error' => $error], 400);
        }

        $previewImageField = (string)($queryParams['previewImageField'] ?? '');
        $allowRootLevel = (bool)($queryParams['allowRootLevel'] ?? false);
        $searchFields = $this->resolveSearchFields($tableName, $searchFieldsParam);
        $records = $this->findRecords($tableName, $searchTerm, $languageUid, $backendLanguageUid, $returnUrl, $infoFields, $searchFields, $maxResults, $previewImageField, $allowRootLevel);

        return $this->jsonResponse($records);
    }

    /**
     * Validates access rights and input. Returns an error message or null on success.
     */
    private function validateRequest(string $tableName, string $searchTerm): ?string
    {
        if ($tableName === '' || !$this->tableExistsInTca($tableName)) {
            return 'Unknown table';
        }

        if (mb_strlen($searchTerm) < self::MIN_SEARCH_LENGTH) {
            return 'Search term too short';
        }

        if (!$this->getBackendUser()->check('tables_select', $tableName)) {
            return 'Access denied';
        }

        return null;
    }

    /**
     * Resolves which DB columns to search in.
     *
     * Priority:
     *   1. Explicitly passed search_fields param
     *   2. ctrl.searchFields from TCA
     *   3. ctrl.label field as fallback
     *
     * Only columns that actually exist in TCA are included (prevents SQL injection via unknown column names).
     *
     * @return list<string>
     */
    private function resolveSearchFields(string $tableName, string $searchFieldsParam): array
    {
        $labelField = $this->getTcaCtrlString($tableName, 'label', 'title');
        $candidates = $searchFieldsParam !== ''
            ? GeneralUtility::trimExplode(',', $searchFieldsParam, true)
            : GeneralUtility::trimExplode(',', $this->getTcaCtrlString($tableName, 'searchFields'), true);

        // Accept only columns that exist in TCA (whitelist approach — prevents SQL injection via unknown column names)
        $validColumns = array_keys($this->getTcaColumns($tableName));
        $resolved = array_values(array_filter(
            $candidates,
            static fn(string $field) => in_array($field, $validColumns, true)
        ));

        // Always fall back to the label field so the element stays functional
        return $resolved !== [] ? $resolved : [$labelField];
    }

    /**
     * Searches records using multi-word AND logic across all configured search fields.
     * Each space-separated word must appear in at least one of the search fields.
     *
     * Two queries are combined:
     * 1. Default-language records matching the search words directly.
     * 2. Default-language records whose translation in any language matches the search words.
     *    This lets editors search in their own language regardless of what the default language is:
     *    e.g. a German editor searching "Müller" finds a contact stored as "Mueller" via its German translation.
     *
     * Display overlay uses $backendLanguageUid (the backend user's preferred language):
     * - When > 0: translated title and field values are shown; title_secondary = default-language title if different
     * - When = 0: default-language values are shown directly; title_secondary = null
     *
     * @param list<string> $infoFields
     * @param list<string> $searchFields
     * @return list<array{uid: int, title: string, title_secondary: string|null, hidden_status: 'hidden'|'partial'|null, icon_identifier: string, image_url: string|null, pid: int, page_path: string, edit_url: string, info_system: list<array{label: string, field: string, value: string}>, info_translated: list<array{label: string, field: string, value: string}>, info_default: list<array{label: string, field: string, value: string}>}>
     */
    private function findRecords(
        string $tableName,
        string $searchTerm,
        int $languageUid,
        int $backendLanguageUid,
        string $returnUrl,
        array $infoFields,
        array $searchFields,
        int $maxResults,
        string $previewImageField = '',
        bool $allowRootLevel = false,
    ): array {
        $labelField = $this->getTcaCtrlString($tableName, 'label', 'title');
        $deleteField = $this->getTcaCtrlNullableString($tableName, 'delete');
        $languageField = $this->getTcaCtrlNullableString($tableName, 'languageField');
        $hiddenField = $this->getTcaHiddenField($tableName);
        $typeIconColumn = $this->getTcaCtrlNullableString($tableName, 'typeicon_column');
        $transOrigPointerField = $this->getTcaCtrlNullableString($tableName, 'transOrigPointerField');

        // Display language = always the backend user's preferred language.
        // The language of the record being edited ($languageUid) does not affect
        // what the editor sees — their backend preference takes full precedence.
        // When $backendLanguageUid = 0 (default/English backend), no overlay is applied
        // and the default-language values are shown directly.
        $displayLanguageUid = $backendLanguageUid;

        // Collect all columns needed for SELECT
        $tcaColumns = $this->getTcaColumns($tableName);
        $extraDbFields = array_filter(
            array_merge($infoFields, $searchFields),
            static fn(string $field) => $field !== 'uid'
                && $field !== 'pid'
                && isset($tcaColumns[$field])
        );

        $selectFields = array_values(array_unique(array_filter([
            'uid',
            'pid',
            $labelField,
            $hiddenField,
            $typeIconColumn,
            ...$extraDbFields,
        ])));

        $words = GeneralUtility::trimExplode(' ', $searchTerm, true);

        // Determine which pages (PIDs) the backend user may read before running the main queries.
        // This prevents non-admin editors from seeing records stored on pages outside their
        // web mount or without page-read permission.
        $accessiblePids = $this->resolveAccessiblePids(
            $tableName,
            $words,
            $deleteField,
            $searchFields,
            $allowRootLevel,
        );

        if ($accessiblePids === []) {
            return [];
        }

        // Query 1: default-language records matching the search words
        $defaultRows = $this->queryDefaultLanguageRows(
            $tableName,
            $words,
            $languageField,
            $deleteField,
            $selectFields,
            $searchFields,
            $accessiblePids,
        );

        // Query 2 (optional): default-language records found only via a translation match.
        // Searches across ALL languages so editors can find records regardless of their
        // backend language setting.
        // Display language (overlay) is determined separately and is not affected by this.
        $translationOnlyRows = [];
        if ($languageField !== null && $transOrigPointerField !== null) {
            $alreadyFoundUids = array_map(fn(mixed $value): int => is_numeric($value) ? (int)$value : 0, array_column($defaultRows, 'uid'));
            $translationOnlyRows = $this->findTranslationParentRows(
                $tableName,
                $words,
                $languageField,
                $transOrigPointerField,
                $deleteField,
                $selectFields,
                $searchFields,
                $alreadyFoundUids,
                $accessiblePids,
            );
        }

        $allRows = array_merge($defaultRows, $translationOnlyRows);

        // Split info fields into system (uid/pid, shown on line 1) and content (other fields).
        // Content fields are shown on lines 2 and 3 with translated and default-language values.
        $contentInfoFields = array_values(array_filter(
            $infoFields,
            static fn(string $field) => $field !== 'uid' && $field !== 'pid'
        ));
        $systemInfoFields = array_values(array_filter(
            $infoFields,
            static fn(string $field) => $field === 'uid' || $field === 'pid'
        ));

        // Apply language overlay to all rows using the unified display language.
        // Overlay happens before ranking so relevance scoring operates on the
        // language the editor is actually working in.
        // title_secondary = the default-language title when it differs from the
        // translated title — shown as context in the dropdown and on the card.
        // AJAX results are already filtered through resolveAccessiblePids(), so they are
        // always accessible. can_edit additionally requires tables_modify on the table.
        $canModifyTable = $this->getBackendUser()->check('tables_modify', $tableName);

        $overlaidRows = [];
        foreach ($allRows as $row) {
            $defaultTitle = $this->rowString($row, $labelField);
            $secondaryTitle = null;
            $defaultRow = $row; // Save before overlay for default-language info comparison
            $translationHidden = null; // null = no translation found for this display language

            if ($displayLanguageUid > 0) {
                $overlayedRow = BackendUtility::getRecordLocalization($tableName, $this->rowInt($row, 'uid'), $displayLanguageUid);
                if (is_array($overlayedRow) && !empty($overlayedRow[0]) && is_array($overlayedRow[0])) {
                    /** @var array<string, mixed> $translatedRow */
                    $translatedRow = $overlayedRow[0];

                    // Capture the translation's hidden state before any content overlay
                    if ($hiddenField !== null) {
                        $translationHidden = (bool)($translatedRow[$hiddenField] ?? false);
                    }

                    foreach ([$labelField, ...$infoFields] as $fieldName) {
                        // uid and pid must always come from the default-language record.
                        // The translation row has a different uid that must not overwrite
                        // the stored default-language uid.
                        if ($fieldName === 'uid' || $fieldName === 'pid') {
                            continue;
                        }
                        if (isset($translatedRow[$fieldName]) && $translatedRow[$fieldName] !== '') {
                            $row[$fieldName] = $translatedRow[$fieldName];
                        }
                    }
                    $translatedTitle = $this->rowString($row, $labelField);
                    if ($defaultTitle !== $translatedTitle) {
                        $secondaryTitle = $defaultTitle;
                    }
                }
            }

            // Compute hidden_status considering both the default-language and the translation record:
            //   'hidden'  — all relevant records are hidden (editor should be clearly warned)
            //   'partial' — only one side is hidden (e.g. default visible, translation hidden)
            //   null      — nothing is hidden
            $defaultHidden = $hiddenField !== null && (bool)($defaultRow[$hiddenField] ?? false);
            if ($translationHidden === null) {
                // No translation in play — status depends only on the default-language record
                $hiddenStatus = $defaultHidden ? 'hidden' : null;
            } elseif ($defaultHidden && $translationHidden) {
                $hiddenStatus = 'hidden';
            } elseif ($defaultHidden || $translationHidden) {
                $hiddenStatus = 'partial';
            } else {
                $hiddenStatus = null;
            }

            $row['_ot_secondary_title'] = $secondaryTitle;
            $row['_ot_default_row'] = $defaultRow;
            $row['_ot_hidden_status'] = $hiddenStatus;
            $overlaidRows[] = $row;
        }

        // Rank by translated/overlay label relevance, then alphabetically
        usort($overlaidRows, function (array $rowA, array $rowB) use ($labelField, $words): int {
            $rankA = $this->computeLabelRank($this->rowString($rowA, $labelField), $words);
            $rankB = $this->computeLabelRank($this->rowString($rowB, $labelField), $words);
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }
            return strcmp($this->rowString($rowA, $labelField), $this->rowString($rowB, $labelField));
        });

        $overlaidRows = array_slice($overlaidRows, 0, $maxResults);

        $result = [];
        foreach ($overlaidRows as $row) {
            $defaultRow = $row['_ot_default_row'];

            // Line 1: system fields (uid, pid) — always shown
            $infoSystem = $this->buildInfoItems($tableName, $row, $systemInfoFields);

            // Line 2: content fields in the editor's display language (only when a translation is active)
            $infoTranslated = $displayLanguageUid > 0 && $contentInfoFields !== []
                ? $this->buildInfoItems($tableName, $row, $contentInfoFields)
                : [];

            // Line 3: content fields in the default language.
            // When a translation is active: only shown when values differ from line 2.
            // When no translation is active: shown as the sole content info line.
            $infoDefaultItems = $contentInfoFields !== []
                ? $this->buildInfoItems($tableName, $defaultRow, $contentInfoFields)
                : [];
            $infoDefault = $infoTranslated !== [] && !$this->infoValuesDiffer($infoTranslated, $infoDefaultItems)
                ? []
                : $infoDefaultItems;

            $uid = $this->rowInt($row, 'uid');
            $pid = $this->rowInt($row, 'pid');
            $result[] = [
                'uid' => $uid,
                'title' => $this->rowString($row, $labelField),
                'title_secondary' => $row['_ot_secondary_title'],
                'hidden_status' => $row['_ot_hidden_status'],
                'icon_identifier' => $this->resolveIconIdentifier($tableName, $row),
                'pid' => $pid,
                'page_path' => $this->resolvePagePath($pid),
                'edit_url' => $canModifyTable ? $this->buildEditUrl($tableName, $uid, $returnUrl) : '',
                'is_accessible' => true,
                'can_edit' => $canModifyTable,
                'info_system' => $infoSystem,
                'info_translated' => $infoTranslated,
                'info_default' => $infoDefault,
                'image_url' => $previewImageField !== ''
                    ? $this->resolvePreviewImageUrl($tableName, $uid, $previewImageField)
                    : null,
            ];
        }

        return $result;
    }

    /**
     * Determines which page PIDs the backend user may access among those that contain
     * records matching the search words.
     *
     * Strategy:
     *   1. SELECT DISTINCT pid with the word conditions (no language filter — translations
     *      live on the same page as their default-language records).
     *   2. For pid = 0 (root level): allow only when the user is admin or $allowRootLevel is true.
     *   3. For all other pids: fast in-memory check via isInWebMount(), then a full
     *      readPageAccess() DB check only for pages that pass the mount check.
     *
     * No result caching — permission checks must always reflect the current state.
     *
     * @param list<string> $words
     * @param list<string> $searchFields
     * @return list<int>
     */
    private function resolveAccessiblePids(
        string $tableName,
        array $words,
        ?string $deleteField,
        array $searchFields,
        bool $allowRootLevel,
    ): array {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->selectLiteral('DISTINCT pid')->from($tableName);

        foreach ($words as $word) {
            $wordConditions = [];
            foreach ($searchFields as $searchField) {
                $wordConditions[] = $queryBuilder->expr()->like(
                    $searchField,
                    $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($word) . '%')
                );
            }
            $queryBuilder->andWhere($queryBuilder->expr()->or(...$wordConditions));
        }

        if ($deleteField !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    $deleteField,
                    $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)
                )
            );
        }

        $candidatePids = array_map(
            fn(mixed $value): int => is_numeric($value) ? (int)$value : 0,
            array_column($queryBuilder->executeQuery()->fetchAllAssociative(), 'pid')
        );

        $backendUser = $this->getBackendUser();
        $accessiblePids = [];

        foreach ($candidatePids as $pid) {
            if ($pid === 0) {
                if ($backendUser->isAdmin() || $allowRootLevel) {
                    $accessiblePids[] = 0;
                }
                continue;
            }

            // Fast in-memory check: is the page within the user's web mount?
            if (!$backendUser->isInWebMount($pid)) {
                continue;
            }

            // Full permission check: does the user have page-read permission?
            $pageRecord = BackendUtility::readPageAccess(
                $pid,
                $backendUser->getPagePermsClause(Permission::PAGE_SHOW)
            );
            if ($pageRecord !== false) {
                $accessiblePids[] = $pid;
            }
        }

        return $accessiblePids;
    }

    /**
     * Queries default-language (sys_language_uid = 0) records matching all search words
     * with multi-word AND logic across the configured search fields.
     *
     * Fetches up to HARD_MAX_RESULTS rows to give PHP-side ranking enough candidates.
     *
     * @param list<string> $words
     * @param list<string> $selectFields
     * @param list<string> $searchFields
     * @param list<int>    $accessiblePids  Pages the backend user is allowed to read
     * @return list<array<string, mixed>>
     */
    private function queryDefaultLanguageRows(
        string $tableName,
        array $words,
        ?string $languageField,
        ?string $deleteField,
        array $selectFields,
        array $searchFields,
        array $accessiblePids,
    ): array {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select(...$selectFields)->from($tableName);

        foreach ($words as $word) {
            $wordConditions = [];
            foreach ($searchFields as $searchField) {
                $wordConditions[] = $queryBuilder->expr()->like(
                    $searchField,
                    $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($word) . '%')
                );
            }
            $queryBuilder->andWhere($queryBuilder->expr()->or(...$wordConditions));
        }

        $queryBuilder->andWhere(
            $queryBuilder->expr()->in('pid', $accessiblePids)
        );

        if ($languageField !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    $languageField,
                    $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)
                )
            );
        }

        if ($deleteField !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    $deleteField,
                    $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)
                )
            );
        }

        $queryBuilder->setMaxResults(self::HARD_MAX_RESULTS);

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * Searches all translation records (sys_language_uid > 0) for the search words,
     * then fetches the corresponding default-language records via transOrigPointerField.
     *
     * Covering all languages ensures that editors can find records regardless of which
     * language their backend is set to (e.g. searching "Müller" with an English backend
     * still finds the contact via its German translation).
     *
     * Records whose default-language UID is already in $excludeUids are skipped so
     * we never return duplicates.
     *
     * @param list<string> $words
     * @param list<string> $selectFields
     * @param list<string> $searchFields
     * @param list<int>    $excludeUids     UIDs already found by the default-language query
     * @param list<int>    $accessiblePids  Pages the backend user is allowed to read
     * @return list<array<string, mixed>>
     */
    private function findTranslationParentRows(
        string $tableName,
        array $words,
        string $languageField,
        string $transOrigPointerField,
        ?string $deleteField,
        array $selectFields,
        array $searchFields,
        array $excludeUids,
        array $accessiblePids,
    ): array {
        // Step 1: find l18n_parent UIDs from translation records matching the words.
        // All languages are covered (sys_language_uid > 0) so editors can search in any
        // language regardless of their backend language setting.
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select($transOrigPointerField)->from($tableName);

        foreach ($words as $word) {
            $wordConditions = [];
            foreach ($searchFields as $searchField) {
                $wordConditions[] = $queryBuilder->expr()->like(
                    $searchField,
                    $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($word) . '%')
                );
            }
            $queryBuilder->andWhere($queryBuilder->expr()->or(...$wordConditions));
        }

        $queryBuilder->andWhere(
            $queryBuilder->expr()->gt(
                $languageField,
                $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)
            )
        );
        $queryBuilder->andWhere(
            $queryBuilder->expr()->in('pid', $accessiblePids)
        );
        // Only include proper translation records (transOrigPointerField > 0)
        $queryBuilder->andWhere(
            $queryBuilder->expr()->gt(
                $transOrigPointerField,
                $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)
            )
        );

        if ($deleteField !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    $deleteField,
                    $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)
                )
            );
        }

        $parentUids = array_column(
            $queryBuilder->executeQuery()->fetchAllAssociative(),
            $transOrigPointerField
        );

        // Remove duplicates and UIDs already covered by the default-language query
        $parentUids = array_values(array_diff(
            array_unique(array_map(fn(mixed $value): int => is_numeric($value) ? (int)$value : 0, $parentUids)),
            $excludeUids
        ));

        if ($parentUids === []) {
            return [];
        }

        // Step 2: fetch the default-language records for these parent UIDs
        $queryBuilder2 = $this->connectionPool->getQueryBuilderForTable($tableName);
        $queryBuilder2->getRestrictions()->removeAll();
        $queryBuilder2->select(...$selectFields)->from($tableName);
        $queryBuilder2->andWhere(
            $queryBuilder2->expr()->in('uid', $parentUids)
        );
        $queryBuilder2->andWhere(
            $queryBuilder2->expr()->eq(
                $languageField,
                $queryBuilder2->createNamedParameter(0, ParameterType::INTEGER)
            )
        );

        if ($deleteField !== null) {
            $queryBuilder2->andWhere(
                $queryBuilder2->expr()->eq(
                    $deleteField,
                    $queryBuilder2->createNamedParameter(0, ParameterType::INTEGER)
                )
            );
        }

        return $queryBuilder2->executeQuery()->fetchAllAssociative();
    }

    /**
     * Counts how many search words are NOT found in the label value (case-insensitive).
     * Used for PHP-side ranking: score 0 means all words matched in the label field.
     *
     * @param list<string> $words
     */
    private function computeLabelRank(string $labelValue, array $words): int
    {
        $lowerLabel = mb_strtolower($labelValue);
        $missCount = 0;
        foreach ($words as $word) {
            if (!str_contains($lowerLabel, mb_strtolower($word))) {
                $missCount++;
            }
        }

        return $missCount;
    }

    /**
     * Returns true when the value sets of two info-item arrays differ.
     * Used to decide whether the default-language info line (line 3) should be shown:
     * it is suppressed when both lines would display identical values.
     *
     * @param list<array{label: string, field: string, value: string}> $infoA
     * @param list<array{label: string, field: string, value: string}> $infoB
     */
    private function infoValuesDiffer(array $infoA, array $infoB): bool
    {
        if (count($infoA) !== count($infoB)) {
            return true;
        }
        foreach ($infoA as $index => $item) {
            if (!isset($infoB[$index]) || $item['value'] !== $infoB[$index]['value']) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $infoFields
     * @return list<array{label: string, field: string, value: string}>
     */
    private function buildInfoItems(string $tableName, array $row, array $infoFields): array
    {
        $languageService = $this->languageServiceFactory->createFromUserPreferences($this->getBackendUser());
        $items = [];

        foreach ($infoFields as $fieldName) {
            if ($fieldName === 'uid') {
                // Clarify that the stored UID is always the default-language record UID,
                // regardless of which language the editor is currently working in.
                $items[] = ['label' => 'ID', 'field' => 'uid', 'value' => $this->rowString($row, 'uid')];
                continue;
            }

            if ($fieldName === 'pid') {
                $items[] = ['label' => 'PID', 'field' => 'pid', 'value' => $this->rowString($row, 'pid')];
                continue;
            }

            $columnConfig = $this->getTcaColumnConfig($tableName, $fieldName);
            if ($columnConfig === null || !array_key_exists($fieldName, $row)) {
                continue;
            }

            $labelValue = $columnConfig['label'] ?? null;
            $label = rtrim($languageService->sL(is_string($labelValue) ? $labelValue : $fieldName), ':');

            $items[] = [
                'label' => $label !== '' ? $label : $fieldName,
                'field' => $fieldName,
                'value' => $this->rowString($row, $fieldName),
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveIconIdentifier(string $tableName, array $row): string
    {
        return $this->iconFactory
            ->getIconForRecord($tableName, $row, IconSize::SMALL)
            ->getIdentifier();
    }

    private function buildEditUrl(string $tableName, int $uid, string $returnUrl): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [$tableName => [$uid => 'edit']],
            'returnUrl' => $returnUrl,
        ]);
    }

    private function resolvePagePath(int $pid): string
    {
        $path = BackendUtility::getRecordPath($pid, '', 50);
        return is_string($path) ? $path : '';
    }

    /**
     * Resolves the first FAL image reference on a record to a 64×64 thumbnail URL.
     *
     * Returns null when no usable image is found or an error occurs.
     */
    private function resolvePreviewImageUrl(string $tableName, int $uid, string $imageField): ?string
    {
        try {
            $fileReferences = $this->fileRepository->findByRelation($tableName, $imageField, $uid);
            if (empty($fileReferences)) {
                return null;
            }
            $originalFile = $fileReferences[0]->getOriginalFile();
            if (!$originalFile->isImage()) {
                return null;
            }
            $processedFile = $originalFile->process(
                ProcessedFile::CONTEXT_IMAGECROPSCALEMASK,
                ['width' => '64c', 'height' => '64c']
            );
            return $processedFile->getPublicUrl() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function jsonResponse(mixed $data, int $statusCode = 200): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $response;
    }

    /**
     * Returns a string value from a DB result row, or $default when the field is absent or non-scalar.
     *
     * @param array<string, mixed> $row
     */
    private function rowString(array $row, string $field, string $default = ''): string
    {
        $value = $row[$field] ?? null;
        return is_scalar($value) ? (string)$value : $default;
    }

    /**
     * Returns an integer value from a DB result row, or $default when the field is absent or non-numeric.
     *
     * @param array<string, mixed> $row
     */
    private function rowInt(array $row, string $field, int $default = 0): int
    {
        $value = $row[$field] ?? null;
        return is_numeric($value) ? (int)$value : $default;
    }

    private function tableExistsInTca(string $tableName): bool
    {
        $tca = $GLOBALS['TCA'] ?? null;
        return is_array($tca) && isset($tca[$tableName]);
    }

    /** @return array<string, mixed> */
    private function getTcaCtrl(string $tableName): array
    {
        $tca = $GLOBALS['TCA'] ?? null;
        if (!is_array($tca)) {
            return [];
        }
        $table = $tca[$tableName] ?? null;
        if (!is_array($table)) {
            return [];
        }
        $ctrl = $table['ctrl'] ?? null;
        return is_array($ctrl) ? $ctrl : [];
    }

    /** @return array<string, mixed> */
    private function getTcaColumns(string $tableName): array
    {
        $tca = $GLOBALS['TCA'] ?? null;
        if (!is_array($tca)) {
            return [];
        }
        $table = $tca[$tableName] ?? null;
        if (!is_array($table)) {
            return [];
        }
        $columns = $table['columns'] ?? null;
        return is_array($columns) ? $columns : [];
    }

    /** @return array<string, mixed>|null */
    private function getTcaColumnConfig(string $tableName, string $fieldName): ?array
    {
        $config = $this->getTcaColumns($tableName)[$fieldName] ?? null;
        if (!is_array($config)) {
            return null;
        }
        /** @var array<string, mixed> $config */
        return $config;
    }

    private function getTcaCtrlString(string $tableName, string $key, string $default = ''): string
    {
        $value = $this->getTcaCtrl($tableName)[$key] ?? null;
        return is_string($value) ? $value : $default;
    }

    private function getTcaCtrlNullableString(string $tableName, string $key): ?string
    {
        $value = $this->getTcaCtrl($tableName)[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    private function getTcaHiddenField(string $tableName): ?string
    {
        $enableColumns = $this->getTcaCtrl($tableName)['enablecolumns'] ?? null;
        if (!is_array($enableColumns)) {
            return null;
        }
        $field = $enableColumns['disabled'] ?? null;
        return is_string($field) ? $field : null;
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        assert($GLOBALS['BE_USER'] instanceof BackendUserAuthentication);
        return $GLOBALS['BE_USER'];
    }
}
