<?php
/**
 * webhook_pix.php — Recebe notificações do BASSPAGO quando um PIX é pago.
 *
 * Configure esta URL no painel do BASSPAGO:
 *   PUT /webhook/{chave}  →  webhookUrl: "https://ajude-seven.vercel.app/api/webhook_pix.php"
 *
 * Payload esperado (padrão Bacen):
 * {
 *   "pix": [
 *     {
 *       "endToEndId": "E1234...",
 *       "txid":       "cd1fe328...",
 *       "valor":      "100.00",
 *       "horario":    "2024-01-01T12:00:00.000Z",
 *       "infoPagador": "..."
 *     }
 *   ]
 * }
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);

require_once __DIR__ . '/tracker.php';
require_once __DIR__ . '/transaction_store.php';

header('Content-Type: application/json; charset=utf-8');

// Só aceita POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$raw = file_get_contents('php://input');

// ── DEBUG: salva o payload bruto no Upstash para inspeção manual ─────────────
// Acesse /api/ver_debug.php depois de um pagamento para ver exatamente o que
// o BASSPAGO enviou. Remova este bloco (e o ver_debug.php) depois de resolver.
try {
    (new TransactionStore())->salvar('debug_last_webhook', [
        'recebidoEm' => date('Y-m-d H:i:s'),
        'raw'        => $raw,
    ]);
} catch (Throwable $e) {
    // ignora falha de debug, não deve travar o processamento real
}

$payload = json_decode($raw, true);

if (empty($payload['pix']) || !is_array($payload['pix'])) {
    http_response_code(200); // Responde 200 para não gerar retry desnecessário
    echo json_encode(['ok' => false, 'msg' => 'Nenhum pix no payload']);
    exit;
}

$tracker = new Tracker();
$store   = new TransactionStore();

foreach ($payload['pix'] as $pix) {
    $txid = $pix['txid'] ?? null;
    if (!$txid) continue;

    // Carrega dados da transação salva na criação do QR Code
    try {
        $txData = $store->carregar($txid);
    } catch (Throwable $e) {
        $txData = [];
    }

    // Idempotência: ignora se já processado
    if (($txData['status'] ?? '') === 'paid') continue;

    $data = array_merge($txData, [
        'txid'   => $txid,
        'valor'  => $pix['valor']   ?? ($txData['valor']  ?? '0.00'),
        'paidAt' => isset($pix['horario'])
            ? date('Y-m-d H:i:s', strtotime($pix['horario']))
            : date('Y-m-d H:i:s'),
        'endToEndId' => $pix['endToEndId'] ?? null,
    ]);

    $tracker->purchase($data);

    // Marca transação como paga
    try {
        $store->salvar($txid, array_merge($txData, ['status' => 'paid', 'paidAt' => $data['paidAt']]));
    } catch (Throwable $e) {
        // segue mesmo assim
    }
}

http_response_code(200);
echo json_encode(['ok' => true]);
