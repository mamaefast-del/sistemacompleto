<?php
session_start();
require 'db.php';

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transaction_id = $_POST['transaction_id'] ?? '';
    $external_id = $_POST['external_id'] ?? '';
    $amount = floatval($_POST['amount'] ?? 0);
    
    if (empty($transaction_id) || empty($external_id) || $amount <= 0) {
        $mensagem = 'Dados inválidos para teste.';
        $tipo = 'error';
    } else {
        // Simular payload do webhook
        $payload = json_encode([
            'transaction_id' => $transaction_id,
            'external_id' => $external_id,
            'status' => 'completed',
            'amount' => $amount,
            'updated_at' => date('c')
        ]);
        
        // Buscar secret key do gateway
        $stmt = $pdo->prepare("SELECT client_secret FROM gateways WHERE LOWER(nome) = 'expfypay' AND ativo = 1 LIMIT 1");
        $stmt->execute();
        $gateway = $stmt->fetch();
        
        if (!$gateway) {
            $mensagem = 'Gateway EXPFY Pay não configurado.';
            $tipo = 'error';
        } else {
            // Gerar assinatura
            $signature = hash_hmac('sha256', $payload, $gateway['client_secret']);
            
            // Simular chamada do webhook
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://voy-cloverpg.fun/webhook-pix.php');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-Signature: ' . $signature
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                $mensagem = "Erro cURL: $error";
                $tipo = 'error';
            } else {
                $mensagem = "Teste enviado! HTTP Code: $httpCode, Response: $response";
                $tipo = 'success';
            }
        }
    }
    
    // Redirecionar de volta com mensagem
    header("Location: pix_admin.php?action=test_webhook&msg=" . urlencode($mensagem) . "&type=" . $tipo);
    exit;
}

// Se chegou aqui, é GET - redirecionar para o painel
header('Location: pix_admin.php?action=test_webhook');
exit;
?>
