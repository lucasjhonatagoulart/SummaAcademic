

## Global header, invoice actions and equal-height catalog sections (2026-11-06)

The MDN Grid Box Alignment guide explains that grid items stretch to fill their grid area by default, while `align-items: start` makes each item follow its own content height; setting `align-items: stretch` therefore pairs the catalog cards to the same row height: <https://developer.mozilla.org/en-US/docs/Web/CSS/Guides/Grid_layout/Box_alignment>. MDN's `grid-template-columns` reference documents responsive track sizing with `repeat()`, `auto-fit`, and `minmax()`: <https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/Properties/grid-template-columns>. Applied to the current UI, the topbar keeps only the theme control, invoice creation/import remains available as one combined action at the bottom of the invoice page, and invoice export appears after the list. Backup restore is moved from the global header into the Control Panel backup section and retains its composite authorization check.


## First-page report heading in print layout (2026-11-06)

The print-specific heading is rendered only in print mode so the on-screen summary remains unchanged. MDN documents using `@media print` for print-only presentation and `break-before: page` to force a later report section onto a new printed page: <https://developer.mozilla.org/en-US/docs/Web/CSS/Guides/Media_queries/Printing> and <https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/Properties/break-before>. The first-page summary now reuses the active report metadata (brand/title, status, classification, acquisition period, generation time, paper orientation and selected-field count; the record total remains in the summary KPI rather than being repeated in the print header), hides the screen-only “Resumo patrimonial” heading for print, and keeps the detailed report on page 2. Removed the wide-column warning sentence from the printable document.


## Hide repeated detailed-report heading in print (2026-11-06)

The detailed report section has its own on-screen metadata heading. In the print-only stylesheet, a direct-child selector on `.report-print-document > .report-print-header` now sets `display: none`, leaving the separate `.report-summary-print-header` visible on page 1. The detailed section's existing `break-before: page` remains unchanged, so item rows still begin on page 2 without repeating the metadata block. MDN documents `@media print` as the place for styles that affect printed output only and gives `display: none` as a way to remove an element and its layout box: <https://developer.mozilla.org/en-US/docs/Web/CSS/Guides/Media_queries/Printing> and <https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/Properties/display>.


## Editing direct assets without an invoice (2026-11-06)

OWASP's Authorization Cheat Sheet says permissions must be checked on every request, including server-side access to a specific object, and that interface-only checks are not sufficient: <https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html>. The existing `edit_assets` guard remains enforced on both the edit route and save POST. PHP's PDO prepared-statement documentation explains that placeholders keep variable values separate from SQL syntax: <https://www.php.net/manual/en/pdo.prepared-statements.php>. The direct-asset editor reuses the allowlisted server-side validation and prepared update for class, category, location, patrimony, acquisition date, quantity, value, purchase year, useful life, brand, type, color, condition and notes. Only records marked with the internal direct-registration source and empty invoice fields synchronize the edited name into `originalDescription`; invoice number and ID remain unchanged, and imported/invoice-linked source descriptions remain preserved.


## Optional Local field when editing an asset (2026-11-06)

MDN documents that the HTML `required` attribute makes a control mandatory before the browser submits the form, so the edit form no longer marks `location` as required: <https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Attributes/required>. OWASP's Input Validation Cheat Sheet calls for server-side field rules, including which fields are required and how missing/empty values are handled: <https://cheatsheetseries.owasp.org/cheatsheets/Input_Validation_Cheat_Sheet.html>. Accordingly, the asset edit handler accepts an empty Local and persists it as an empty value, while still requiring the asset name and Conta Patrimonial. If a Local is supplied, it must still match an allowed catalog entry or the current historical value. The separate direct-registration form and its validation are unchanged; Local remains required when creating a new direct asset.


## Invoice-list actions moved above the list (2026-11-06)

MDN describes Flexbox as a one-dimensional layout model for aligning items and distributing available space, and documents `flex-wrap: wrap` as allowing items to move to additional lines rather than overflow a narrow container: <https://developer.mozilla.org/en-US/docs/Web/CSS/Guides/Flexible_box_layout/Basic_concepts> and <https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/Properties/flex-wrap>. MDN also explains that `aria-label` supplies an accessible name to a named element when appropriate: <https://developer.mozilla.org/en-US/docs/Web/Accessibility/ARIA/Reference/Attributes/aria-label>. The invoice create/import and export actions were moved as a group to the DOM immediately before the invoice-list panel; the accessible group label and independent `edit_invoices`/`export_data` conditions are retained, with the existing wrap behavior used for narrow screens.


## Provided brand assets and responsive sidebar logo (2026-11-09)

MDN documents `alt` as textual replacement for image content and notes that it is announced to assistive-technology users; a linked image's text should describe the linked resource. Source: <https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/img>. MDN documents that `max-width` constrains the used width to the available maximum; the project applies a maximum width and `height:auto` so the supplied SVG wordmark can shrink within the sidebar while preserving its intrinsic proportions. Sources: <https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/Properties/max-width> and <https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/Properties/height>. The five user-provided logo/symbol files are copied unchanged to `assets/brand/`; only the dark SVG is used in the dark sidebar, with the requested alternative text. The light SVG and standalone symbol remain available as project assets.

MDN also recommends `role="img"` on SVG files embedded with `<img>` to address a documented VoiceOver announcement issue; the sidebar logo includes this role alongside the requested `alt`. Source: <https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/img>.

## Responsive preview check (2026-11-09)

MDN explains that responsive images should fit their available container rather than overflow, and that `aspect-ratio: auto` on a replaced element such as `<img>` uses its natural ratio after loading. Sources: <https://developer.mozilla.org/en-US/docs/Web/HTML/Guides/Responsive_images> and <https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/Properties/aspect-ratio>. The local visual check uses the existing layout template and real brand asset at desktop and narrow viewport sizes; the preview lives outside the project and contains no business records.

## Asset classification validation and deletion audit (2026-11-10)

OWASP says authorization must be validated server-side on every request, not only in interface controls: <https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html>. Its Logging Cheat Sheet recommends audit records sufficient to establish when, who and what, including the affected object, action, result and reason; it also distinguishes audit/transaction trails from security-event logging: <https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html>. These sources guide the local implementation: enforce administrative permission on both display and POST, persist the deletion reason and actor as audit data outside the reportable asset fields, and exclude deleted records from normal lists and reports.

## Importação da migração SQL no phpMyAdmin (2026-11-10)

A documentação oficial orienta selecionar a base de dados de destino antes de abrir a aba **Import**. O formulário de upload aceita ficheiros SQL; SQL pode executar alterações na base. Para esta atualização, usar somente `upgrade-asset-deletion-audit.sql`, depois de guardar uma cópia de segurança, e não importar `schema.sql` sobre a base existente, pois esse ficheiro é destinado à instalação nova. Referências oficiais: [documentação phpMyAdmin 5.2.2 — Import and export](https://docs.phpmyadmin.net/en/release_5_2_2/import_export.html) e [documentação phpMyAdmin atual — Import and export](https://docs.phpmyadmin.net/en/latest/import_export.html).
