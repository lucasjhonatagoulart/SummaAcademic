<?php

declare(strict_types=1);

// Regressão: a tabela "Ativos recentes" da Visão geral exibe a coluna Patrimônio,
// então a consulta precisa trazer esse campo (antes da 1.8.0 ela sempre mostrava "—").
$repository = (string)file_get_contents(__DIR__ . '/../app/repository.php');
$dashboard = (string)file_get_contents(__DIR__ . '/../templates/dashboard.php');

$failures = 0;
if (!preg_match("/\\\$recent = \\\$pdo->query\\('SELECT ([^']+) FROM assets/", $repository, $match)) {
    fwrite(STDERR, "FAIL consulta de ativos recentes não encontrada\n");
    exit(1);
}
$columns = array_map('trim', explode(',', $match[1]));
foreach (['id', 'name', 'category', 'patrimony', 'value', 'status'] as $column) {
    if (!in_array($column, $columns, true)) {
        fwrite(STDERR, "FAIL ativos recentes sem a coluna {$column}\n");
        $failures++;
    }
}
if (!str_contains($dashboard, "\$row['patrimony']")) {
    fwrite(STDERR, "FAIL a Visão geral não exibe o patrimônio dos ativos recentes\n");
    $failures++;
}
if ($failures) exit(1);
echo "Dashboard recent assets checks passed.\n";
