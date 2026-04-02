<?php

declare(strict_types=1);

namespace OliverThiele\OtRecordselector\Form\Element;

use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Custom form element renderType="otRecordSelector".
 *
 * TCA usage example:
 *
 *   'my_field' => [
 *       'config' => [
 *           'type' => 'user',
 *           'renderType' => 'otRecordSelector',
 *           'foreign_table' => 'tx_myext_domain_model_record',
 *           'minitems' => 0,
 *           'maxitems' => 1,
 *       ],
 *   ],
 *
 * Selected records are displayed as native-looking cards with:
 * - Record icon, title, hidden badge
 * - UID and page-tree path in a second line
 * - Edit link and remove button
 *
 * Saves default-language UIDs (sys_language_uid = 0) into the field.
 * Displays translated titles based on the currently edited record's language.
 */
final class RecordSelectorElement extends AbstractFormElement
{
    /** @return array<string, mixed> */
    public function render(): array
    {
        $result = $this->initializeResultArray();

        $parameterArray = $this->data['parameterArray'];
        $fieldConfig = $parameterArray['fieldConf']['config'];
        $fieldName = $parameterArray['itemFormElName'];
        $fieldId = StringUtility::getUniqueId('formengine-ot-recordselector-');
        $dropdownId = $fieldId . '-dropdown';
        $currentValue = (int)($parameterArray['itemFormElValue'] ?? 0);
        $maxItems = (int)($fieldConfig['maxitems'] ?? 1);
        // Comma-separated list of DB fields to show as additional info in results and cards.
        // Use 'uid' as a special keyword for the record UID. Defaults to 'uid' if not set.
        $infoFields = GeneralUtility::trimExplode(',', (string)($fieldConfig['infoFields'] ?? 'uid'), true);
        // Comma-separated DB fields to search in. Falls back to ctrl.searchFields, then label field.
        $searchFields = (string)($fieldConfig['searchFields'] ?? '');
        // Maximum number of results returned by the AJAX endpoint (hard cap: 200).
        $maxResults = (int)($fieldConfig['maxResults'] ?? 20);
        // FAL field on the foreign table whose first image is shown as a 64×64 preview thumbnail.
        // Falls back to the TYPO3 record icon when the field is empty or yields no image.
        $previewImageField = (string)($fieldConfig['previewImage'] ?? '');
        // When true, records stored at pid=0 (site root level) are included in search results
        // for non-admin editors. Admins always have access regardless of this setting.
        // Default: false — root-level records are restricted to admins.
        $allowRootLevel = (bool)($fieldConfig['allowRootLevel'] ?? false);
        // When false, the remove button is hidden for records on pages the editor cannot access.
        // When true (default), the remove button is shown with a confirmation dialog warning
        // that the selection cannot be restored after removal.
        $allowRemoveInaccessible = (bool)($fieldConfig['allowRemoveInaccessible'] ?? true);

        $tableName = (string)($fieldConfig['foreign_table'] ?? '');
        if ($tableName === '' || !$this->tableExistsInTca($tableName)) {
            $result['html'] = '<p class="text-danger">otRecordSelector: missing or unknown foreign_table</p>';

            return $result;
        }

        $languageUid = $this->resolveEditingLanguageUid();
        // The backend user's preferred language UID — used so editors can search
        // in their own language even when editing a default-language (lang=0) record.
        $backendLanguageUid = $this->resolveBackendUserLanguageUid();
        $isDebugMode = $this->isBackendDebugMode();
        $tableLabel = $this->getLanguageService()->sL(
            $this->getTcaCtrlString($tableName, 'title', $tableName)
        );
        $placeholder = $this->getLanguageService()->sL(
            'LLL:EXT:ot_recordselector/Resources/Private/Language/locallang.xlf:element.placeholder'
        );
        $removeLabel = $this->getLanguageService()->sL(
            'LLL:EXT:ot_recordselector/Resources/Private/Language/locallang.xlf:chip.remove'
        );
        $hiddenLabel = $this->getLanguageService()->sL(
            'LLL:EXT:ot_recordselector/Resources/Private/Language/locallang.xlf:badge.hidden'
        );
        $noAccessLabel = $this->getLanguageService()->sL(
            'LLL:EXT:ot_recordselector/Resources/Private/Language/locallang.xlf:badge.no_access'
        );
        $modalTitle = $this->getLanguageService()->sL(
            'LLL:EXT:ot_recordselector/Resources/Private/Language/locallang.xlf:modal.remove_inaccessible.title'
        );
        $modalMessage = $this->getLanguageService()->sL(
            'LLL:EXT:ot_recordselector/Resources/Private/Language/locallang.xlf:modal.remove_inaccessible.message'
        );
        $modalConfirm = $this->getLanguageService()->sL(
            'LLL:EXT:ot_recordselector/Resources/Private/Language/locallang.xlf:modal.remove_inaccessible.confirm'
        );
        $modalCancel = $this->getLanguageService()->sL(
            'LLL:EXT:ot_recordselector/Resources/Private/Language/locallang.xlf:modal.remove_inaccessible.cancel'
        );

        // Display language = always the backend user's preferred language.
        // Mirrors the controller logic so the server-rendered card matches the AJAX results.
        $displayLanguageUid = $backendLanguageUid;

        // Build server-side card for the pre-selected value
        $initialCard = '';
        $inputVisible = true;
        if ($currentValue > 0) {
            $recordData = $this->resolveRecordData($tableName, $currentValue, $displayLanguageUid, $infoFields, $previewImageField);
            $initialCard = $this->buildCard(
                $currentValue,
                $recordData['title'],
                $recordData['icon_identifier'],
                $recordData['image_url'],
                $recordData['page_path'],
                $recordData['edit_url'],
                $recordData['hidden_status'],
                $recordData['info_system'],
                $recordData['info_translated'],
                $recordData['info_default'],
                $recordData['is_accessible'],
                $allowRemoveInaccessible,
                $removeLabel,
                $hiddenLabel,
                $noAccessLabel,
                $isDebugMode,
            );
            if ($maxItems === 1) {
                $inputVisible = false;
            }
        }

        // Security: allowRootLevel is baked into the server-generated URL so it cannot be
        // tampered with client-side. The JS sends this URL as-is without re-sending the parameter.
        $ajaxUrl = (string)$this->getBackendUriBuilder()->buildUriFromRoute(
            'ajax_ot_recordselector_search',
            $allowRootLevel ? ['allowRootLevel' => '1'] : []
        );

        $html = $this->buildHtml(
            $fieldId,
            $dropdownId,
            $fieldName,
            $currentValue,
            $ajaxUrl,
            $tableName,
            $languageUid,
            $backendLanguageUid,
            $maxItems,
            $infoFields,
            $searchFields,
            $maxResults,
            $previewImageField,
            $allowRemoveInaccessible,
            $tableLabel,
            $placeholder,
            $modalTitle,
            $modalMessage,
            $modalConfirm,
            $modalCancel,
            $initialCard,
            $inputVisible,
            $isDebugMode,
        );

        $result['html'] = $html;
        // Loading the module registers the custom element <typo3-ot-recordselector>.
        // No invoke() needed — connectedCallback() fires automatically when the element enters the DOM.
        $result['javaScriptModules'][] = JavaScriptModuleInstruction::create(
            '@oliverthiele/ot-recordselector/RecordSelector.js'
        );

        return $result;
    }

