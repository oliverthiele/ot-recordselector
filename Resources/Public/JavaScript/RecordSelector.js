/**
 * RecordSelector — Web Component for the otRecordSelector form element.
 *
 * Selected records are shown as native-looking cards:
 * - Record icon (typo3-backend-icon web component)
 * - Title + hidden badge
 * - UID and page-tree path as a second line
 * - Edit link and remove button
 *
 * Accessibility: role="combobox", aria-expanded, aria-activedescendant, aria-label on remove buttons.
 * maxitems=1: hides search after selection; maxitems>1: keeps search visible.
 */

import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';

const DEBOUNCE_DELAY_MS = 250;
const MIN_SEARCH_LENGTH = 2;

class RecordSelectorElement extends HTMLElement {
  connectedCallback() {
    this.cardsContainer = this.querySelector('.ot-recordselector-cards');
    this.inputGroup = this.querySelector('.ot-recordselector-input-group');
    this.searchInput = this.querySelector('.ot-recordselector-search');
    this.dropdown = this.querySelector('.ot-recordselector-dropdown');
    this.hiddenInput = this.querySelector('.ot-recordselector-value');

    if (!this.searchInput || !this.dropdown || !this.hiddenInput) {
      return;
    }

    this.ajaxUrl = this.dataset.ajaxUrl;
    this.tableName = this.dataset.table;
    this.languageUid = this.dataset.lang ?? '0';
    this.maxItems = parseInt(this.dataset.maxitems ?? '1', 10);
    this.infoFields = this.dataset.infoFields ?? 'uid';
    this.searchFields = this.dataset.searchFields ?? '';
    this.maxResults = parseInt(this.dataset.maxResults ?? '20', 10);
    this.backendLang = this.dataset.backendLang ?? '0';
    this.previewImageField = this.dataset.previewImageField ?? '';
    this.allowRemoveInaccessible = this.dataset.allowRemoveInaccessible !== '0';
    this.modalTitle = this.dataset.modalTitle ?? '';
    this.modalMessage = this.dataset.modalMessage ?? '';
    this.modalConfirm = this.dataset.modalConfirm ?? 'Remove';
    this.modalCancel = this.dataset.modalCancel ?? 'Cancel';

    // data-* attributes use kebab-case in HTML; the browser's dataset API automatically
    // converts them to lowerCamelCase (data-info-fields → dataset.infoFields).

    this.debounceTimer = null;
    this.activeIndex = -1;
    this.currentResults = [];

    // Wire up remove buttons on server-rendered cards
    this.cardsContainer.querySelectorAll('.ot-recordselector-card').forEach((card) => {
      this.bindCardRemoveButton(card);
    });

    this.registerEventListeners();
  }

  registerEventListeners() {
    this.searchInput.addEventListener('input', () => {
      clearTimeout(this.debounceTimer);
      this.debounceTimer = setTimeout(
        () => this.search(this.searchInput.value.trim()),
        DEBOUNCE_DELAY_MS
      );
    });

    this.searchInput.addEventListener('keydown', (event) => this.handleKeydown(event));

    this.searchInput.addEventListener('blur', () => {
      // Delay so mousedown on a dropdown item fires first
      setTimeout(() => this.hideDropdown(), 150);
    });

    this.searchInput.addEventListener('focus', () => {
      const term = this.searchInput.value.trim();
      if (term.length >= MIN_SEARCH_LENGTH) {
        this.search(term);
      }
    });
  }

