document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-report-builder]');
  if (form) {
    const fields = Array.from(form.querySelectorAll('[data-report-field]'));
    const selectedCount = form.querySelector('[data-report-selected-count]');
    const orientation = form.querySelector('[data-report-orientation]');
    const orientationHint = form.querySelector('[data-report-orientation-hint]');
    const wideHint = form.querySelector('[data-report-wide-hint]');
    const selectAll = form.querySelector('[data-report-select-all]');
    const clearSelection = form.querySelector('[data-report-clear]');
    const footer = form.querySelector('.report-builder-footer');
    let errorMessage = null;

    const countSelected = () => fields.filter(field => field.checked).length;

    const updateSelectionState = () => {
      const count = countSelected();
      if (selectedCount) selectedCount.textContent = String(count);
      if (orientationHint && orientation) {
        if (orientation.value === 'auto') {
          orientationHint.textContent = count <= 5
            ? 'Automática: retrato até 5 campos; paisagem acima. A prévia permanece em tabela.'
            : 'Automática: paisagem para acomodar as colunas da tabela.';
        } else if (orientation.value === 'portrait' && count > 5) {
          orientationHint.textContent = 'Retrato com muitas colunas reduz a fonte; considere paisagem. O formato continua sendo tabela.';
        } else {
          orientationHint.textContent = `A prévia e a impressão usarão A4 ${orientation.value === 'portrait' ? 'retrato' : 'paisagem'} em tabela.`;
        }
      }
      if (wideHint) wideHint.hidden = count <= 8;
    };

    const removeError = () => {
      if (errorMessage) {
        errorMessage.remove();
        errorMessage = null;
      }
    };

    fields.forEach(field => field.addEventListener('change', () => {
      removeError();
      updateSelectionState();
    }));
    orientation?.addEventListener('change', updateSelectionState);

    selectAll?.addEventListener('click', () => {
      fields.forEach(field => { field.checked = true; });
      removeError();
      updateSelectionState();
    });
    clearSelection?.addEventListener('click', () => {
      fields.forEach(field => { field.checked = false; });
      updateSelectionState();
    });

    form.addEventListener('submit', event => {
      if (countSelected() > 0) return;
      event.preventDefault();
      if (!errorMessage) {
        errorMessage = document.createElement('p');
        errorMessage.className = 'alert error';
        errorMessage.setAttribute('role', 'alert');
        errorMessage.textContent = 'Selecione pelo menos um campo para gerar o relatório.';
        if (footer) form.insertBefore(errorMessage, footer);
        else form.appendChild(errorMessage);
      }
      fields[0]?.focus();
    });

    updateSelectionState();
  }

  const reportTable = document.querySelector('[data-report-table]');
  if (reportTable) {
    const columnCount = reportTable.querySelectorAll('thead th').length;
    reportTable.style.minWidth = `${Math.max(760, columnCount * 110)}px`;
  }

  const printDocument = document.querySelector('[data-report-print-document]');
  if (!printDocument) return;

  const enterPrintMode = () => document.body.classList.add('report-print-mode');
  const leavePrintMode = () => window.setTimeout(() => document.body.classList.remove('report-print-mode'), 0);
  window.addEventListener('beforeprint', enterPrintMode);
  window.addEventListener('afterprint', leavePrintMode);
  document.querySelectorAll('[data-report-print]').forEach(button => {
    button.addEventListener('click', () => {
      enterPrintMode();
      window.print();
    });
  });
});
