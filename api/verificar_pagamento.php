<?php
/**
 * verificar_pagamento.php
 *
 * Retorna o status de uma transacao PIX pelo txid.
 *
 * GET /verificar_pagamento.php?txid={txid}
 *
 * IMPORTANTE: este endpoint NAO depende mais do webhook do BASSPAGO.
 * Ele consulta a cobranca direto no PSP (GET /cob/{txid}) e, na primeira vez
 * que detecta o pagamento, dispara o evento Purchase (Facebook) e o status
 * "paid" (UTMify). Assim a venda aprovada e marcada mesmo se o webhook falhar.
 */
​
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');
​
header('Content-Type: application/json; charset=utf-8');
​
ob_start();
​
register_shutdown_function(function () {
    $fatal = error_get_last();
    $tiposFatais = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
​
    if ($fatal !== null && in_array($fatal['type'], $tiposFatais, true)) {
        if (ob_get_length() !== false) {
            ob_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'erro' => 'PHP fatal: ' . $fatal['message'],
            'arquivo' => basename((string)$fatal['file']) . ':' . $fatal['line'],
        ]);
    }
​
    if (ob_get_length() !== false) {
        ob_end_flush();
    }
});
​
require_once __DIR__ . '/pix_api.php';
require_once __DIR__ . '/tracker.php';
require_once __DIR__ . '/transaction_store.php';
​
// Sanitiza o txid - apenas alfanumerico
$txid = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['txid'] ?? '');
​
if (empty($txid)) {
    http_response_code(400);
    echo json_encode(['erro' => 'txid invalido.']);
    exit;
}
​
// ---------------------------------------------------------------------------
// 1) Le o que ja foi salvo na criacao do QR Code (UTMs, nome, email, etc.)
// ---------------------------------------------------------------------------
$store    = null;
$txData   = [];
$storeErr = null;
​
try {
    $store  = new TransactionStore();
    $txData = $store->carregar($txid);
} catch (Throwable $e) {
    $storeErr = $e->getMessage();
    error_log('[verificar_pagamento] Upstash indisponivel: ' . $storeErr);
}
​
// Se o webhook (ou uma consulta anterior) ja confirmou, responde na hora.
if (($txData['status'] ?? '') === 'paid') {
    echo json_encode(['txid' => $txid, 'status' => 'paid', 'fonte' => 'cache']);
    exit;
}
​
// ---------------------------------------------------------------------------
// 2) Consulta a cobranca direto no PSP - fonte da verdade
// ---------------------------------------------------------------------------
$statusPsp = null;
$cob       = [];
​
try {
    $cob       = (new PixApi())->consultarCobranca($txid);
    $statusPsp = strtoupper((string)($cob['status'] ?? ''));
} catch (Throwable $e) {
    error_log('[verificar_pagamento] Falha ao consultar PSP txid=' . $txid . ': ' . $e->getMessage());
​
    // Nao derruba o checkout: informa que segue aguardando.
    echo json_encode([
        'txid'   => $txid,
        'status' => $txData['status'] ?? 'waiting_paid',
        'aviso'  => 'Nao foi possivel consultar o PSP agora.',
    ]);
    exit;
}
​
$statusPagos = ['CONCLUIDA', 'CONCLUIDO', 'PAGA', 'PAGO', 'PAID', 'COMPLETED'];
$foiPago     = in_array($statusPsp, $statusPagos, true);
​
// Alguns PSPs devolvem o array "pix" preenchido assim que o pagamento cai,
// mesmo antes de mudar o campo status. Tratamos isso como pago tambem.
if (!$foiPago && !empty($cob['pix']) && is_array($cob['pix'])) {
    $foiPago = true;
}
​
if (!$foiPago) {
    echo json_encode([
        'txid'      => $txid,
        'status'    => 'waiting_paid',
        'statusPsp' => $statusPsp,
    ]);
    exit;
}
​
// ---------------------------------------------------------------------------
// 3) Pago! Marca como pago ANTES de disparar, para nao duplicar o evento
//    caso o navegador faca duas consultas quase simultaneas.
// ---------------------------------------------------------------------------
$infoPix = (!empty($cob['pix']) && is_array($cob['pix'])) ? ($cob['pix'][0] ?? []) : [];
​
$horario = $infoPix['horario'] ?? ($cob['calendario']['criacao'] ?? null);
$paidAt  = $horario ? date('Y-m-d H:i:s', strtotime($horario)) : date('Y-m-d H:i:s');
​
$valorPago = $infoPix['valor']
    ?? ($cob['valor']['original'] ?? ($txData['valor'] ?? '0.00'));
​
$dadosPagos = array_merge($txData, [
    'txid'       => $txid,
    'valor'      => $valorPago,
    'paidAt'     => $paidAt,
    'endToEndId' => $infoPix['endToEndId'] ?? ($txData['endToEndId'] ?? null),
    'nome'       => $infoPix['pagador']['nome'] ?? ($txData['nome'] ?? ''),
    'document'   => $infoPix['pagador']['cpf']  ?? ($txData['document'] ?? ''),
    'status'     => 'paid',
]);
​
$jaDisparado = false;
​
if ($store !== null) {
    try {
        $store->salvar($txid, $dadosPagos);
    } catch (Throwable $e) {
        error_log('[verificar_pagamento] Falha ao marcar paid no Upstash: ' . $e->getMessage());
    }
}
​
// ---------------------------------------------------------------------------
// 4) Dispara Purchase (Facebook) + status paid (UTMify)
// ---------------------------------------------------------------------------
try {
    (new Tracker())->purchase($dadosPagos);
    $jaDisparado = true;
} catch (Throwable $e) {
    error_log('[verificar_pagamento] Falha ao disparar Purchase/UTMify: ' . $e->getMessage());
}
​
echo json_encode([
    'txid'       => $txid,
    'status'     => 'paid',
    'fonte'      => 'psp',
    'valor'      => $valorPago,
    'paidAt'     => $paidAt,
    'disparado'  => $jaDisparado,
]);
​