  handleKeydown(event) {
    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault();
        if (this.dropdown.style.display === 'none') {
          this.search(this.searchInput.value.trim());
        } else {
          this.setActiveIndex(this.activeIndex + 1);
        }
        break;
      case 'ArrowUp':
        event.preventDefault();
        this.setActiveIndex(this.activeIndex - 1);
        break;
      case 'Enter':
        event.preventDefault();
        if (this.activeIndex >= 0 && this.currentResults[this.activeIndex]) {
          this.selectItem(this.currentResults[this.activeIndex]);
        }
        break;
      case 'Escape':
        this.hideDropdown();
        break;
    }
  }

  search(term) {
    if (term.length < MIN_SEARCH_LENGTH) {
      this.hideDropdown();
      return;
    }

    const queryArgs = {
      table: this.tableName,
      search: term,
      lang: this.languageUid,
      backendLang: this.backendLang,
      infoFields: this.infoFields,
      maxResults: this.maxResults,
      returnUrl: window.location.href,
      previewImageField: this.previewImageField,
    };
    if (this.searchFields !== '') {
      queryArgs.searchFields = this.searchFields;
    }

    new AjaxRequest(this.ajaxUrl)
      .withQueryArguments(queryArgs)
      .get()
      .then(async (response) => {
        const data = await response.resolve();
        if (Array.isArray(data)) {
          this.showDropdown(data);
        }
      })
      .catch(() => {
        this.hideDropdown();
      });
  }

  showDropdown(items) {
    this.currentResults = items;
    this.activeIndex = -1;
    this.dropdown.innerHTML = '';

    items.forEach((item, index) => {
      const optionId = `${this.id}-option-${index}`;
      const listItem = document.createElement('li');
      listItem.className = 'list-group-item list-group-item-action ot-recordselector-option d-flex align-items-center gap-2 py-1';
      listItem.setAttribute('role', 'option');
      listItem.setAttribute('id', optionId);
      listItem.setAttribute('aria-selected', 'false');
      listItem.dataset.uid = String(item.uid);

      // Icon or preview image — image preferred when available (64 px ≈ WCAG 2.5.5 target size)
      if (item.image_url) {
        const img = document.createElement('img');
        img.src = item.image_url;
        img.alt = item.title || '';
        img.width = 64;
        img.height = 64;
        img.style.cssText = 'width:64px;height:64px;object-fit:cover;border-radius:3px;flex-shrink:0';
        listItem.appendChild(img);
      } else if (item.icon_identifier) {
        const icon = document.createElement('typo3-backend-icon');
        icon.setAttribute('identifier', item.icon_identifier);
        icon.setAttribute('size', 'small');
        listItem.appendChild(icon);
      }

      // Title + hidden badge + info line
      const contentWrap = document.createElement('span');
      contentWrap.className = 'flex-grow-1 overflow-hidden';

      const titleLine = document.createElement('span');
      titleLine.className = 'd-flex align-items-center gap-1';
      titleLine.textContent = item.title || `[uid:${item.uid}]`;
      if (item.hidden_status === 'hidden' || item.hidden_status === 'partial') {
        const badge = document.createElement('span');
        badge.className = item.hidden_status === 'hidden'
          ? 'badge bg-warning ms-1'
          : 'badge bg-secondary ms-1';
        badge.textContent = item.hidden_status === 'hidden' ? 'hidden' : 'partially hidden';
        titleLine.appendChild(badge);
      }
      if (item.is_accessible === false) {
        const noAccessBadge = document.createElement('span');
        noAccessBadge.className = 'badge bg-info ms-1';
        noAccessBadge.textContent = 'no access';
        titleLine.appendChild(noAccessBadge);
      }
      contentWrap.appendChild(titleLine);

      // Line 1: system info (uid/pid)
      if (Array.isArray(item.info_system) && item.info_system.length > 0) {
        const systemLine = document.createElement('span');
        systemLine.className = 'text-muted small d-block';
        systemLine.textContent = item.info_system.map((i) => `${i.label}: ${i.value}`).join(' · ');
        contentWrap.appendChild(systemLine);
      }
      // Line 2: content fields in editor's language (only when translation is active)
      if (Array.isArray(item.info_translated) && item.info_translated.length > 0) {
        const translatedLine = document.createElement('span');
        translatedLine.className = 'text-muted small d-block';
        translatedLine.textContent = item.info_translated.map((i) => `${i.label}: ${i.value}`).join(' · ');
        contentWrap.appendChild(translatedLine);
      }
      // Line 3: default-language content fields (italic, only when different from line 2)
      if (Array.isArray(item.info_default) && item.info_default.length > 0) {
        const defaultLine = document.createElement('span');
        defaultLine.className = 'text-muted small d-block fst-italic';
        defaultLine.textContent = item.info_default.map((i) => `${i.label}: ${i.value}`).join(' · ');
        contentWrap.appendChild(defaultLine);
      }

      // Show the secondary title (other-language version) as a muted italic line.
      // When editing a translated record: secondary = default-language title.
      // When editing default-language but found via backend language: secondary = translation.
      if (item.title_secondary) {
        const secondaryLine = document.createElement('span');
        secondaryLine.className = 'text-muted small d-block fst-italic';
        secondaryLine.textContent = item.title_secondary;
        contentWrap.appendChild(secondaryLine);
      }

      listItem.appendChild(contentWrap);

      listItem.addEventListener('mousedown', (event) => {
        // Use mousedown so it fires before the input loses focus
        event.preventDefault();
        this.selectItem(item);
      });

      this.dropdown.appendChild(listItem);
    });

    const hasItems = items.length > 0;
    this.dropdown.style.display = hasItems ? 'block' : 'none';
    this.searchInput.setAttribute('aria-expanded', hasItems ? 'true' : 'false');
  }

  hideDropdown() {
    this.dropdown.style.display = 'none';
    this.searchInput.setAttribute('aria-expanded', 'false');
    this.searchInput.removeAttribute('aria-activedescendant');
    this.activeIndex = -1;
  }

  selectItem(item) {
    // For single-select: replace existing card
    if (this.maxItems === 1) {
      this.cardsContainer.innerHTML = '';
    }

    this.addCard(item);
    this.hiddenInput.value = String(item.uid);
    this.searchInput.value = '';
    this.hideDropdown();

    // For single-select: hide the search input after selection
    if (this.maxItems === 1) {
      this.inputGroup.style.display = 'none';
    }

    this.hiddenInput.dispatchEvent(new Event('change', {bubbles: true}));
  }

  addCard(item) {
    const card = document.createElement('li');
    card.className = 'ot-recordselector-card';
    card.setAttribute('role', 'option');
    card.setAttribute('aria-selected', 'true');
    card.dataset.uid = String(item.uid);
    if (item.is_accessible === false) {
      card.dataset.accessible = '0';
    }

    const cardInner = document.createElement('div');
    cardInner.className = 'card mb-1';

    const cardBody = document.createElement('div');
    cardBody.className = 'card-body p-2 d-flex align-items-start gap-2';

    // Icon or preview image — image preferred when available (64 px ≈ WCAG 2.5.5 target size)
    const iconWrap = document.createElement('div');
    iconWrap.className = 'flex-shrink-0';
    if (item.image_url) {
      const img = document.createElement('img');
      img.src = item.image_url;
      img.alt = item.title || '';
      img.width = 64;
      img.height = 64;
      img.style.cssText = 'width:64px;height:64px;object-fit:cover;border-radius:3px';
      iconWrap.appendChild(img);
    } else if (item.icon_identifier) {
      iconWrap.className += ' mt-1';
      const icon = document.createElement('typo3-backend-icon');
      icon.setAttribute('identifier', item.icon_identifier);
      icon.setAttribute('size', 'small');
      iconWrap.appendChild(icon);
    }
    if (iconWrap.firstChild) {
      cardBody.appendChild(iconWrap);
    }

    // Content column: title + meta
    const contentCol = document.createElement('div');
    contentCol.className = 'flex-grow-1 overflow-hidden';

    const titleRow = document.createElement('div');
    titleRow.className = 'd-flex align-items-center gap-1 fw-bold text-truncate';
    titleRow.textContent = item.title || `[uid:${item.uid}]`;

    if (item.hidden_status === 'hidden' || item.hidden_status === 'partial') {
      const badge = document.createElement('span');
      badge.className = item.hidden_status === 'hidden'
        ? 'badge bg-warning ms-1'
        : 'badge bg-secondary ms-1';
      badge.textContent = item.hidden_status === 'hidden' ? 'hidden' : 'partially hidden';
      titleRow.appendChild(badge);
    }
    if (item.is_accessible === false) {
      const noAccessBadge = document.createElement('span');
      noAccessBadge.className = 'badge bg-info ms-1';
      noAccessBadge.textContent = 'no access';
      titleRow.appendChild(noAccessBadge);
    }

    contentCol.appendChild(titleRow);

    // Line 1: system info (uid/pid) + page path
    const systemParts = [];
    if (Array.isArray(item.info_system) && item.info_system.length > 0) {
      systemParts.push(...item.info_system.map((i) => `${i.label}: ${i.value}`));
    } else {
      // Fallback: always show UID when no system info is present
      systemParts.push(`UID: ${item.uid}`);
    }
    if (item.page_path) {
      systemParts.push(item.page_path);
    }
    const systemRow = document.createElement('div');
    systemRow.className = 'text-muted small';
    systemRow.textContent = systemParts.join(' · ');
    contentCol.appendChild(systemRow);

    // Line 2: content fields in editor's language (only when translation is active)
    if (Array.isArray(item.info_translated) && item.info_translated.length > 0) {
      const translatedRow = document.createElement('div');
      translatedRow.className = 'text-muted small';
      translatedRow.textContent = item.info_translated.map((i) => `${i.label}: ${i.value}`).join(' · ');
      contentCol.appendChild(translatedRow);
    }

    // Line 3: default-language content fields (italic, only when different from line 2)
    if (Array.isArray(item.info_default) && item.info_default.length > 0) {
      const defaultInfoRow = document.createElement('div');
      defaultInfoRow.className = 'text-muted small fst-italic';
      defaultInfoRow.textContent = item.info_default.map((i) => `${i.label}: ${i.value}`).join(' · ');
      contentCol.appendChild(defaultInfoRow);
    }
    cardBody.appendChild(contentCol);

    // Actions column: edit + remove
    const actionsCol = document.createElement('div');
    actionsCol.className = 'flex-shrink-0 d-flex gap-1 align-items-start';

    if (item.edit_url && item.can_edit !== false) {
      const editLink = document.createElement('a');
      editLink.href = item.edit_url;
      editLink.className = 'btn btn-default btn-sm';
      editLink.title = 'Edit record';
      editLink.innerHTML = '<typo3-backend-icon identifier="actions-open" size="small"></typo3-backend-icon>';
      actionsCol.appendChild(editLink);
    }

    const showRemove = item.is_accessible !== false || this.allowRemoveInaccessible;
    if (showRemove) {
      const removeButton = document.createElement('button');
      removeButton.type = 'button';
      removeButton.className = 'btn btn-default btn-sm ot-recordselector-card-remove';
      removeButton.setAttribute('aria-label', `Remove ${item.title || item.uid}`);
      removeButton.innerHTML = '<typo3-backend-icon identifier="actions-close" size="small"></typo3-backend-icon>';
      actionsCol.appendChild(removeButton);
    }

    cardBody.appendChild(actionsCol);
    cardInner.appendChild(cardBody);
    card.appendChild(cardInner);

    this.cardsContainer.appendChild(card);
    this.bindCardRemoveButton(card);
  }

  bindCardRemoveButton(card) {
    const removeButton = card.querySelector('.ot-recordselector-card-remove');
    if (!removeButton) {
      return;
    }

    removeButton.addEventListener('click', () => {
      const isAccessible = card.dataset.accessible !== '0';

      if (!isAccessible && this.allowRemoveInaccessible) {
        // Show confirmation modal before removing an inaccessible record
        Modal.confirm(
          this.modalTitle,
          this.modalMessage,
          SeverityEnum.warning,
          [
            {
              text: this.modalCancel,
              btnClass: 'btn-default',
              trigger: (event, modal) => modal.hideModal(),
            },
            {
              text: this.modalConfirm,
              btnClass: 'btn-warning',
              trigger: (event, modal) => {
                this.removeCard(card);
                modal.hideModal();
              },
            },
          ]
        );
      } else {
        this.removeCard(card);
      }
    });
  }

  removeCard(card) {
    card.remove();

    if (this.maxItems === 1) {
      this.hiddenInput.value = '0';
      this.inputGroup.style.display = '';
      this.searchInput.focus();
    } else {
      this.rebuildHiddenValue();
    }

    this.hiddenInput.dispatchEvent(new Event('change', {bubbles: true}));
  }

  rebuildHiddenValue() {
    const uids = Array.from(
      this.cardsContainer.querySelectorAll('.ot-recordselector-card')
    ).map((card) => card.dataset.uid);
    this.hiddenInput.value = uids.join(',');
  }

  setActiveIndex(newIndex) {
    const options = this.dropdown.querySelectorAll('.ot-recordselector-option');
    if (this.activeIndex >= 0 && options[this.activeIndex]) {
      options[this.activeIndex].classList.remove('active');
      options[this.activeIndex].setAttribute('aria-selected', 'false');
    }

    this.activeIndex = Math.max(-1, Math.min(newIndex, options.length - 1));

    if (this.activeIndex >= 0 && options[this.activeIndex]) {
      const activeOption = options[this.activeIndex];
      activeOption.classList.add('active');
      activeOption.setAttribute('aria-selected', 'true');
      activeOption.scrollIntoView({block: 'nearest'});
      this.searchInput.setAttribute('aria-activedescendant', activeOption.id);
    } else {
      this.searchInput.removeAttribute('aria-activedescendant');
    }
  }
}

window.customElements.define('typo3-ot-recordselector', RecordSelectorElement);
