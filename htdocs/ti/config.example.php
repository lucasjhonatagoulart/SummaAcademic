<?php
/*
 * CONFIGURAÇÃO DO PARQUE DE TI
 * Copie este arquivo para config.php (mesma pasta) e preencha com os dados do banco
 * criado no painel da InfinityFree (Painel > MySQL Databases). O config.php não vai para o repositório.
 */
$CONFIG = [
    // Servidor MySQL: aparece no painel como "MySQL Hostname", algo como sql123.infinityfree.com
    'db_host' => 'sqlXXX.infinityfree.com',
    // Nome do banco, algo como if0_12345678_parque
    'db_name' => 'if0_XXXXXXXX_parque',
    // Usuário do MySQL, algo como if0_12345678
    'db_user' => 'if0_XXXXXXXX',
    // Senha do MySQL (a mesma do painel "vPanel" da conta de hospedagem)
    'db_pass' => 'SUA_SENHA_AQUI',

    // Não altere: "mysql" na hospedagem.
    'db_driver' => 'mysql',

    // Fuso horário usado nos registros.
    'timezone' => 'America/Sao_Paulo',
];
