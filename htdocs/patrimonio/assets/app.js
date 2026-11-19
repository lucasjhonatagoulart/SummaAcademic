document.addEventListener('DOMContentLoaded', () => {
  const list = document.getElementById('items-list');
  const template = document.getElementById('item-template');
  const editor = document.querySelector('[data-invoice-editor]');
  const form = editor?.querySelector('[data-invoice-form]');
  const itemRows = () => [...(list?.querySelectorAll('[data-item-card]') ?? [])];
  const money = value => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value) || 0);
  const text = value => String(value ?? '').trim();
  const parseNumber = value => {
    let raw = text(value).replace(/\s/g, '');
    if (raw.includes(',') && raw.includes('.')) raw = raw.replace(/\./g, '').replace(',', '.');
    else raw = raw.replace(',', '.');
    const numeric = Number(raw);
    return Number.isFinite(numeric) ? numeric : 0;
  };

  let nextIndex = 0;
  if (list) {
    for (const field of list.querySelectorAll('[name^="items["]')) {
      const match = field.name.match(/^items\[(\d+)\]/);
      if (match) nextIndex = Math.max(nextIndex, Number(match[1]) + 1);
    }
  }

  const sourceField = editor?.querySelector('[data-invoice-source]');
  const setSource = value => {
    if (!sourceField) return;
    sourceField.value = value === 'xml' ? 'xml' : 'manual';
    const imported = sourceField.value === 'xml';
    editor.querySelectorAll('[data-source-option]').forEach(card => {
      const selected = card.dataset.sourceOption === sourceField.value;
      card.classList.toggle('is-selected', selected);
      const button = card.querySelector('[data-set-invoice-source]');
      if (button) {
        button.setAttribute('aria-pressed', String(selected));
        button.textContent = selected ? 'Selecionado' : 'Usar cadastro manual';
      }
    });
    editor.querySelectorAll('[data-source-label]').forEach(node => {
      node.textContent = imported ? 'XML importado' : 'Cadastro manual';
      node.classList.toggle('green', imported);
    });
    const fiscalStatus = editor.querySelector('[data-fiscal-status]');
    if (fiscalStatus) {
      fiscalStatus.textContent = imported ? 'Campos do XML protegidos' : 'Cadastro manual';
      fiscalStatus.classList.toggle('green', imported);
    }
    const readOnlyNote = editor.querySelector('[data-readonly-note]');
    if (readOnlyNote) readOnlyNote.hidden = !imported;
    editor.querySelectorAll('[data-xml-optional]').forEach(node => { node.hidden = !imported; });
    const xmlOptionalNote = editor.querySelector('[data-xml-optional-note]');
    if (xmlOptionalNote) xmlOptionalNote.hidden = !imported;
    const manualNote = editor.querySelector('[data-manual-note]');
    if (manualNote) manualNote.hidden = imported;
    editor.querySelectorAll('[data-invoice-field]').forEach(field => {
      if (field.dataset.invoiceField !== 'notes') field.readOnly = imported;
    });
    editor.querySelectorAll('[data-item-card]').forEach(card => {
      card.querySelectorAll('[data-item-field]').forEach(field => {
        if (['originalDescription', 'quantity', 'unit', 'unitValue', 'ncm', 'cfop'].includes(field.dataset.itemField)) {
          field.readOnly = imported;
        }
      });
      const manualButtons = card.querySelectorAll('[data-manual-only]');
      manualButtons.forEach(button => { button.hidden = imported; });
      const sourceNote = card.querySelector('.invoice-item-source-note');
      if (sourceNote) sourceNote.hidden = !imported;
      updateItemCard(card);
    });
    editor.querySelectorAll('[data-manual-only]').forEach(button => { button.hidden = imported; });
    updateReview();
  };

  const updateItemCard = card => {
    if (!card) return;
    const classification = card.querySelector('[data-item-field="classification"]')?.value || 'fixed';
    const skipped = classification === 'skip';
    const patrimonialFields = new Set(['name', 'category', 'location', 'patrimony', 'usefulLife', 'brand', 'color', 'type', 'condition', 'notes']);
    card.querySelectorAll('[data-item-field]').forEach(field => {
      if (!patrimonialFields.has(field.dataset.itemField)) return;
      if (field.tagName === 'SELECT') {
        const hidden = [...card.querySelectorAll('[data-skip-hidden]')].find(input => input.dataset.skipHidden === field.dataset.itemField);
        if (skipped) {
          field.disabled = true;
          let preserved = hidden;
          if (!preserved) {
            preserved = document.createElement('input');
            preserved.type = 'hidden';
            preserved.name = field.name;
            preserved.dataset.skipHidden = field.dataset.itemField;
            field.insertAdjacentElement('afterend', preserved);
          }
          preserved.value = field.value;
        } else {
          field.disabled = false;
          hidden?.remove();
        }
      } else {
        field.readOnly = skipped;
      }
    });
    card.classList.toggle('is-skipped', skipped);
    const label = card.querySelector('[data-classification-label]');
    if (label) {
      label.textContent = skipped ? 'Não incorporar' : classification === 'small' ? 'Pequeno valor' : 'Ativo fixo';
      label.classList.toggle('amber', skipped);
    }
    const note = card.querySelector('[data-skip-note]');
    if (note) note.hidden = !skipped;
    const title = card.querySelector('[data-item-name]')?.value;
    const titleNode = card.querySelector('[data-item-title]');
    if (titleNode) titleNode.textContent = text(title) || 'Item sem nome padronizado';
  };

  const setReviewText = (field, value) => {
    editor?.querySelectorAll(`[data-review-field="${field}"]`).forEach(node => {
      if (node.tagName === 'P') node.textContent = value;
      else node.textContent = value || 'Não informado';
    });
  };

  function updateReview() {
    if (!editor || !form) return;
    const readHeader = name => form.querySelector(`[data-invoice-field="${name}"]`)?.value ?? '';
    for (const field of ['number', 'series', 'accessKey', 'supplier', 'supplierCnpj', 'recipient', 'recipientCnpj', 'issuedAt']) {
      setReviewText(field, text(readHeader(field)) || 'Não informado');
    }
    const invoiceTotal = editor.querySelector('[data-review-field="total"]');
    if (invoiceTotal) invoiceTotal.textContent = money(parseNumber(readHeader('total')));
    const sourceSummary = editor.querySelector('[data-review-source]');
    if (sourceSummary) sourceSummary.textContent = sourceField?.value === 'xml' ? 'XML importado' : 'Cadastro manual';
    const noteText = text(readHeader('notes'));
    const reviewNotes = editor.querySelector('[data-review-notes]');
    if (reviewNotes) {
      reviewNotes.hidden = !noteText;
      const paragraph = reviewNotes.querySelector('[data-review-field="notes"]');
      if (paragraph) paragraph.textContent = noteText;
    }

    let itemTotal = 0;
    let included = 0;
    let skipped = 0;
    const rows = itemRows();
    editor.querySelectorAll('[data-item-count]').forEach(node => { node.textContent = String(rows.length); });
    rows.forEach((card, index) => {
      updateItemCard(card);
      const get = field => card.querySelector(`[data-item-field="${field}"]`)?.value ?? '';
      const total = parseNumber(get('total'));
      itemTotal += total;
      const isSkipped = get('classification') === 'skip';
      if (isSkipped) skipped += 1; else included += 1;
      const indexNode = card.querySelector('.invoice-item-index');
      if (indexNode) indexNode.textContent = String(index + 1).padStart(2, '0');
      const eyebrow = card.querySelector('.invoice-item-heading__text .eyebrow');
      if (eyebrow) eyebrow.textContent = `Item ${index + 1} · ${sourceField?.value === 'xml' ? 'do XML' : 'manual'}`;
    });
    const totalNode = editor.querySelector('[data-review-items-total]');
    if (totalNode) totalNode.textContent = money(itemTotal);
    const includedNode = editor.querySelector('[data-review-included]');
    if (includedNode) includedNode.textContent = String(included);
    const skippedNode = editor.querySelector('[data-review-skipped]');
    if (skippedNode) skippedNode.textContent = String(skipped);
    const empty = editor.querySelector('[data-empty-items]');
    if (empty) empty.classList.toggle('is-empty', rows.length > 0);
    const bulkPanel = editor.querySelector('[data-bulk-apply]');
    if (bulkPanel) bulkPanel.hidden = rows.length === 0;

    const reviewList = editor.querySelector('[data-review-items]');
    if (reviewList) {
      reviewList.replaceChildren();
      if (!rows.length) {
        const message = document.createElement('p');
        message.className = 'hint';
        message.textContent = 'Nenhum item para revisar.';
        reviewList.append(message);
      }
      rows.forEach(card => {
        const get = field => card.querySelector(`[data-item-field="${field}"]`)?.value ?? '';
        const skippedItem = get('classification') === 'skip';
        const classification = get('classification');
        const row = document.createElement('div');
        row.className = 'invoice-review-item';
        const main = document.createElement('div');
        const name = document.createElement('strong');
        name.textContent = text(get('name')) || 'Item sem nome padronizado';
        const original = document.createElement('span');
        original.textContent = text(get('originalDescription')) || 'Descrição original não informada';
        const fiscal = document.createElement('span');
        fiscal.textContent = `${text(get('quantity'))} ${text(get('unit'))} · unitário ${money(parseNumber(get('unitValue')))} · NCM ${text(get('ncm')) || '—'} · CFOP ${text(get('cfop')) || '—'}`;
        main.append(name, original, fiscal);
        const data = document.createElement('div');
        data.className = 'invoice-review-item__data';
        const badge = document.createElement('span');
        badge.className = `badge${skippedItem ? ' amber' : ''}`;
        badge.textContent = skippedItem ? 'Não incorporar' : classification === 'small' ? 'Pequeno valor' : 'Ativo fixo';
        data.append(badge);
        if (!skippedItem) {
          const category = document.createElement('span'); category.textContent = text(get('category')) || 'Conta patrimonial pendente';
          const location = document.createElement('span'); location.textContent = text(get('location')) || 'Local pendente';
          const patrimony = text(get('patrimony')).split(/[,/\n]+/).map(value => value.trim()).filter(Boolean).length;
          const codes = document.createElement('span'); codes.textContent = `${patrimony} patrimônio(s)`;
          data.append(category, location, codes);
        }
        const amount = document.createElement('strong');
        amount.className = 'money';
        amount.textContent = money(parseNumber(get('total')));
        row.append(main, data, amount);
        reviewList.append(row);
      });
    }
  }

  if (editor && form) {
    let activeStep = Math.max(1, Math.min(3, Number(editor.dataset.activeStep) || 1));
    const stepButtons = [...editor.querySelectorAll('[data-step-target]')];
    const showStep = step => {
      activeStep = Math.max(1, Math.min(3, step));
      editor.dataset.activeStep = String(activeStep);
      editor.querySelectorAll('[data-step-panel]').forEach(panel => { panel.hidden = Number(panel.dataset.stepPanel) !== activeStep; });
      stepButtons.forEach(button => {
        const value = Number(button.dataset.stepTarget);
        button.classList.toggle('is-active', value === activeStep);
        button.classList.toggle('is-complete', value < activeStep);
        button.setAttribute('aria-current', value === activeStep ? 'step' : 'false');
        const number = button.querySelector('.invoice-step__number');
        if (number) number.textContent = value < activeStep ? '✓' : String(value);
      });
      const current = editor.querySelector('[data-current-step]');
      if (current) current.value = String(activeStep);
      editor.querySelectorAll('[data-step-next]').forEach(button => { button.hidden = activeStep >= 3; });
      editor.querySelectorAll('[data-step-back]').forEach(button => { button.hidden = activeStep <= 1; });
      editor.querySelectorAll('[data-register], [data-register-hint]').forEach(button => { button.hidden = activeStep !== 3; });
      updateReview();
    };
    stepButtons.forEach(button => button.addEventListener('click', () => showStep(Number(button.dataset.stepTarget))));
    editor.querySelector('[data-step-next]')?.addEventListener('click', () => showStep(activeStep + 1));
    editor.querySelector('[data-step-back]')?.addEventListener('click', () => showStep(activeStep - 1));
    editor.querySelector('[data-set-invoice-source="manual"]')?.addEventListener('click', () => setSource('manual'));

    if (list && template) {
      const add = editor.querySelector('[data-add-item]');
      add?.addEventListener('click', () => {
        const html = template.innerHTML.replaceAll('__INDEX__', String(nextIndex)).replaceAll('__NUMBER__', String(nextIndex + 1).padStart(2, '0'));
        nextIndex += 1;
        list.insertAdjacentHTML('beforeend', html);
        updateReview();
      });
      document.addEventListener('click', event => {
        const button = event.target.closest('[data-remove-item]');
        if (!button || !list?.contains(button)) return;
        button.closest('[data-item-card]')?.remove();
        updateReview();
      });
    }

    editor.querySelector('[data-apply-category]')?.addEventListener('click', () => {
      const value = editor.querySelector('[data-bulk-category]')?.value ?? '';
      if (!value) return;
      itemRows().forEach(card => {
        if (card.querySelector('[data-item-field="classification"]')?.value !== 'skip') {
          const field = card.querySelector('[data-item-field="category"]');
          if (field) field.value = value;
        }
      });
      updateReview();
    });
    editor.querySelector('[data-apply-location]')?.addEventListener('click', () => {
      const value = editor.querySelector('[data-bulk-location]')?.value ?? '';
      if (!value) return;
      itemRows().forEach(card => {
        if (card.querySelector('[data-item-field="classification"]')?.value !== 'skip') {
          const field = card.querySelector('[data-item-field="location"]');
          if (field) field.value = value;
        }
      });
      updateReview();
    });

    form.addEventListener('input', event => {
      const target = event.target;
      if (target.matches('[data-item-field="quantity"], [data-item-field="unitValue"]') && sourceField?.value !== 'xml') {
        const card = target.closest('[data-item-card]');
        const quantity = parseNumber(card?.querySelector('[data-item-field="quantity"]')?.value);
        const unitValue = parseNumber(card?.querySelector('[data-item-field="unitValue"]')?.value);
        const total = card?.querySelector('[data-item-total]');
        const display = card?.querySelector('[data-item-total-display]');
        if (total) total.value = (quantity * unitValue).toFixed(2);
        if (display) display.textContent = money(quantity * unitValue);
      }
      if (target.matches('[data-item-field]')) updateItemCard(target.closest('[data-item-card]'));
      updateReview();
    });
    form.addEventListener('change', event => {
      if (event.target.matches('[data-item-field="classification"]')) updateItemCard(event.target.closest('[data-item-card]'));
      updateReview();
    });

    const fileInput = editor.querySelector('[data-xml-file]');
    const dropzone = editor.querySelector('[data-xml-dropzone]');
    const fileName = editor.querySelector('[data-xml-file-name]');
    const fileError = editor.querySelector('[data-xml-error]');
    const importButton = editor.querySelector('[data-import-xml]');
    const displayFileError = message => {
      if (fileError) { fileError.textContent = message; fileError.hidden = false; }
    };
    const validateFile = file => {
      if (!file) return 'Selecione um arquivo XML da NF-e.';
      if (!file.name.toLowerCase().endsWith('.xml') && !file.type.toLowerCase().includes('xml')) return 'Selecione um arquivo XML da NF-e.';
      if (file.size > 2 * 1024 * 1024) return 'O XML excede o limite de 2 MB.';
      return '';
    };
    const submitXml = () => {
      const file = fileInput?.files?.[0];
      const error = validateFile(file);
      if (error) { displayFileError(error); return; }
      if (fileError) fileError.hidden = true;
      if (fileName) fileName.textContent = file.name;
      if (importButton && typeof form.requestSubmit === 'function') form.requestSubmit(importButton);
      else importButton?.click();
    };
    fileInput?.addEventListener('change', () => {
      const file = fileInput.files?.[0];
      if (!file) return;
      const error = validateFile(file);
      if (error) {
        displayFileError(error);
        fileInput.value = '';
        if (fileName) fileName.textContent = 'Clique para selecionar ou arraste o XML';
        return;
      }
      if (fileName) fileName.textContent = file.name;
      if (fileError) fileError.hidden = true;
      window.setTimeout(submitXml, 80);
    });
    importButton?.addEventListener('click', event => {
      const error = validateFile(fileInput?.files?.[0]);
      if (error) { event.preventDefault(); displayFileError(error); }
    });
    ['dragenter', 'dragover'].forEach(type => dropzone?.addEventListener(type, event => {
      event.preventDefault();
      dropzone.classList.add('is-dragging');
    }));
    ['dragleave', 'drop'].forEach(type => dropzone?.addEventListener(type, event => {
      event.preventDefault();
      dropzone.classList.remove('is-dragging');
    }));
    dropzone?.addEventListener('drop', event => {
      const file = event.dataTransfer?.files?.[0];
      if (!file || !fileInput) return;
      const transfer = new DataTransfer();
      transfer.items.add(file);
      fileInput.files = transfer.files;
      fileInput.dispatchEvent(new Event('change', { bubbles: true }));
    });

    setSource(sourceField?.value || 'manual');
    showStep(activeStep);
  }

  document.querySelectorAll('input[type="file"][name="xml_file"]:not([data-xml-file])').forEach(input => input.addEventListener('change', () => {
    const file = input.files?.[0];
    if (file && file.size > 2 * 1024 * 1024) {
      window.alert('O XML ultrapassa o limite de 2 MB. Escolha um arquivo menor.');
      input.value = '';
    }
  }));
});


