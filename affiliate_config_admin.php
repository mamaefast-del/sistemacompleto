<?php
session_start();

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    header('Location: login.php');
    exit;
}

require 'db.php';

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $configs = $_POST['config'] ?? [];
        
        foreach ($configs as $key => $value) {
            $stmt = $pdo->prepare("
                INSERT INTO affiliate_config (config_key, config_value) 
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)
            ");
            $stmt->execute([$key, $value]);
        }
        
        $_SESSION['success'] = 'Configurações salvas com sucesso!';
        header('Location: affiliate_config_admin.php');
        exit;
        
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Erro ao salvar configurações: ' . $e->getMessage();
    }
}

// Buscar configurações atuais
try {
    $stmt = $pdo->query("SELECT config_key, config_value, description FROM affiliate_config");
    $configs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    $configs = [];
}

// Configurações padrão se não existirem
$defaultConfigs = [
    'AFF_COOKIE_NAME' => 'aff_ref',
    'AFF_COOKIE_DAYS' => '30',
    'AFF_ATTR_MODEL' => 'LAST_CLICK',
    'AFF_COOKIE_DOMAIN' => '',
    'AFF_COMMISSION_RATE_L1' => '10.00',
    'AFF_COMMISSION_RATE_L2' => '5.00',
    'AFF_MIN_PAYOUT' => '10.00',
    'AFF_MAX_PAYOUT' => '1000.00'
];