    /**
     * @param list<array{label: string, field: string, value: string}> $infoSystem     UID/PID — always line 1
     * @param list<array{label: string, field: string, value: string}> $infoTranslated Content fields in editor language — line 2 (empty when lang=0)
     * @param list<array{label: string, field: string, value: string}> $infoDefault    Content fields in default language — line 3 (empty when same as line 2)
     */
    private function buildCard(
        int $uid,
        string $title,
        string $iconIdentifier,
        ?string $imageUrl,
        string $pagePath,
        string $editUrl,
        string|null $hiddenStatus,
        array $infoSystem,
        array $infoTranslated,
        array $infoDefault,
        bool $isAccessible,
        bool $allowRemoveInaccessible,
        string $removeLabel,
        string $hiddenLabel,
        string $noAccessLabel,
        bool $isDebugMode = false,
    ): string {
        $uidEncoded = htmlspecialchars((string)$uid, ENT_QUOTES);
        $titleEncoded = htmlspecialchars($title, ENT_QUOTES);
        $iconIdentifierEncoded = htmlspecialchars($iconIdentifier, ENT_QUOTES);
        $pagePathEncoded = htmlspecialchars($pagePath, ENT_QUOTES);
        $editUrlEncoded = htmlspecialchars($editUrl, ENT_QUOTES);
        $removeLabelEncoded = htmlspecialchars($removeLabel . ' ' . $title, ENT_QUOTES);

        $hiddenBadge = match ($hiddenStatus) {
            'hidden'  => '<span class="badge bg-warning ms-1">' . htmlspecialchars($hiddenLabel, ENT_QUOTES) . '</span>',
            'partial' => '<span class="badge bg-secondary ms-1">partially hidden</span>',
            default   => '',
        };
        $noAccessBadge = !$isAccessible
            ? '<span class="badge bg-info ms-1">' . htmlspecialchars($noAccessLabel, ENT_QUOTES) . '</span>'
            : '';
        // When allowRemoveInaccessible is false and the record is not accessible,
        // the remove button is hidden — the editor cannot restore the selection.
        $showRemoveButton = $isAccessible || $allowRemoveInaccessible;
        $accessibleAttr = $isAccessible ? '' : ' data-accessible="0"';

        $editButton = $editUrl !== ''
            ? '<a href="' . $editUrlEncoded . '" class="btn btn-default btn-sm" title="Edit record">'
              . '<typo3-backend-icon identifier="actions-open" size="small"></typo3-backend-icon>'
              . '</a>'
            : '';

        // Renders a single info line as "Label: value · Label: value" with optional debug markers.
        $renderInfoLine = function (array $items, bool $italic = false) use ($isDebugMode): string {
            $parts = [];
            foreach ($items as $item) {
                $labelEncoded = htmlspecialchars($item['label'], ENT_QUOTES);
                $valueEncoded = htmlspecialchars($item['value'], ENT_QUOTES);
                $fieldDebug = $isDebugMode
                    ? ' <span class="text-muted">[' . htmlspecialchars($item['field'], ENT_QUOTES) . ']</span>'
                    : '';
                $parts[] = '<span>' . $labelEncoded . ': ' . $valueEncoded . $fieldDebug . '</span>';
            }
            return implode('<span class="mx-1">·</span>', $parts);
        };

        // Line 1: system info (uid/pid) + page-tree path
        $systemParts = [];
        foreach ($infoSystem as $item) {
            $labelEncoded = htmlspecialchars($item['label'], ENT_QUOTES);
            $valueEncoded = htmlspecialchars($item['value'], ENT_QUOTES);
            $fieldDebug = $isDebugMode
                ? ' <span class="text-muted">[' . htmlspecialchars($item['field'], ENT_QUOTES) . ']</span>'
                : '';
            $systemParts[] = '<span>' . $labelEncoded . ': ' . $valueEncoded . $fieldDebug . '</span>';
        }
        if ($pagePath !== '') {
            $systemParts[] = '<span title="' . $pagePathEncoded . '">' . $pagePathEncoded . '</span>';
        }
        $systemLine = implode('<span class="mx-1">·</span>', $systemParts);

        // Line 2: translated content info (editor's language)
        $translatedLine = $renderInfoLine($infoTranslated);

        // Line 3: default-language content info (italic, only when different from line 2)
        $defaultLine = $renderInfoLine($infoDefault);

        $translatedLineHtml = $translatedLine !== ''
            ? '<div class="text-muted small">' . $translatedLine . '</div>'
            : '';
        $defaultLineHtml = $defaultLine !== ''
            ? '<div class="text-muted small fst-italic">' . $defaultLine . '</div>'
            : '';

        // Prefer the preview image (64×64 thumbnail) over the TYPO3 record icon.
        // 64 px gives enough context alongside multiple info lines while staying compact.
        if ($imageUrl !== null && $imageUrl !== '') {
            $imageUrlEncoded = htmlspecialchars($imageUrl, ENT_QUOTES);
            $titleAttrEncoded = htmlspecialchars($title, ENT_QUOTES);
            $iconOrImage = '<img src="' . $imageUrlEncoded . '" alt="' . $titleAttrEncoded . '"'
                . ' width="64" height="64"'
                . ' style="width:64px;height:64px;object-fit:cover;border-radius:3px">';
        } else {
            $iconOrImage = '<typo3-backend-icon identifier="' . $iconIdentifierEncoded . '" size="small"></typo3-backend-icon>';
        }

        $removeButton = $showRemoveButton
            ? '<button type="button"
                        class="btn btn-default btn-sm ot-recordselector-card-remove"
                        aria-label="' . $removeLabelEncoded . '">
                    <typo3-backend-icon identifier="actions-close" size="small"></typo3-backend-icon>
                </button>'
            : '';

        return <<<HTML
<li class="ot-recordselector-card" role="option" aria-selected="true" data-uid="{$uidEncoded}"{$accessibleAttr}>
    <div class="card mb-1">
        <div class="card-body p-2 d-flex align-items-start gap-2">
            <div class="flex-shrink-0">
                {$iconOrImage}
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="d-flex align-items-center gap-1 fw-bold text-truncate">
                    {$titleEncoded}{$hiddenBadge}{$noAccessBadge}
                </div>
                <div class="text-muted small">{$systemLine}</div>
                {$translatedLineHtml}
                {$defaultLineHtml}
            </div>
            <div class="flex-shrink-0 d-flex gap-1 align-items-start">
                {$editButton}
                {$removeButton}
            </div>
        </div>
    </div>
</li>
HTML;
    }

