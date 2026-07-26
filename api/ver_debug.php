<?php
/**
 * ver_debug.php — TEMPORÁRIO. Mostra o último payload bruto recebido no webhook.
 * Apague este arquivo depois de terminar o diagnóstico.
 *
 * Acesse: https://ajude-seven.vercel.app/api/ver_debug.php
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/transaction_store.php';

try {
    $data = (new TransactionStore())->carregar('debug_last_webhook');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => $e->getMessage()]);
    exit;
}

if (empty($data)) {
    echo json_encode(['msg' => 'Nenhum webhook recebido ainda.']);
    exit;
}

echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
