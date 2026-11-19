<?php
// Testes das regras de negócio: php tests/run.php
define('ACADEMUS_TEST', true);
require __DIR__ . '/../htdocs/app/perms.php';
require __DIR__ . '/../htdocs/app/domain.php';
$f = 0; $n = 0;
function t(string $name, bool $ok) { global $f, $n; $n++; if (!$ok) { $f++; echo "FALHOU: $name\n"; } }
t('média ponderada', abs(weighted_average([['score' => 8, 'weight' => 2], ['score' => 5, 'weight' => 1]]) - 7.0) < 0.001);
t('média sem notas', weighted_average([]) === null);
t('CPF válido', valid_cpf('52998224725'));
t('CPF inválido', !valid_cpf('11111111111'));
t('CNPJ válido', valid_cnpj('11222333000181'));
t('dinheiro 1.234,56', parse_money('1.234,56') === 123456);
t('dinheiro inválido', parse_money('abc') === null);
t('conflito de horário', slots_overlap(['weekday' => 1, 'start_time' => '19:00', 'end_time' => '20:40'], ['weekday' => 1, 'start_time' => '20:00', 'end_time' => '21:00']));
t('sem conflito em dias diferentes', !slots_overlap(['weekday' => 1, 'start_time' => '19:00', 'end_time' => '20:40'], ['weekday' => 2, 'start_time' => '19:00', 'end_time' => '20:40']));
t('encostado não conflita', !slots_overlap(['weekday' => 1, 'start_time' => '19:00', 'end_time' => '20:40'], ['weekday' => 1, 'start_time' => '20:40', 'end_time' => '22:20']));
t('senha fraca recusada', validate_new_password('abc', 'x', 'a@b.c', 'abc') !== null);
t('senha com o e-mail recusada', validate_new_password('joao2026xx', '', 'joao@x.com', 'joao2026xx') !== null);
t('senha forte aceita', validate_new_password('NovaSenha@2026', 'Antiga123', 'joao@x.com', 'NovaSenha@2026') === null);
t('senha provisória forte', validate_new_password($p = gen_temp_password(), '', 'joao@x.com', $p) === null);
t('código do histórico', (bool) preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', transcript_code()));
t('cobrança vencida', charge_state(['status' => 'ABERTA', 'due_on' => '2026-01-10'], '2026-02-01') === 'VENCIDA');
t('cobrança paga', charge_state(['status' => 'PAGA', 'due_on' => '2026-01-10'], '2026-02-01') === 'PAGA');
t('rateio 250 em 3 turmas soma 250', array_sum(split_cents(25000, 3)) === 25000 && split_cents(25000, 3) === [8334, 8333, 8333]);
t('rateio turma única', split_cents(25000, 1) === [25000]);
t('valor por hora-aula', lesson_value(9000, 'HORA', 2) === 18000 && lesson_value(25000, 'AULA', 2) === 25000);
// Compras
t('código da solicitação', purchase_code(7) === 'SC-0007' && purchase_code(12345) === 'SC-12345');
t('total da cotação com frete', purchase_quote_total([1 => 8, 2 => 2], [1 => 219900, 2 => 5900], 3000) === 8 * 219900 + 2 * 5900 + 3000);
t('item sem preço conta zero', purchase_quote_total([1 => 3], [], 0) === 0);
t('menor cotação', purchase_cheapest([['id' => 4, 'total_cents' => 500], ['id' => 5, 'total_cents' => 400], ['id' => 6, 'total_cents' => 400]]) === 5);
t('sem cotações', purchase_cheapest([]) === null);
t('pagamento no cartão mostra parcelas', purchase_payment_label('CARTAO', 10) === 'Cartão de crédito · 10x' && purchase_payment_label('CARTAO', 1) === 'Cartão de crédito · à vista' && purchase_payment_label('PIX', 3) === 'Pix');
t('local Outro usa o nome informado', purchase_place_label('Outro', ' Papelaria Central ') === 'Papelaria Central' && purchase_place_label('Magalu', 'x') === 'Magalu');
t('passos do andamento', purchase_step('ENVIADA') === 0 && purchase_step('APROVACAO') === 2 && purchase_step('RECEBIDA') === 5);
$ok = ['title' => 'Notebooks do laboratório', 'sector' => 'TI', 'justification' => 'Os computadores atuais não rodam os programas.', 'needed_by' => '', 'priority' => 'NORMAL', 'place' => 'Mercado Livre', 'place_other' => '', 'link' => 'https://exemplo.com/p', 'payment' => 'CARTAO', 'installments' => 10];
$it = [['description' => 'Notebook', 'quantity' => 8, 'unit' => 'un.', 'est_unit_cents' => 230000, 'patrimonial' => 1]];
t('solicitação válida', validate_purchase_request($ok, $it, '2026-11-19') === null);
t('cartão acima de 12x recusado', validate_purchase_request(['installments' => 13] + $ok, $it, '2026-11-19') !== null);
t('Outro sem nome recusado', validate_purchase_request(['place' => 'Outro', 'place_other' => ''] + $ok, $it, '2026-11-19') !== null);
t('local fora da lista recusado', validate_purchase_request(['place' => 'Loja X'] + $ok, $it, '2026-11-19') !== null);
t('link sem http recusado', validate_purchase_request(['link' => 'javascript:alert(1)'] + $ok, $it, '2026-11-19') !== null);
t('data no passado recusada', validate_purchase_request(['needed_by' => '2026-11-01'] + $ok, $it, '2026-11-19') !== null);
t('sem itens recusado', validate_purchase_request($ok, [], '2026-11-19') !== null);
t('quantidade zero recusada', validate_purchase_request($ok, [['quantity' => 0] + $it[0]], '2026-11-19') !== null);
echo "$n testes, $f falhas\n"; exit($f ? 1 : 0);