    /**
     * @param list<string> $infoFields
     */
    private function buildHtml(
        string $fieldId,
        string $dropdownId,
        string $fieldName,
        int $currentValue,
        string $ajaxUrl,
        string $tableName,
        int $languageUid,
        int $backendLanguageUid,
        int $maxItems,
        array $infoFields,
        string $searchFields,
        int $maxResults,
        string $previewImageField,
        bool $allowRemoveInaccessible,
        string $tableLabel,
        string $placeholder,
        string $modalTitle,
        string $modalMessage,
        string $modalConfirm,
        string $modalCancel,
        string $initialCard,
        bool $inputVisible,
        bool $isDebugMode,
    ): string {
        $fieldIdEncoded = htmlspecialchars($fieldId, ENT_QUOTES);
        $dropdownIdEncoded = htmlspecialchars($dropdownId, ENT_QUOTES);
        $fieldNameEncoded = htmlspecialchars($fieldName, ENT_QUOTES);
        $currentValueEncoded = htmlspecialchars((string)$currentValue, ENT_QUOTES);
        $ajaxUrlEncoded = htmlspecialchars($ajaxUrl, ENT_QUOTES);
        $tableNameEncoded = htmlspecialchars($tableName, ENT_QUOTES);
        $tableLabelEncoded = htmlspecialchars($tableLabel, ENT_QUOTES);
        $placeholderEncoded = htmlspecialchars($placeholder, ENT_QUOTES);
        $inputStyle = $inputVisible ? '' : 'display:none';

        // Debug info: show [tableName] and [fieldName] next to the label (mirrors TYPO3 core behaviour)
        $debugInfo = $isDebugMode
            ? ' <span class="text-muted" style="font-weight:normal;font-size:0.85em">'
              . htmlspecialchars('[' . $tableName . ']', ENT_QUOTES)
              . '</span>'
            : '';

        // Extract the FlexForm field key from the full form-element name for debug display.
        // The full name looks like data[tt_content][1][pi_flexform][data][sDEF][lDEF][settings.flexForm.contact][vDEF]
        $flexFieldDebug = '';
        if ($isDebugMode) {
            preg_match('/\[([^\]]+)\]\[vDEF\]$/', $fieldName, $matches);
            $flexKey = $matches[1] ?? $this->data['fieldName'] ?? '';
            if ($flexKey !== '') {
                $flexFieldDebug = ' <span class="text-muted" style="font-weight:normal;font-size:0.85em">'
                    . htmlspecialchars('[' . $flexKey . ']', ENT_QUOTES)
                    . '</span>';
            }
        }

        $infoFieldsEncoded = htmlspecialchars(implode(',', $infoFields), ENT_QUOTES);
        $searchFieldsEncoded = htmlspecialchars($searchFields, ENT_QUOTES);
        $previewImageFieldEncoded = htmlspecialchars($previewImageField, ENT_QUOTES);
        $allowRemoveInaccessibleEncoded = $allowRemoveInaccessible ? '1' : '0';
        $modalTitleEncoded = htmlspecialchars($modalTitle, ENT_QUOTES);
        $modalMessageEncoded = htmlspecialchars($modalMessage, ENT_QUOTES);
        $modalConfirmEncoded = htmlspecialchars($modalConfirm, ENT_QUOTES);
        $modalCancelEncoded = htmlspecialchars($modalCancel, ENT_QUOTES);

        return <<<HTML
<typo3-ot-recordselector id="{$fieldIdEncoded}"
     data-ajax-url="{$ajaxUrlEncoded}"
     data-table="{$tableNameEncoded}"
     data-lang="{$languageUid}"
     data-backend-lang="{$backendLanguageUid}"
     data-maxitems="{$maxItems}"
     data-info-fields="{$infoFieldsEncoded}"
     data-search-fields="{$searchFieldsEncoded}"
     data-max-results="{$maxResults}"
     data-preview-image-field="{$previewImageFieldEncoded}"
     data-allow-remove-inaccessible="{$allowRemoveInaccessibleEncoded}"
     data-modal-title="{$modalTitleEncoded}"
     data-modal-message="{$modalMessageEncoded}"
     data-modal-confirm="{$modalConfirmEncoded}"
     data-modal-cancel="{$modalCancelEncoded}"
     style="display:block;position:relative">

    <p class="ot-recordselector-label mb-1"><strong>{$tableLabelEncoded}</strong>{$debugInfo}{$flexFieldDebug}</p>

    <ul class="ot-recordselector-cards list-unstyled mb-1"
        role="listbox"
        aria-label="{$tableLabelEncoded}"
        aria-multiselectable="true">
        {$initialCard}
    </ul>

    <div class="input-group ot-recordselector-input-group" style="{$inputStyle}">
        <span class="input-group-text">
            <typo3-backend-icon identifier="actions-search" size="small"></typo3-backend-icon>
        </span>
        <input type="text"
               class="form-control ot-recordselector-search"
               placeholder="{$placeholderEncoded}"
               autocomplete="off"
               role="combobox"
               aria-label="{$tableLabelEncoded}"
               aria-expanded="false"
               aria-controls="{$dropdownIdEncoded}"
               aria-autocomplete="list"
               aria-haspopup="listbox" />
    </div>

    <ul class="ot-recordselector-dropdown list-group"
        id="{$dropdownIdEncoded}"
        role="listbox"
        aria-label="{$tableLabelEncoded}"
        style="display:none;position:absolute;z-index:1000;max-height:240px;overflow-y:auto;width:100%;box-shadow:0 2px 4px rgba(0,0,0,.1)"></ul>

    <input type="hidden"
           name="{$fieldNameEncoded}"
           value="{$currentValueEncoded}"
           class="ot-recordselector-value" />
</typo3-ot-recordselector>
HTML;
    }

