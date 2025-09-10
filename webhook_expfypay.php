<?php
/**
 * Webhook ExpfyPay - Processamento de Pagamentos e Comissões de Afiliados
 * Endpoint: /webhook_expfypay.php
 */

require_once 'db.php';
require_once 'includes/affiliate_tracker.php';

// Configurar headers de resposta
header('Content-Type: application/json; charset=utf-8');

// Log detalhado para debug
function logWebhook($message, $data = null) {
    $timestamp = date('[Y-m-d H:i:s]');
    $logEntry = "$timestamp $message";
    if ($data !== null) {
        $logEntry .= "\nData: " . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    $logEntry .= "\n" . str_repeat('-', 80) . "\n";
    file_put_contents('logs/webhook_expfypay.log', $logEntry, FILE_APPEND | LOCK_EX);
}

// Capturar payload bruto
$rawPayload = file_get_contents('php://input');
$headers = getallheaders();

logWebhook("Webhook ExpfyPay recebido", [
    'method' => $_SERVER['REQUEST_METHOD'],
    'headers' => $headers,
    'payload' => $rawPayload
]);

// Validar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logWebhook("Método inválido: " . $_SERVER['REQUEST_METHOD']);
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Validar payload
if (empty($rawPayload)) {
    logWebhook("Payload vazio");
    http_response_code(400);
    echo json_encode(['error' => 'Empty payload']);
    exit;
}

// Decodificar JSON
$payload = json_decode($rawPayload, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logWebhook("JSON inválido: " . json_last_error_msg());
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Validar assinatura
$signature = $headers['X-Signature'] ?? $headers['x-signature'] ?? '';
if (empty($signature)) {
    logWebhook("Assinatura não fornecida");
    http_response_code(401);
    echo json_encode(['error' => 'Missing signature']);
    exit;
}

// Buscar chave secreta do gateway
try {
    $stmt = $pdo->prepare("SELECT client_secret FROM gateways WHERE nome = 'expfypay' AND ativo = 1 LIMIT 1");
    $stmt->execute();
    $gateway = $stmt->fetch();
    
    if (!$gateway) {
        logWebhook("Gateway ExpfyPay não configurado");
        http_response_code(500);
        echo json_encode(['error' => 'Gateway not configured']);
        exit;
    }
    
    $secretKey = $gateway['client_secret'];
    
} catch (PDOException $e) {
    logWebhook("Erro ao buscar gateway: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
    exit;
}

// Verificar assinatura
$expectedSignature = hash_hmac('sha256', $rawPayload, $secretKey);
$signatureValid = hash_equals($expectedSignature, $signature);

if (!$signatureValid) {
    logWebhook("Assinatura inválida", [
        'received' => $signature,
        'expected' => $expectedSignature
    ]);
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

// Extrair dados do payload
$transactionId = $payload['transaction_id'] ?? $payload['id'] ?? '';
$externalId = $payload['external_id'] ?? '';
$status = strtolower($payload['status'] ?? '');
$amount = floatval($payload['amount'] ?? 0);
$event = $payload['event'] ?? 'payment.updated';

logWebhook("Dados extraídos", [
    'transaction_id' => $transactionId,
    'external_id' => $externalId,
    'status' => $status,
    'amount' => $amount,
    'event' => $event
]);

// Validar dados obrigatórios
if (empty($transactionId)) {
    logWebhook("Transaction ID não fornecido");
    http_response_code(400);
    echo json_encode(['error' => 'Missing transaction_id']);
    exit;
}

// Inicializar tracker de afiliados
$affiliateTracker = new AffiliateTracker($pdo);

// Registrar callback (idempotência)
$affiliateTracker->logPaymentCallback('expfypay', $transactionId, $payload, $status, $signatureValid);

// Status de sucesso configuráveis
$successStatuses = ['completed', 'approved', 'paid', 'confirmed', 'success'];

try {
    if (in_array($status, $successStatuses)) {
        logWebhook("Processando pagamento aprovado");
        
        // Buscar transação no banco
        $stmt = $pdo->prepare("
            SELECT * FROM transacoes_pix 
            WHERE transaction_id = ? OR external_id = ?
            ORDER BY criado_em DESC LIMIT 1
        ");
        $stmt->execute([$transactionId, $externalId]);
        $transaction = $stmt->fetch();
        
        if (!$transaction) {
            logWebhook("Transação não encontrada", [
                'transaction_id' => $transactionId,
                'external_id' => $externalId
            ]);
            
            // Tentar buscar apenas por external_id
            $stmt = $pdo->prepare("SELECT * FROM transacoes_pix WHERE external_id = ?");
            $stmt->execute([$externalId]);
            $transaction = $stmt->fetch();
        }
        
        if ($transaction) {
            $userId = $transaction['usuario_id'];
            $transactionAmount = floatval($transaction['valor']);
            
            logWebhook("Transação encontrada", [
                'id' => $transaction['id'],
                'user_id' => $userId,
                'amount' => $transactionAmount,
                'current_status' => $transaction['status']
            ]);
            
            // Verificar se já foi processada
            if ($transaction['status'] === 'aprovado') {
                logWebhook("Transação já processada anteriormente");
                echo json_encode(['status' => 'already_processed']);
                exit;
            }
            
            // Atualizar status da transação
            $stmt = $pdo->prepare("
                UPDATE transacoes_pix 
                SET status = 'aprovado', transaction_id = ? 
                WHERE id = ?
            ");
            $stmt->execute([$transactionId, $transaction['id']]);
            
            // Buscar configurações
            $stmt = $pdo->prepare("SELECT bonus_deposito FROM configuracoes LIMIT 1");
            $stmt->execute();
            $config = $stmt->fetch();
            $bonusPercentual = floatval($config['bonus_deposito'] ?? 0);
            
            // Calcular bônus
            $bonusAmount = ($transactionAmount * $bonusPercentual) / 100;
            $totalAmount = $transactionAmount + $bonusAmount;
            
            // Creditar saldo
            $stmt = $pdo->prepare("UPDATE usuarios SET saldo = saldo + ? WHERE id = ?");
            $stmt->execute([$totalAmount, $userId]);
            
            logWebhook("Saldo creditado", [
                'user_id' => $userId,
                'transaction_amount' => $transactionAmount,
                'bonus_amount' => $bonusAmount,
                'total_credited' => $totalAmount
            ]);
            
            // Processar afiliado (primeiro depósito)
            $affiliateProcessed = $affiliateTracker->processFirstDeposit($userId, $transactionId, $transactionAmount);
            
            if ($affiliateProcessed) {
                logWebhook("Comissão de afiliado processada", [
                    'user_id' => $userId,
                    'transaction_id' => $transactionId,
                    'amount' => $transactionAmount
                ]);
            } else {
                logWebhook("Nenhuma comissão de afiliado aplicável", [
                    'user_id' => $userId,
                    'reason' => 'Usuário não foi indicado ou não é primeiro depósito'
                ]);
            }
            
            // Criar rollover se houver bônus
            if ($bonusAmount > 0) {
                try {
                    $rolloverMultiplier = 2; // Configurável
                    $rolloverRequired = $totalAmount * $rolloverMultiplier;
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO rollover (usuario_id, valor_deposito, valor_necessario, valor_acumulado, finalizado) 
                        VALUES (?, ?, ?, 0, 0)
                    ");
                    $stmt->execute([$userId, $totalAmount, $rolloverRequired]);
                    
                    logWebhook("Rollover criado", [
                        'user_id' => $userId,
                        'required_amount' => $rolloverRequired
                    ]);
                } catch (PDOException $e) {
                    logWebhook("Erro ao criar rollover: " . $e->getMessage());
                }
            }
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Payment processed successfully',
                'transaction_id' => $transactionId,
                'amount_credited' => $totalAmount,
                'affiliate_processed' => $affiliateProcessed
            ]);
            
        } else {
            logWebhook("Transação não encontrada no banco", [
                'transaction_id' => $transactionId,
                'external_id' => $externalId
            ]);
            
            echo json_encode([
                'status' => 'not_found',
                'message' => 'Transaction not found'
            ]);
        }
        
    } else {
        logWebhook("Status não é de sucesso: $status");
        
        // Registrar outros status para auditoria
        echo json_encode([
            'status' => 'acknowledged',
            'message' => 'Status received but not processed',
            'received_status' => $status
        ]);
    }
    
} catch (PDOException $e) {
    logWebhook("Erro de banco de dados: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
} catch (Exception $e) {
    logWebhook("Erro geral: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
?>