foreach ($defaultConfigs as $key => $defaultValue) {
    if (!isset($configs[$key])) {
        $configs[$key] = $defaultValue;
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações de Afiliados - Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --bg-dark: #0a0b0f;
            --bg-panel: #111318;
            --bg-card: #1a1d24;
            --primary-green: #00d4aa;
            --primary-gold: #fbce00;
            --text-light: #ffffff;
            --text-muted: #8b949e;
            --border-color: #21262d;
            --success-color: #22c55e;
            --error-color: #ef4444;
            --radius: 12px;
            --shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, var(--bg-dark) 0%, #0d1117 100%);
            color: var(--text-light);
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 24px;
        }

        .page-header {
            margin-bottom: 32px;
        }

        .page-title {
            font-size: 32px;
            font-weight: 800;
            color: var(--text-light);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .page-subtitle {
            color: var(--text-muted);
            font-size: 16px;
        }

        .card {
            background: var(--bg-panel);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 24px;
            transition: var(--transition);
        }

        .card:hover {
            border-color: var(--primary-green);
            box-shadow: var(--shadow);
        }

        .card h3 {
            color: var(--primary-green);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 20px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-light);
            font-size: 14px;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            color: var(--text-light);
            transition: var(--transition);
            font-size: 14px;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(0, 212, 170, 0.1);
        }

        .form-help {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .btn {
            padding: 12px 24px;
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
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-gold));
            color: #000;
            box-shadow: 0 4px 16px rgba(0, 212, 170, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 212, 170, 0.4);
        }

        .alert {
            padding: 16px 20px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid var(--success-color);
            color: var(--success-color);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid var(--error-color);
            color: var(--error-color);
        }

        .info-box {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid var(--info-color);
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 20px;
            color: var(--text-light);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-cog"></i>
                Configurações de Afiliados
            </h1>
            <div class="page-subtitle">
                Configure o comportamento do sistema de rastreamento e comissões
            </div>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?= $_SESSION['success'] ?>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?= $_SESSION['error'] ?>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <form method="POST">
            <!-- Configurações de Rastreamento -->
            <div class="card">
                <h3>
                    <i class="fas fa-mouse-pointer"></i>
                    Rastreamento de Cliques
                </h3>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Nome do Cookie</label>
                        <input type="text" 
                               name="config[AFF_COOKIE_NAME]" 
                               class="form-input" 
                               value="<?= htmlspecialchars($configs['AFF_COOKIE_NAME']) ?>" 
                               required>
                        <div class="form-help">Nome do cookie usado para rastrear afiliados</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Duração do Cookie (dias)</label>
                        <input type="number" 
                               name="config[AFF_COOKIE_DAYS]" 
                               class="form-input" 
                               value="<?= htmlspecialchars($configs['AFF_COOKIE_DAYS']) ?>" 
                               min="1" 
                               max="365" 
                               required>
                        <div class="form-help">Por quantos dias o cookie permanece ativo</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Modelo de Atribuição</label>
                        <select name="config[AFF_ATTR_MODEL]" class="form-input" required>
                            <option value="FIRST_CLICK" <?= $configs['AFF_ATTR_MODEL'] === 'FIRST_CLICK' ? 'selected' : '' ?>>
                                Primeiro Clique
                            </option>
                            <option value="LAST_CLICK" <?= $configs['AFF_ATTR_MODEL'] === 'LAST_CLICK' ? 'selected' : '' ?>>
                                Último Clique
                            </option>
                        </select>
                        <div class="form-help">Como atribuir usuários quando há múltiplos cliques</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Domínio do Cookie</label>
                        <input type="text" 
                               name="config[AFF_COOKIE_DOMAIN]" 
                               class="form-input" 
                               value="<?= htmlspecialchars($configs['AFF_COOKIE_DOMAIN']) ?>" 
                               placeholder=".meudominio.com">
                        <div class="form-help">Deixe vazio para domínio atual, ou use .dominio.com para subdomínios</div>
                    </div>
                </div>
            </div>

            <!-- Configurações de Comissão -->
            <div class="card">
                <h3>
                    <i class="fas fa-percentage"></i>
                    Sistema de Comissões
                </h3>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Comissão Nível 1 (%)</label>
                        <input type="number" 
                               name="config[AFF_COMMISSION_RATE_L1]" 
                               class="form-input" 
                               value="<?= htmlspecialchars($configs['AFF_COMMISSION_RATE_L1']) ?>" 
                               step="0.01" 
                               min="0" 
                               max="50" 
                               required>
                        <div class="form-help">Comissão para quem indica diretamente</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Comissão Nível 2 (%)</label>
                        <input type="number" 
                               name="config[AFF_COMMISSION_RATE_L2]" 
                               class="form-input" 
                               value="<?= htmlspecialchars($configs['AFF_COMMISSION_RATE_L2']) ?>" 
                               step="0.01" 
                               min="0" 
                               max="25" 
                               required>
                        <div class="form-help">Comissão para sub-afiliação</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Saque Mínimo (R$)</label>
                        <input type="number" 
                               name="config[AFF_MIN_PAYOUT]" 
                               class="form-input" 
                               value="<?= htmlspecialchars($configs['AFF_MIN_PAYOUT']) ?>" 
                               step="0.01" 
                               min="1" 
                               required>
                        <div class="form-help">Valor mínimo para saque de comissão</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Saque Máximo (R$)</label>
                        <input type="number" 
                               name="config[AFF_MAX_PAYOUT]" 
                               class="form-input" 
                               value="<?= htmlspecialchars($configs['AFF_MAX_PAYOUT']) ?>" 
                               step="0.01" 
                               min="1" 
                               required>
                        <div class="form-help">Valor máximo para saque de comissão</div>
                    </div>
                </div>
            </div>

            <!-- Informações Técnicas -->
            <div class="card">
                <h3>
                    <i class="fas fa-info-circle"></i>
                    Informações Técnicas
                </h3>
                
                <div class="info-box">
                    <h4 style="color: var(--primary-green); margin-bottom: 12px;">URLs de Teste:</h4>
                    <ul style="line-height: 1.8;">
                        <li><strong>Com afiliado:</strong> <code>/?ref=CODIGO&utm_source=facebook</code></li>
                        <li><strong>Relatórios:</strong> <code>admin_affiliate_reports.php</code></li>
                        <li><strong>Teste completo:</strong> <code>test_affiliate_system.php</code></li>
                    </ul>
                    
                    <h4 style="color: var(--primary-green); margin: 20px 0 12px;">Webhook ExpfyPay:</h4>
                    <ul style="line-height: 1.8;">
                        <li><strong>Endpoint:</strong> <code>/webhook_expfypay.php</code></li>
                        <li><strong>Método:</strong> POST</li>
                        <li><strong>Header:</strong> X-Signature (HMAC SHA256)</li>
                    </ul>
                </div>
            </div>

            <div style="text-align: center; margin-top: 32px;">
                <button type="submit" class="btn btn-primary" style="font-size: 16px; padding: 16px 32px;">
                    <i class="fas fa-save"></i>
                    Salvar Configurações
                </button>
            </div>
        </form>
    </div>
</body>
</html>