<?php
/**
 * webhook_pix.php — Recebe notificações do BASSPAGO quando um PIX é pago.
 *
 * Configure esta URL no painel do BASSPAGO:
 *   PUT /webhook/{chave}  →  webhookUrl: "https://seudominio.com/apipix/webhook_pix.php"
 *
 * Payload recebido:
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

// Loga o payload bruto para debug — vai para os Logs da Vercel (Functions → Logs),
// já que não há mais disco persistente para gravar um .txt.
error_log('[webhook_pix] ' . $raw);

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
        error_log('[webhook_pix] falha ao carregar transação: ' . $e->getMessage());
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
        error_log('[webhook_pix] falha ao salvar transação paga: ' . $e->getMessage());
    }
}

http_response_code(200);
echo json_encode(['ok' => true]);
