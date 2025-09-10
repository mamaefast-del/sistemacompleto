<?php
session_start();
require 'db.php';

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    header('Location: login.php');
    exit;
}

// Função para testar webhook
function testarWebhook($transaction_id, $external_id, $amount, $pdo) {
    $payload = json_encode([
        'transaction_id' => $transaction_id,
        'external_id' => $external_id,
        'status' => 'completed',
        'amount' => $amount,
        'updated_at' => date('c')
    ]);
    
    // Buscar secret key
    $stmt = $pdo->prepare("SELECT client_secret FROM gateways WHERE LOWER(nome) = 'expfypay' AND ativo = 1 LIMIT 1");
    $stmt->execute();
    $gateway = $stmt->fetch();
    
    if (!$gateway) {
        return ['success' => false, 'message' => 'Gateway não configurado'];
    }
    
    $signature = hash_hmac('sha256', $payload, $gateway['client_secret']);
    
    // Simular chamada
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
    
    return [
        'success' => !$error && $httpCode == 200,
        'http_code' => $httpCode,
        'response' => $response,
        'error' => $error
    ];
}

$resultado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_test_transaction') {
        $user_id = intval($_POST['user_id'] ?? 0);
        $amount = floatval($_POST['amount'] ?? 0);
        
        if ($user_id > 0 && $amount > 0) {
            $external_id = 'TEST_' . time() . '_' . rand(1000, 9999);
            $transaction_id = 'TXN_' . time() . '_' . rand(1000, 9999);
            
            // Criar transação de teste
            $stmt = $pdo->prepare("INSERT INTO transacoes_pix (usuario_id, telefone, valor, external_id, status, criado_em) VALUES (?, '11999999999', ?, ?, 'pendente', NOW())");
            $stmt->execute([$user_id, $amount, $external_id]);
            
            $resultado = [
                'type' => 'success',
                'message' => "Transação de teste criada: External ID: $external_id, Transaction ID: $transaction_id",
                'external_id' => $external_id,
                'transaction_id' => $transaction_id,
                'amount' => $amount
            ];
        } else {
            $resultado = ['type' => 'error', 'message' => 'Dados inválidos'];
        }
    }
    
    if ($action === 'test_webhook' && isset($_POST['external_id'])) {
        $external_id = $_POST['external_id'];
        $transaction_id = $_POST['transaction_id'];
        $amount = floatval($_POST['amount']);
        
        $teste = testarWebhook($transaction_id, $external_id, $amount, $pdo);
        
        if ($teste['success']) {
            $resultado = [
                'type' => 'success',
                'message' => 'Webhook testado com sucesso!',
                'details' => $teste
            ];
        } else {
            $resultado = [
                'type' => 'error',
                'message' => 'Erro no teste do webhook: ' . ($teste['error'] ?: $teste['response']),
                'details' => $teste
            ];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug Webhook - Teste Completo</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --bg-dark: #0d1117;
            --bg-panel: #161b22;
            --primary-gold: #fbce00;
            --text-light: #f0f6fc;
            --text-muted: #8b949e;
            --radius: 12px;
            --transition: 0.3s ease;
            --border-panel: #21262d;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0d1117 0%, #161b22 100%);
            color: var(--text-light);
            min-height: 100vh;
            padding: 40px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        h1 {
            color: var(--primary-gold);
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .card {
            background: var(--bg-panel);
            border: 1px solid var(--border-panel);
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 24px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        label {
            display: block;
            font-weight: 600;
            color: var(--text-light);
            margin-bottom: 8px;
        }

        input, select {
            width: 100%;
            padding: 12px 16px;
            background: var(--bg-dark);
            border: 1px solid var(--border-panel);
            border-radius: var(--radius);
            color: var(--text-light);
            font-size: 14px;
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--primary-gold);
        }

        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: var(--radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 14px;
            background: linear-gradient(135deg, var(--primary-gold), #f4c430);
            color: #000;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .message {
            padding: 16px 20px;
            border-radius: var(--radius);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .message.success {
            background: rgba(34, 197, 94, 0.15);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #22c55e;
        }

        .message.error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #ef4444;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--primary-gold);
            text-decoration: none;
            margin-bottom: 20px;
            transition: var(--transition);
        }

        .back-link:hover {
            color: #f4c430;
        }

        pre {
            background: var(--bg-dark);
            padding: 16px;
            border-radius: var(--radius);
            overflow-x: auto;
            font-size: 12px;
            line-height: 1.4;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="pix_admin.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Voltar ao Painel PIX
        </a>

        <h1>
            <i class="fas fa-bug"></i>
            Debug Webhook - Teste Completo
        </h1>

        <?php if ($resultado): ?>
            <div class="message <?= $resultado['type'] ?>">
                <i class="fas fa-<?= $resultado['type'] === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
                <?= htmlspecialchars($resultado['message']) ?>
            </div>
            
            <?php if (isset($resultado['details'])): ?>
                <div class="card">
                    <h3>Detalhes da Resposta:</h3>
                    <pre><?= htmlspecialchars(json_encode($resultado['details'], JSON_PRETTY_PRINT)) ?></pre>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="grid">
            <!-- Criar Transação de Teste -->
            <div class="card">
                <h3 style="color: var(--primary-gold); margin-bottom: 20px;">
                    <i class="fas fa-plus-circle"></i>
                    Criar Transação de Teste
                </h3>
                
                <form method="POST">
                    <input type="hidden" name="action" value="create_test_transaction">
                    
                    <div class="form-group">
                        <label>ID do Usuário:</label>
                        <input type="number" name="user_id" placeholder="Digite o ID do usuário" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Valor (R$):</label>
                        <input type="number" name="amount" step="0.01" placeholder="10.00" required>
                    </div>
                    
                    <button type="submit" class="btn">
                        <i class="fas fa-plus"></i>
                        Criar Transação
                    </button>
                </form>
            </div>

            <!-- Testar Webhook -->
            <div class="card">
                <h3 style="color: var(--primary-gold); margin-bottom: 20px;">
                    <i class="fas fa-play"></i>
                    Testar Webhook
                </h3>
                
                <form method="POST">
                    <input type="hidden" name="action" value="test_webhook">
                    
                    <div class="form-group">
                        <label>External ID:</label>
                        <input type="text" name="external_id" placeholder="External ID da transação" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Transaction ID:</label>
                        <input type="text" name="transaction_id" placeholder="Transaction ID" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Valor (R$):</label>
                        <input type="number" name="amount" step="0.01" placeholder="10.00" required>
                    </div>
                    
                    <button type="submit" class="btn">
                        <i class="fas fa-paper-plane"></i>
                        Testar Webhook
                    </button>
                </form>
            </div>
        </div>

        <!-- Instruções -->
        <div class="card">
            <h3 style="color: var(--primary-gold); margin-bottom: 20px;">
                <i class="fas fa-info-circle"></i>
                Como Usar
            </h3>
            
            <ol style="color: var(--text-light); line-height: 1.6;">
                <li><strong>Criar Transação de Teste:</strong> Digite o ID de um usuário existente e um valor. Isso criará uma transação pendente no sistema.</li>
                <li><strong>Testar Webhook:</strong> Use os dados retornados da transação criada para simular o webhook do gateway.</li>
                <li><strong>Verificar Resultado:</strong> Após o teste, verifique se o saldo do usuário foi atualizado corretamente.</li>
                <li><strong>Analisar Logs:</strong> Use o painel principal para ver os logs detalhados do webhook.</li>
            </ol>
        </div>
    </div>
</body>
</html>
