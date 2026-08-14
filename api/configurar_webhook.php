<?php
/**
 * configurar_webhook.php
 *
 * Registra (ou consulta) o webhook do BASSPAGO para a sua chave PIX.
 *
 * Uso:
 *   https://SEU-DOMINIO/api/configurar_webhook.php            -> registra usando o dominio atual
 *   https://SEU-DOMINIO/api/configurar_webhook.php?ver=1       -> apenas consulta o que esta registrado
 *   https://SEU-DOMINIO/api/configurar_webhook.php?url=https://outro.com/api/webhook_pix.php
 *
 * A URL e montada automaticamente a partir do dominio em que este arquivo esta
 * rodando, evitando o erro de registrar o webhook em um dominio antigo.
 */
​
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');
​
header('Content-Type: application/json; charset=utf-8');
​
require_once __DIR__ . '/pix_api.php';
​
global $CHAVE_PIX;
$chave = trim($CHAVE_PIX ?? '');
​
if (empty($chave)) {
    http_response_code(500);
    echo json_encode(['erro' => 'CHAVE_PIX nao configurada em var.php']);
    exit;
}
​
// Dominio atual (ex.: ajude-a-thais.vercel.app)
$host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
$host = preg_replace('/[^a-zA-Z0-9\.\-:]/', '', (string)$host);
​
$WEBHOOK_URL = !empty($_GET['url'])
    ? trim((string)$_GET['url'])
    : 'https://' . $host . '/api/webhook_pix.php';
​
if (!filter_var($WEBHOOK_URL, FILTER_VALIDATE_URL) || strpos($WEBHOOK_URL, 'https://') !== 0) {
    http_response_code(400);
    echo json_encode(['erro' => 'URL de webhook invalida: ' . $WEBHOOK_URL]);
    exit;
}
​
try {
    $pix = new PixApi();
​
    // Modo consulta: ?ver=1 mostra o webhook atualmente registrado no PSP.
    if (!empty($_GET['ver'])) {
        $atual = $pix->consultarWebhook($chave);
​
        echo json_encode([
            'ok'              => true,
            'modo'            => 'consulta',
            'chave'           => $chave,
            'webhookNoPsp'    => $atual['webhookUrl'] ?? null,
            'webhookEsperado' => $WEBHOOK_URL,
            'confere'         => (($atual['webhookUrl'] ?? null) === $WEBHOOK_URL),
            'resposta'        => $atual,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
​
    $result = $pix->configurarWebhook($chave, $WEBHOOK_URL);
​
    echo json_encode([
        'ok'         => true,
        'modo'       => 'registro',
        'mensagem'   => 'Webhook registrado com sucesso!',
        'chave'      => $chave,
        'webhookUrl' => $result['webhookUrl'] ?? $WEBHOOK_URL,
        'criacao'    => $result['criacao']    ?? null,
        'resposta'   => $result,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
​
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    http_response_code($code >= 400 ? $code : 500);
    echo json_encode([
        'erro'    => $e->getMessage(),
        'arquivo' => basename($e->getFile()) . ':' . $e->getLine(),
    ]);
}
​
