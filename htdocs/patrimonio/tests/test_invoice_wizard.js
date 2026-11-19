const assert = require('node:assert/strict');
const fs = require('node:fs');

const js = fs.readFileSync(require('node:path').join(__dirname, '..', 'assets', 'app.js'), 'utf8');
const template = fs.readFileSync(require('node:path').join(__dirname, '..', 'templates', 'invoice-form.php'), 'utf8');
const list = fs.readFileSync(require('node:path').join(__dirname, '..', 'templates', 'invoices.php'), 'utf8');
const layout = fs.readFileSync(require('node:path').join(__dirname, '..', 'templates', 'layout.php'), 'utf8');
let checks = 0;
const check = (condition, message) => {
  assert.ok(condition, message);
  checks += 1;
  console.log(`OK - ${message}`);
};

check(['Dados da nota', 'Classificação dos itens', 'Etapa 3 · Conferência'].every(text => template.includes(text)), 'template declara as três etapas do editor');
check(template.includes('data-xml-dropzone') && template.includes('data-xml-file') && js.includes("file.size > 2 * 1024 * 1024"), 'dropzone escolhe arquivos XML e rejeita arquivos acima de 2 MB');
check(js.includes("form.requestSubmit(importButton)") && js.includes("data-xml-file"), 'selecionar ou soltar XML aciona a análise pelo servidor');
check(template.includes('data-xml-optional') && template.includes('data-xml-optional-note') && template.includes('data-manual-note') && js.includes("querySelectorAll('[data-xml-optional]')") && js.includes('manualNote.hidden = imported'), 'rótulos e avisos opcionais acompanham a escolha XML/manual');
check(layout.includes("asset_url('app.js')"), 'layout invalida o JavaScript para aplicar a alternância de rótulos, o editor do catálogo e a confirmação de exclusão');
check(js.includes("['name', 'category', 'location', 'patrimony'") , 'itens “Não incorporar” mantêm campos patrimoniais somente leitura');
check(js.includes('preserved.name = field.name') && js.includes('preserved.value = field.value'), 'campos select desabilitados de itens ignorados são preservados no POST');
check(js.includes("data-apply-category") && js.includes("data-apply-location") && js.includes("!== 'skip'"), 'aplicação rápida pula itens marcados para não incorporar');
check(js.includes('(quantity * unitValue).toFixed(2)') && js.includes('data-item-total-display'), 'total manual é calculado por quantidade × valor unitário');
check(js.includes('data-review-items-total') && js.includes("text(get('ncm'))") && js.includes("text(get('cfop'))"), 'revisão resume totais e detalhes fiscais dos itens');
check(['Todas as notas', 'Rascunhos', 'Incorporadas'].every(text => list.includes(text)), 'lista da NF-e mantém as três abas de situação');
console.log(`${checks} verificações estáticas passaram.`);