(() => {
  const closeCatalogEditor = (row, restoreFocus = false) => {
    const form = row?.querySelector('[data-catalog-edit-form]');
    const display = row?.querySelector('[data-catalog-edit-display]');
    const opener = row?.querySelector('[data-catalog-edit-open]');
    const input = form?.querySelector('input[name="new_value"]');
    if (!form || !display || !opener) return;
    if (input && form.dataset.originalValue !== undefined) input.value = form.dataset.originalValue;
    form.hidden = true;
    display.hidden = false;
    opener.setAttribute('aria-expanded', 'false');
    if (restoreFocus) opener.focus();
  };

  document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    const message = form.dataset.confirmSubmit;
    if (message && !window.confirm(message)) event.preventDefault();
  });

  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    const edit = target?.closest('[data-catalog-edit-open]');
    if (edit) {
      const row = edit.closest('[data-catalog-edit-row]');
      const form = row?.querySelector('[data-catalog-edit-form]');
      const display = row?.querySelector('[data-catalog-edit-display]');
      const input = form?.querySelector('input[name="new_value"]');
      if (!row || !form || !display || !input) return;
      document.querySelectorAll('[data-catalog-edit-form]:not([hidden])').forEach(openForm => {
        if (openForm !== form) closeCatalogEditor(openForm.closest('[data-catalog-edit-row]'));
      });
      display.hidden = true;
      form.hidden = false;
      edit.setAttribute('aria-expanded', 'true');
      input.focus();
      input.select();
      return;
    }

    const cancel = target?.closest('[data-catalog-edit-cancel]');
    if (cancel) closeCatalogEditor(cancel.closest('[data-catalog-edit-row]'), true);
  });
})();

