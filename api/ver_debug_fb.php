<?php
/**
 * ver_debug_fb.php — TEMPORÁRIO. Mostra o resultado da última chamada
 * feita à Conversions API do Facebook (sucesso ou erro).
 * Apague este arquivo depois de terminar o diagnóstico.
 *
 * Acesse: https://ajude-seven.vercel.app/api/ver_debug_fb.php
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/transaction_store.php';

try {
    $data = (new TransactionStore())->carregar('debug_last_fb');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => $e->getMessage()]);
    exit;
}

if (empty($data)) {
    echo json_encode(['msg' => 'Nenhuma chamada ao Facebook registrada ainda.']);
    exit;
}

echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
