<?php
// Copie este arquivo para config.php e substitua os valores no painel do seu provedor.
// Não envie config.php por e-mail público e não o coloque em repositório aberto.
return [
    'db_host' => 'sqlXXX.infinityfree.com',
    'db_name' => 'if0_12345678_ativa',
    'db_user' => 'if0_12345678',
    'db_pass' => 'SUBSTITUA_PELA_SENHA_DO_BANCO',

    // Módulo do Summa (opcional). Com este bloco, o login é feito pelo Summa e o sistema
    // aparece como "Summa Patrimônio". Sem ele, funciona sozinho, como antes.
    // 'secret' precisa ser IGUAL a 'patrimonio_sso_secret' do config.php do Summa
    // (mínimo de 32 caracteres; gere um com: php -r "echo bin2hex(random_bytes(32));").
    // 'url' é o endereço do Summa visto a partir desta pasta: '../' quando o Patrimônio
    // fica em htdocs/patrimonio/.
    // 'summa' => [
    //     'secret' => 'COLE_AQUI_O_MESMO_SEGREDO_DO_SUMMA',
    //     'url' => '../',
    // ],
];