(() => {
  // Editar ativo: "+ Nova conta patrimonial…" revela o campo do nome novo.
  document.querySelectorAll('[data-category-select]').forEach(select => {
    const wrap = select.closest('form')?.querySelector('[data-category-new]');
    const input = wrap?.querySelector('input');
    if (!wrap || !input) return;
    const sync = (focus) => {
      const isNew = select.value === '__new__';
      wrap.hidden = !isNew;
      input.disabled = !isNew;
      input.required = isNew;
      if (isNew && focus) input.focus();
    };
    select.addEventListener('change', () => sync(true));
    sync(false);
  });
})();

// Lista de ativos: o ícone Excluir abre uma janela que pede o motivo.
// Sem suporte a <dialog>, o link segue para a ficha do ativo, onde está o mesmo formulário.
(() => {
  const dialog = document.querySelector('[data-delete-dialog]');
  if (!dialog || typeof dialog.showModal !== 'function') return;
  const idField = dialog.querySelector('[data-delete-id]');
  const nameLabel = dialog.querySelector('[data-delete-name]');
  const reason = dialog.querySelector('textarea[name="reason"]');
  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-delete-asset]');
    if (trigger) {
      event.preventDefault();
      idField.value = trigger.dataset.deleteAsset;
      nameLabel.textContent = trigger.dataset.assetName || '';
      reason.value = '';
      dialog.showModal();
      reason.focus();
      return;
    }
    if (event.target.closest('[data-delete-cancel]') || event.target === dialog) dialog.close();
  });
})();

