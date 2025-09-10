<?php
require 'db.php';
require_once 'includes/affiliate_tracker.php';

// Log de entrada
$input = file_get_contents('php://input');
file_put_contents('webhook_log.txt', date('[Y-m-d H:i:s] ') . "Webhook recebido: $input\n", FILE_APPEND);

$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$transaction_id = $data['transaction_id'] ?? '';
$external_id = $data['external_id'] ?? '';
$status = $data['status'] ?? '';
$amount = floatval($data['amount'] ?? 0);

// Log do webhook
$stmt = $pdo->prepare("INSERT INTO webhook_logs (transaction_id, external_id, payload, status) VALUES (?, ?, ?, 'processed')");
$stmt->execute([$transaction_id, $external_id, $input]);

if ($status === 'completed') {
    try {
        // Buscar transação
        $stmt = $pdo->prepare("SELECT * FROM transacoes_pix WHERE external_id = ? AND status = 'pendente'");
        $stmt->execute([$external_id]);
        $transacao = $stmt->fetch();
        
        if ($transacao) {
            // Atualizar status da transação
            $stmt = $pdo->prepare("UPDATE transacoes_pix SET status = 'aprovado', transaction_id = ? WHERE id = ?");
            $stmt->execute([$transaction_id, $transacao['id']]);
            
            // Buscar configurações
            $config = $pdo->query("SELECT bonus_deposito, rollover_multiplicador FROM configuracoes LIMIT 1")->fetch();
            $bonus_percentual = floatval($config['bonus_deposito'] ?? 0);
            $rollover_multiplicador = floatval($config['rollover_multiplicador'] ?? 2);
            
            // Calcular bônus
            $bonus = ($amount * $bonus_percentual) / 100;
            $valor_total = $amount + $bonus;
            
            // Creditar saldo
            $stmt = $pdo->prepare("UPDATE usuarios SET saldo = saldo + ? WHERE id = ?");
            $stmt->execute([$valor_total, $transacao['usuario_id']]);
            
            // Processar comissão de afiliado (primeiro depósito)
            try {
                $affiliateProcessed = processAffiliateFirstDeposit($pdo, $transacao['usuario_id'], $transaction_id, $amount);
                if ($affiliateProcessed) {
                    file_put_contents('webhook_log.txt', date('[Y-m-d H:i:s] ') . "Comissão de afiliado processada para usuário {$transacao['usuario_id']}\n", FILE_APPEND);
                }
            } catch (Exception $e) {
                file_put_contents('webhook_log.txt', date('[Y-m-d H:i:s] ') . "Erro ao processar comissão de afiliado: " . $e->getMessage() . "\n", FILE_APPEND);
            }
            
            // Criar rollover se houver bônus
            if ($bonus > 0) {
                $valor_rollover = $valor_total * $rollover_multiplicador;
                $stmt = $pdo->prepare("
                    INSERT INTO rollover (usuario_id, valor_deposito, valor_necessario, valor_acumulado, finalizado) 
                    VALUES (?, ?, ?, 0, 0)
                ");
                $stmt->execute([$transacao['usuario_id'], $valor_total, $valor_rollover]);
            }
            
            file_put_contents('webhook_log.txt', date('[Y-m-d H:i:s] ') . "Transação aprovada: $external_id - Valor: $amount - Bônus: $bonus\n", FILE_APPEND);
        }
    } catch (PDOException $e) {
        file_put_contents('webhook_log.txt', date('[Y-m-d H:i:s] ') . "Erro: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

http_response_code(200);
echo json_encode(['status' => 'success']);
?>