<?php
// Carrega configuração, bibliotecas e controladores.
const APP_VERSION = '1.18.0';
date_default_timezone_set('America/Sao_Paulo');
mb_internal_encoding('UTF-8');

$cfgFile = dirname(__DIR__) . '/config.php';
if (!is_file($cfgFile)) { header('Location: install/'); exit; }
define('CONFIG', require $cfgFile);
error_reporting(E_ALL);
ini_set('display_errors', !empty(CONFIG['debug']) ? '1' : '0');
ini_set('log_errors', '1');

foreach (['brand', 'perms', 'domain', 'db', 'modules', 'helpers', 'auth', 'mail', 'ui', 'queries'] as $f) require __DIR__ . "/$f.php";
foreach (glob(__DIR__ . '/c/*.php') as $f) require $f;
migrate();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://unpkg.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data: blob:; form-action 'self'; frame-ancestors 'self'; base-uri 'self'");