    /**
     * Loads the record from DB and returns all data needed to render the card.
     *
     * @param list<string> $infoFields
     * @return array{title: string, icon_identifier: string, image_url: string|null, pid: int, page_path: string, edit_url: string, hidden_status: 'hidden'|'partial'|null, is_accessible: bool, can_edit: bool, info_system: list<array{label: string, field: string, value: string}>, info_translated: list<array{label: string, field: string, value: string}>, info_default: list<array{label: string, field: string, value: string}>}
     */
    private function resolveRecordData(string $tableName, int $uid, int $languageUid, array $infoFields, string $previewImageField = ''): array
    {
        $labelField = $this->getTcaCtrlString($tableName, 'label', 'title');
        $hiddenField = $this->getTcaHiddenField($tableName);

        $row = BackendUtility::getRecord($tableName, $uid) ?? [];
        $defaultRow = $row; // Save before overlay for default-language info comparison

        $contentInfoFields = array_values(array_filter(
            $infoFields,
            static fn(string $field) => $field !== 'uid' && $field !== 'pid'
        ));
        $systemInfoFields = array_values(array_filter(
            $infoFields,
            static fn(string $field) => $field === 'uid' || $field === 'pid'
        ));

        // Overlay label field and all configured info fields with translated values.
        // uid and pid are always kept from the default-language record — the translation
        // record has a different uid (the overlay row uid) which must never replace the
        // stored default-language uid.
        $translationHidden = null; // null = no translation found for this display language
        if ($languageUid > 0 && $row !== []) {
            $overlayedRow = BackendUtility::getRecordLocalization($tableName, $uid, $languageUid);
            if (is_array($overlayedRow) && !empty($overlayedRow[0]) && is_array($overlayedRow[0])) {
                /** @var array<string, mixed> $translatedRow */
                $translatedRow = $overlayedRow[0];

                // Capture the translation's hidden state before content overlay
                if ($hiddenField !== null) {
                    $translationHidden = (bool)($translatedRow[$hiddenField] ?? false);
                }

                foreach ([$labelField, ...$infoFields] as $fieldName) {
                    if ($fieldName === 'uid' || $fieldName === 'pid') {
                        continue;
                    }
                    if (isset($translatedRow[$fieldName]) && $translatedRow[$fieldName] !== '') {
                        $row[$fieldName] = $translatedRow[$fieldName];
                    }
                }
            }
        }

        $defaultHidden = $hiddenField !== null && (bool)($row[$hiddenField] ?? false);
        if ($translationHidden === null) {
            $hiddenStatus = $defaultHidden ? 'hidden' : null;
        } elseif ($defaultHidden && $translationHidden) {
            $hiddenStatus = 'hidden';
        } elseif ($defaultHidden || $translationHidden) {
            $hiddenStatus = 'partial';
        } else {
            $hiddenStatus = null;
        }

        $title = $this->rowString($row, $labelField);
        $pid = $this->rowInt($row, 'pid');
        $isAccessible = $this->isPidAccessible($pid);
        $canEdit = $isAccessible && $this->getBackendUser()->check('tables_modify', $tableName);
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);

