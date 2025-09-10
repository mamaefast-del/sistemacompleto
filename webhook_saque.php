<?php
require 'db.php';
header('Content-Type: application/json; charset=utf-8');

// ============ Log básico ============
file_put_contents("log_webhook_saque_expfypay.txt", date('[Y-m-d H:i:s] ') . "Método: " . $_SERVER['REQUEST_METHOD'] . PHP_EOL, FILE_APPEND);
file_put_contents("log_webhook_saque_expfypay.txt", date('[Y-m-d H:i:s] ') . "Headers: " . print_r(getallheaders(), true) . PHP_EOL, FILE_APPEND);

// Leia o corpo cru para validação de assinatura
$raw = file_get_contents('php://input');
file_put_contents("log_webhook_saque_expfypay.txt", date('[Y-m-d H:i:s] ') . "Recebido (php://input): " . $raw . PHP_EOL, FILE_APPEND);

// Validação de assinatura EXPFY Pay
$signatureRecebida = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
if (empty($signatureRecebida)) {
    file_put_contents("log_webhook_saque_expfypay.txt", date('[Y-m-d H:i:s] ') . "ERRO: Header X-Signature não encontrado" . PHP_EOL, FILE_APPEND);
    http_response_code(401);
    echo json_encode(['error' => 'Assinatura não fornecida']);
    exit;
}

// Buscar secret key do gateway ativo
$stmt = $pdo->prepare("SELECT client_secret FROM gateways WHERE nome='expfypay' AND ativo=1 LIMIT 1");
$stmt->execute();
$gateway = $stmt->fetch();

if (!$gateway) {
    file_put_contents("log_webhook_saque_expfypay.txt", date('[Y-m-d H:i:s] ') . "ERRO: Gateway EXPFY Pay não encontrado ou inativo" . PHP_EOL, FILE_APPEND);
    http_response_code(500);
    echo json_encode(['error' => 'Gateway não configurado']);
    exit;
}

$secretKey = $gateway['client_secret'];
$assinaturaEsperada = hash_hmac('sha256', $raw, $secretKey);

if (!hash_equals($assinaturaEsperada, $signatureRecebida)) {
    file_put_contents("log_webhook_saque_expfypay.txt", date('[Y-m-d H:i:s] ') . "ERRO: Assinatura inválida" . PHP_EOL, FILE_APPEND);
    http_response_code(401);
    echo json_encode(['error' => 'Assinatura inválida']);
    exit;
}

// Decodificar payload
$data = json_decode($raw, true);

if (!$data) {
    file_put_contents("log_webhook_saque_expfypay.txt", date('[Y-m-d H:i:s] ') . "ERRO: Payload JSON inválido" . PHP_EOL, FILE_APPEND);
    http_response_code(400);
    echo json_encode(['error' => 'Payload inválido']);
    exit;
}

// Verificar campos obrigatórios
if (!isset($data['event']) || !isset($data['transaction_id'])) {
    file_put_contents("log_webhook_saque_expfypay.txt", date('[Y-m-d H:i:s] ') . "ERRO: Campos obrigatórios ausentes" . PHP_EOL, FILE_APPEND);
    http_response_code(400);
    echo json_encode(['error' => 'Campos obrigatórios ausentes']);
    exit;
}

$event = $data['event'];
$transaction_id = $data['transaction_id'];
$status = $data['status'] ?? '';
$amount = floatval($data['amount'] ?? 0);

file_put_contents("log_webhook_saque_expfypay.txt", date('[Y-m-d H:i:s] ') . "Evento: $event, Transaction: $transaction_id, Status: $status" . PHP_EOL, FILE_APPEND);

// Processar eventos de saque
if ($event === 'withdrawl.completed' && $status === 'completed') {
    
    // Busca saque pendente
    $stmt = $pdo->prepare("SELECT * FROM saques WHERE transaction_id = ? AND status = 'pendente' LIMIT 1");
    $stmt->execute([$transaction_id]);
    $saque = $stmt->fetch();

    if ($saque) {
        file_put_contents("log_webhook_saque_expfypay.txt", date('[Y-m-d H:i:s] ') . "Saque pendente encontrado: ID {$saque['id']}" . PHP_EOL, FILE_APPEND);

        // Atualiza status para aprovado
        $stmtUp = $pdo->prepare("UPDATE saques SET status = 'aprovado' WHERE id = ?");
        $stmtUp->execute([$saque['id']]);

        file_put_contents("log_webhook_saque_expfypay.txt", date('[Y-m-d H:i:s] ') . "SUCESSO: Saque processado - Usuário: {$saque['usuario_id']}, Valor: $amount" . PHP_EOL, FILE_APPEND);
        
        echo json_encode(['status' => 'success', 'message' => 'Saque processado com sucesso']);
    } else {
        file_put_contents("log_webhook_saque_expfypay.txt", date('[Y-m-d H:i:s] ') . "AVISO: Saque não encontrado ou já processado: $transaction_id" . PHP_EOL, FILE_APPEND);
        echo json_encode(['status' => 'warning', 'message' => 'Saque não encontrado']);
    }
} elseif ($event === 'withdrawl.processing') {
    file_put_contents("log_webhook_saque_expfypay.txt", date('[Y-m-d H:i:s] ') . "INFO: Saque em processamento - $transaction_id" . PHP_EOL, FILE_APPEND);
    echo json_encode(['status' => 'processing', 'message' => 'Saque em processamento']);
} else {
    file_put_contents("log_webhook_saque_expfypay.txt", date('[Y-m-d H:i:s] ') . "INFO: Evento ignorado - $event" . PHP_EOL, FILE_APPEND);
    echo json_encode(['status' => 'ignored', 'message' => 'Evento ignorado']);
}
?>