        $infoSystem = $this->buildInfoItems($tableName, $row, $uid, $systemInfoFields);
        $infoTranslated = $languageUid > 0 && $contentInfoFields !== []
            ? $this->buildInfoItems($tableName, $row, $uid, $contentInfoFields)
            : [];
        $infoDefaultItems = $contentInfoFields !== []
            ? $this->buildInfoItems($tableName, $defaultRow, $uid, $contentInfoFields)
            : [];
        $infoDefault = $infoTranslated !== [] && !$this->infoValuesDiffer($infoTranslated, $infoDefaultItems)
            ? []
            : $infoDefaultItems;

        return [
            'title' => $title,
            'icon_identifier' => $row !== []
                ? $iconFactory->getIconForRecord($tableName, $row, IconSize::SMALL)->getIdentifier()
                : 'default-not-found',
            'image_url' => $previewImageField !== ''
                ? $this->resolvePreviewImageUrl($tableName, $uid, $previewImageField)
                : null,
            'pid' => $pid,
            'page_path' => $pid > 0 ? $this->resolvePagePath($pid) : '',
            'edit_url' => $canEdit ? $this->buildEditUrl($tableName, $uid) : '',
            'hidden_status' => $hiddenStatus,
            'is_accessible' => $isAccessible,
            'can_edit' => $canEdit,
            'info_system' => $infoSystem,
            'info_translated' => $infoTranslated,
            'info_default' => $infoDefault,
        ];
    }

    /**
     * Returns true when the value sets of two info-item arrays differ.
     * Used to decide whether the default-language info line (line 3) should be shown.
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
     * Builds the info items array for a record row based on the configured info_fields.
     *
     * @param array<string, mixed> $row
     * @param list<string> $infoFields
     * @return list<array{label: string, field: string, value: string}>
     */
    private function buildInfoItems(string $tableName, array $row, int $uid, array $infoFields): array
    {
        $items = [];

        foreach ($infoFields as $fieldName) {
            if ($fieldName === 'uid') {
                $items[] = ['label' => 'ID', 'field' => 'uid', 'value' => (string)$uid];
                continue;
            }

            if ($fieldName === 'pid') {
                $items[] = ['label' => 'PID', 'field' => 'pid', 'value' => $this->rowString($row, 'pid')];
                continue;
            }

            $columnConfig = $this->getTcaColumnConfig($tableName, $fieldName);
            if ($columnConfig === null) {
                continue;
            }

            $labelValue = $columnConfig['label'] ?? null;
            $label = rtrim(
                $this->getLanguageService()->sL(is_string($labelValue) ? $labelValue : $fieldName),
                ':'
            );

            $items[] = [
                'label' => $label !== '' ? $label : $fieldName,
                'field' => $fieldName,
                'value' => $this->rowString($row, $fieldName),
            ];
        }

        return $items;
    }

    /**
     * Resolves the first FAL image reference on a record to a 64×64 thumbnail URL.
     * Returns null when no usable image is found or an error occurs.
     *
     * @see RecordSelectorController::resolvePreviewImageUrl() — same logic, shared contract
     */
    private function resolvePreviewImageUrl(string $tableName, int $uid, string $imageField): ?string
    {
        try {
            $fileRepository = GeneralUtility::makeInstance(FileRepository::class);
            $fileReferences = $fileRepository->findByRelation($tableName, $imageField, $uid);
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

    private function buildEditUrl(string $tableName, int $uid): string
    {
        return (string)$this->getBackendUriBuilder()->buildUriFromRoute('record_edit', [
            'edit' => [$tableName => [$uid => 'edit']],
            'returnUrl' => GeneralUtility::getIndpEnv('REQUEST_URI'),
        ]);
    }

    private function isPidAccessible(int $pid): bool
    {
        if ($pid === 0) {
            return $this->getBackendUser()->isAdmin();
        }
        $backendUser = $this->getBackendUser();
        if (!$backendUser->isInWebMount($pid)) {
            return false;
        }
        return (bool)BackendUtility::readPageAccess(
            $pid,
            $backendUser->getPagePermsClause(Permission::PAGE_SHOW)
        );
    }

    private function resolvePagePath(int $pid): string
    {
        $path = BackendUtility::getRecordPath($pid, '', 50);
        return is_string($path) ? $path : '';
    }

    /**
     * Returns the raw language code from the backend user preferences.
     * Used for debug output and as the source for resolveBackendUserLanguageUid().
     *
     * TYPO3 stores the language in two places — we try both:
     *   - BE_USER->user['lang'] — from the sys_be_users DB row (most reliable)
     *   - BE_USER->uc['lang']   — from serialised user preferences (may be empty)
     */
    private function getBackendUserLanguageCode(): string
    {
        $backendUser = $this->getBackendUser();

        // Try the DB row first (sys_be_users.lang), then the serialised preferences (uc['lang']).
        $fromUserRowRaw = $backendUser->user['lang'] ?? null;
        $fromUserRow = is_string($fromUserRowRaw) ? $fromUserRowRaw : '';
        if ($fromUserRow !== '' && $fromUserRow !== 'default') {
            return $fromUserRow;
        }

        $fromUcRaw = $backendUser->uc['lang'] ?? null;
        $fromUc = is_string($fromUcRaw) ? $fromUcRaw : '';
        if ($fromUc !== '' && $fromUc !== 'default') {
            return $fromUc;
        }

        // Last resort: read directly from the LanguageService locale
        // (handles cases where uc is not yet populated for this session)
        return $this->getLanguageService()->lang;
    }

    private function resolveBackendUserLanguageUid(): int
    {
        $backendUserLang = $this->getBackendUserLanguageCode();
        if ($backendUserLang === '' || $backendUserLang === 'default') {
            return 0;
        }

        // Match the language code against the site languages from the site configuration.
        $site = $this->data['site'] ?? null;
        if ($site !== null) {
            foreach ($site->getAllLanguages() as $siteLanguage) {
                if ($siteLanguage->getLanguageId() === 0) {
                    continue;
                }
                if ($siteLanguage->getLocale()->getLanguageCode() === $backendUserLang) {
                    return $siteLanguage->getLanguageId();
                }
            }
        }

        return 0;
    }

    private function resolveEditingLanguageUid(): int
    {
        $languageField = $this->getTcaCtrlNullableString((string)$this->data['tableName'], 'languageField');
        if ($languageField === null) {
            return 0;
        }

        return (int)($this->data['databaseRow'][$languageField][0] ?? $this->data['databaseRow'][$languageField] ?? 0);
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

    private function isBackendDebugMode(): bool
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        if (!is_array($confVars)) {
            return false;
        }
        $beConf = $confVars['BE'] ?? null;
        return is_array($beConf) && (bool)($beConf['debug'] ?? false);
    }

    private function getBackendUriBuilder(): UriBuilder
    {
        return GeneralUtility::makeInstance(UriBuilder::class);
    }
}
