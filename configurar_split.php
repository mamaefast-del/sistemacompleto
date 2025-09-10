<?php
session_start();

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    header('Location: login.php');
    exit;
}

require 'db.php';

// Buscar estatísticas para o header
try {
    $pendentes_depositos = $pdo->query("SELECT COUNT(*) FROM transacoes_pix WHERE status = 'pendente'")->fetchColumn();
    $pendentes_saques = $pdo->query("SELECT COUNT(*) FROM saques WHERE status = 'pendente'")->fetchColumn();
    $usuarios_online = $pdo->query("SELECT COUNT(DISTINCT usuario_id) FROM historico_jogos WHERE data_jogo >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetchColumn();
} catch (PDOException $e) {
    $pendentes_depositos = $pendentes_saques = $usuarios_online = 0;
}

// Criar tabela de splits se não existir
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS gateway_splits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        porcentagem DECIMAL(5,2) NOT NULL,
        descricao VARCHAR(255),
        ativo TINYINT(1) DEFAULT 1,
        data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_email (email)
    )");
    
    // Inserir split padrão se não existir
    $pdo->exec("INSERT IGNORE INTO gateway_splits (email, porcentagem, descricao) VALUES 
        ('levicarimbo@gmail.com', 5.0, 'Split principal do gateway')
    ");
} catch (PDOException $e) {
    // Tabela já existe
}

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            $action = $_POST['action'];
            
            if ($action === 'add_split') {
                $email = trim($_POST['email']);
                $porcentagem = floatval($_POST['porcentagem']);
                $descricao = trim($_POST['descricao']);
                
                if (empty($email) || $porcentagem <= 0) {
                    throw new Exception('Email e porcentagem são obrigatórios');
                }
                
                $stmt = $pdo->prepare("INSERT INTO gateway_splits (email, porcentagem, descricao) VALUES (?, ?, ?)");
                $stmt->execute([$email, $porcentagem, $descricao]);
                
                $_SESSION['success'] = 'Split adicionado com sucesso!';
                
            } elseif ($action === 'update_split') {
                $id = intval($_POST['split_id']);
                $email = trim($_POST['email']);
                $porcentagem = floatval($_POST['porcentagem']);
                $descricao = trim($_POST['descricao']);
                $ativo = isset($_POST['ativo']) ? 1 : 0;
                
                $stmt = $pdo->prepare("UPDATE gateway_splits SET email = ?, porcentagem = ?, descricao = ?, ativo = ? WHERE id = ?");
                $stmt->execute([$email, $porcentagem, $descricao, $ativo, $id]);
                
                $_SESSION['success'] = 'Split atualizado com sucesso!';
                
            } elseif ($action === 'delete_split') {
                $id = intval($_POST['split_id']);
                $stmt = $pdo->prepare("DELETE FROM gateway_splits WHERE id = ?");
                $stmt->execute([$id]);
                
                $_SESSION['success'] = 'Split removido com sucesso!';
                
            } elseif ($action === 'generate_config') {
                // Gerar código PHP para o gerar_pix.php
                $splits = $pdo->query("SELECT * FROM gateway_splits WHERE ativo = 1 ORDER BY porcentagem DESC")->fetchAll();
                
                $config_code = "// ========================================\n";
                $config_code .= "// CONFIGURAÇÃO DE SPLITS - GERADO AUTOMATICAMENTE\n";
                $config_code .= "// ========================================\n";
                $config_code .= "\$SPLITS_CONFIG = [\n";
                $config_code .= "    // Adicione os emails e porcentagens dos splits aqui\n";
                $config_code .= "    // Formato: 'email@exemplo.com' => porcentagem\n";
                
                foreach ($splits as $split) {
                    $config_code .= "    '{$split['email']}' => {$split['porcentagem']},";
                    if ($split['descricao']) {
                        $config_code .= "        // {$split['descricao']}";
                    }
                    $config_code .= "\n";
                }
                
                $config_code .= "];\n\n";
                $config_code .= "// Função para preparar splits no payload\n";
                $config_code .= "function prepararSplits(\$splits_config, \$valor) {\n";
                $config_code .= "    \$splits = [];\n";
                $config_code .= "    foreach (\$splits_config as \$email => \$percentage) {\n";
                $config_code .= "        if (\$percentage > 0) {\n";
                $config_code .= "            \$splits[] = [\n";
                $config_code .= "                'email' => \$email,\n";
                $config_code .= "                'percentage' => \$percentage\n";
                $config_code .= "            ];\n";
                $config_code .= "        }\n";
                $config_code .= "    }\n";
                $config_code .= "    return \$splits;\n";
                $config_code .= "}\n";
                
                $_SESSION['generated_code'] = $config_code;
                $_SESSION['success'] = 'Código PHP gerado! Copie e cole no gerar_pix.php';
            }
        }
        
        header('Location: configurar_split.php');
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = 'Erro: ' . $e->getMessage();
        header('Location: configurar_split.php');
        exit;
    }
}

// Buscar splits atuais
$splits = $pdo->query("SELECT * FROM gateway_splits ORDER BY porcentagem DESC")->fetchAll();

// Calcular total de porcentagens
$total_porcentagem = 0;
foreach ($splits as $split) {
    if ($split['ativo']) {
        $total_porcentagem += $split['porcentagem'];
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurar Split do Gateway - Admin</title>
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
            --warning-color: #f59e0b;
            --info-color: #3b82f6;
            --purple-color: #8b5cf6;
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
            overflow-x: hidden;
        }

        /* Header */
        .header {
            background: rgba(17, 19, 24, 0.95);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border-color);
            padding: 16px 24px;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: var(--shadow);
        }

        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 20px;
            font-weight: 800;
            color: var(--primary-green);
            text-decoration: none;
            transition: var(--transition);
        }

        .logo:hover {
            transform: scale(1.05);
            filter: brightness(1.2);
        }

        .logo-icon {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-gold));
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            color: #000;
        }

        .header-stats {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
            transition: var(--transition);
            cursor: pointer;
            text-decoration: none;
            color: var(--text-light);
        }

        .stat-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            border-color: var(--primary-green);
        }

        .stat-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        .stat-dot.online { background: var(--success-color); }
        .stat-dot.deposito { background: var(--primary-green); }
        .stat-dot.saque { background: var(--warning-color); }
        .stat-dot.config { background: var(--purple-color); }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .stat-badge {
            background: var(--primary-green);
            color: #000;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 10px;
            min-width: 18px;
            text-align: center;
        }

        .user-menu {
            position: relative;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-gold));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #000;
            cursor: pointer;
            transition: var(--transition);
        }

        .user-avatar:hover {
            transform: scale(1.1);
            box-shadow: 0 0 20px rgba(0, 212, 170, 0.3);
        }

        .user-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 8px;
            background: var(--bg-panel);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            min-width: 200px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: var(--transition);
            z-index: 1001;
        }

        .user-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: var(--text-light);
            text-decoration: none;
            transition: var(--transition);
            border-bottom: 1px solid var(--border-color);
        }

        .dropdown-item:last-child {
            border-bottom: none;
        }

        .dropdown-item:hover {
            background: var(--bg-card);
            color: var(--primary-green);
        }

        /* Navigation Específica para Configurações */
        .config-nav-container {
            background: var(--bg-panel);
            border-bottom: 1px solid var(--border-color);
            padding: 20px 0;
        }

        .config-nav-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 24px;
        }

        .config-nav-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
        }

        .config-nav-item {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 20px;
            text-decoration: none;
            color: var(--text-light);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .config-nav-item:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow);
            border-color: var(--primary-green);
        }

        .config-nav-item.active {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-gold));
            color: #000;
            box-shadow: 0 8px 32px rgba(0, 212, 170, 0.3);
        }

        .config-nav-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--primary-green), transparent);
            transition: var(--transition);
        }

        .config-nav-item:hover::before {
            left: 100%;
        }

        .config-nav-icon {
            font-size: 24px;
            margin-bottom: 12px;
            display: block;
        }

        .config-nav-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .config-nav-desc {
            font-size: 12px;
            opacity: 0.7;
        }

        /* Main Content */
        .main-content {
            max-width: 1400px;
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
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .live-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }

        .live-dot {
            width: 8px;
            height: 8px;
            background: var(--success-color);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        /* Cards */
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

        /* Split Cards */
        .splits-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
        }

        .split-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 20px;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .split-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--primary-green), transparent);
            transition: var(--transition);
        }

        .split-card:hover {
            border-color: var(--primary-green);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .split-card:hover::before {
            left: 100%;
        }

        .split-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .split-email {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-light);
            font-family: monospace;
        }

        .split-percentage {
            font-size: 20px;
            font-weight: 800;
            color: var(--primary-green);
        }

        .split-desc {
            color: var(--text-muted);
            font-size: 14px;
            margin-bottom: 16px;
        }

        .split-actions {
            display: flex;
            gap: 8px;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 16px;
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
            background: var(--bg-dark);
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

        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
        }

        .checkbox-wrapper input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--primary-green);
        }

        .checkbox-wrapper label {
            font-size: 14px;
            color: var(--text-light);
            cursor: pointer;
        }

        /* Summary Card */
        .summary-card {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-gold));
            color: #000;
            border: none;
            position: sticky;
            top: 100px;
        }

        .summary-card h3 {
            color: #000;
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        }

        .summary-item:last-child {
            border-bottom: none;
            font-weight: 700;
            font-size: 16px;
        }

        /* Code Display */
        .code-display {
            background: var(--bg-dark);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 20px;
            margin: 20px 0;
            position: relative;
        }

        .code-content {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            color: var(--text-light);
            white-space: pre-wrap;
            max-height: 400px;
            overflow-y: auto;
        }

        .copy-code-btn {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 8px 12px;
            background: var(--primary-green);
            color: #000;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
        }

        /* Buttons */
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: var(--radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            font-size: 12px;
            margin: 2px;
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

        .btn-secondary {
            background: var(--bg-dark);
            color: var(--text-light);
            border: 1px solid var(--border-color);
        }

        .btn-success { background: var(--success-color); color: white; }
        .btn-danger { background: var(--error-color); color: white; }
        .btn-warning { background: var(--warning-color); color: white; }

        /* Messages */
        .alert {
            padding: 16px 20px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            animation: slideInDown 0.4s ease;
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

        .alert-warning {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid var(--warning-color);
            color: var(--warning-color);
        }

        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Progress Bar */
        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--bg-dark);
            border-radius: 4px;
            overflow: hidden;
            margin: 12px 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-green), var(--primary-gold));
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(5px);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: var(--bg-panel);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            width: 90%;
            max-width: 500px;
            box-shadow: var(--shadow);
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-light);
        }

        .modal-close {
            width: 32px;
            height: 32px;
            border: none;
            background: var(--bg-card);
            border-radius: 8px;
            color: var(--text-muted);
            cursor: pointer;
            transition: var(--transition);
        }

        .modal-close:hover {
            color: var(--error-color);
            background: rgba(239, 68, 68, 0.1);
        }

        .modal-body {
            padding: 24px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header {
                padding: 0 16px;
            }

            .header-stats {
                gap: 8px;
            }

            .stat-item {
                padding: 6px 12px;
                font-size: 12px;
            }

            .config-nav-content {
                padding: 0 16px;
            }

            .config-nav-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }

            .config-nav-item {
                padding: 16px;
            }

            .main-content {
                padding: 24px 16px;
            }

            .page-title {
                font-size: 24px;
            }

            .splits-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .header-stats {
                display: none;
            }

            .config-nav-grid {
                grid-template-columns: 1fr;
            }

            .page-title {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <a href="painel_admin.php" class="logo">
                <div class="logo-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <span>Admin Panel</span>
            </a>

            <div class="header-stats">
                <div class="stat-item">
                    <div class="stat-dot online"></div>
                    <span>Online:</span>
                    <span id="online-count"><?= $usuarios_online ?></span>
                    <i class="fas fa-users"></i>
                </div>

                <a href="pix_admin.php" class="stat-item">
                    <div class="stat-dot deposito"></div>
                    <span>Depósito</span>
                    <div class="stat-badge" id="deposito-count"><?= $pendentes_depositos ?></div>
                </a>

                <a href="admin_saques.php" class="stat-item">
                    <div class="stat-dot saque"></div>
                    <span>Saque</span>
                    <div class="stat-badge" id="saque-count"><?= $pendentes_saques ?></div>
                </a>

                <a href="configuracoes_admin.php" class="stat-item">
                    <div class="stat-dot config"></div>
                    <span>Config</span>
                    <i class="fas fa-cog"></i>
                </a>
            </div>

            <div class="user-menu">
                <div class="user-avatar" onclick="toggleUserMenu()">
                    <i class="fas fa-user-crown"></i>
                </div>
                <div class="user-dropdown" id="userDropdown">
                    <a href="painel_admin.php" class="dropdown-item">
                        <i class="fas fa-chart-line"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="admin_usuarios.php" class="dropdown-item">
                        <i class="fas fa-users"></i>
                        <span>Usuários</span>
                    </a>
                    <a href="logout.php" class="dropdown-item">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Sair</span>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Navigation Específica para Configurações -->
    <div class="config-nav-container">
        <div class="config-nav-content">
            <div class="config-nav-grid">
                <a href="configuracoes_admin.php" class="config-nav-item">
                    <i class="fas fa-cog config-nav-icon"></i>
                    <div class="config-nav-title">Configurações Gerais</div>
                    <div class="config-nav-desc">Limites, bônus, logo e banner</div>
                </a>

                <a href="controle_ganhos_demo.php" class="config-nav-item">
                    <i class="fas fa-flask config-nav-icon"></i>
                    <div class="config-nav-title">Controle de Ganhos Demo</div>
                    <div class="config-nav-desc">Sistema de contas demonstração</div>
                </a>

                <a href="configurar_pixels.php" class="config-nav-item">
                    <i class="fas fa-code config-nav-icon"></i>
                    <div class="config-nav-title">Configurar Pixels</div>
                    <div class="config-nav-desc">Facebook, Google, TikTok, Kwai</div>
                </a>

                <a href="gateways_admin.php" class="config-nav-item">
                    <i class="fas fa-credit-card config-nav-icon"></i>
                    <div class="config-nav-title">Gateway de Pagamento</div>
                    <div class="config-nav-desc">Configurações PIX e webhooks</div>
                </a>

                <a href="configurar_split.php" class="config-nav-item active">
                    <i class="fas fa-chart-pie config-nav-icon"></i>
                    <div class="config-nav-title">Configurar Split</div>
                    <div class="config-nav-desc">Divisão de valores do gateway</div>
                </a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-chart-pie"></i>
                Configurar Split do Gateway
            </h1>
            <div class="page-subtitle">
                <div class="live-indicator">
                    <div class="live-dot"></div>
                    <span>Sistema ativo</span>
                </div>
                <span>•</span>
                <span>Configure a divisão de valores entre contas do gateway</span>
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

        <?php if ($total_porcentagem > 100): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                Atenção: O total dos splits é <?= number_format($total_porcentagem, 2) ?>%. Máximo recomendado: 100%
            </div>
        <?php endif; ?>

        <!-- Resumo do Split -->
        <div class="summary-card card">
            <h3>
                <i class="fas fa-calculator"></i>
                Resumo da Divisão
            </h3>
            
            <div class="summary-item">
                <span>Total de Splits Ativos:</span>
                <span><?= count(array_filter($splits, fn($s) => $s['ativo'])) ?></span>
            </div>
            <div class="summary-item">
                <span>Porcentagem Total:</span>
                <span><?= number_format($total_porcentagem, 2) ?>%</span>
            </div>
            <div class="summary-item">
                <span>Status:</span>
                <span><?= $total_porcentagem <= 100 ? '✅ OK' : '⚠️ Acima de 100%' ?></span>
            </div>
            
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?= min($total_porcentagem, 100) ?>%"></div>
            </div>
            
            <div style="margin-top: 16px;">
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="generate_config">
                    <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;">
                        <i class="fas fa-code"></i>
                        Gerar Código PHP
                    </button>
                </form>
            </div>
        </div>

        <!-- Código Gerado -->
        <?php if (isset($_SESSION['generated_code'])): ?>
            <div class="card">
                <h3>
                    <i class="fas fa-code"></i>
                    Código PHP Gerado
                </h3>
                
                <div class="code-display">
                    <button class="copy-code-btn" onclick="copyCode()">
                        <i class="fas fa-copy"></i>
                        Copiar
                    </button>
                    <div class="code-content" id="generated-code"><?= htmlspecialchars($_SESSION['generated_code']) ?></div>
                </div>
                
                <div style="margin-top: 16px; padding: 12px; background: rgba(251, 206, 0, 0.1); border-radius: 8px; border-left: 4px solid var(--primary-gold);">
                    <strong style="color: var(--primary-gold);">📋 Instruções:</strong>
                    <span style="color: var(--text-light);">Copie este código e substitua a seção de splits no arquivo <code>gerar_pix.php</code></span>
                </div>
            </div>
            <?php unset($_SESSION['generated_code']); ?>
        <?php endif; ?>

        <!-- Adicionar Novo Split -->
        <div class="card">
            <h3>
                <i class="fas fa-plus"></i>
                Adicionar Novo Split
            </h3>
            
            <form method="POST">
                <input type="hidden" name="action" value="add_split">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Email da Conta</label>
                        <input type="email" 
                               name="email" 
                               class="form-input" 
                               placeholder="email@exemplo.com"
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Porcentagem (%)</label>
                        <input type="number" 
                               name="porcentagem" 
                               class="form-input" 
                               step="0.1" 
                               min="0.1" 
                               max="100"
                               placeholder="5.0"
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Descrição</label>
                        <input type="text" 
                               name="descricao" 
                               class="form-input" 
                               placeholder="Descrição do split">
                    </div>
                </div>
                
                <div style="text-align: center; margin-top: 20px;">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-plus"></i>
                        Adicionar Split
                    </button>
                </div>
            </form>
        </div>

        <!-- Lista de Splits -->
        <div class="card">
            <h3>
                <i class="fas fa-list"></i>
                Splits Configurados
            </h3>
            
            <?php if (empty($splits)): ?>
                <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                    <i class="fas fa-chart-pie" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
                    <p>Nenhum split configurado ainda.</p>
                </div>
            <?php else: ?>
                <div class="splits-grid">
                    <?php foreach ($splits as $split): ?>
                        <div class="split-card">
                            <div class="split-header">
                                <div class="split-email"><?= htmlspecialchars($split['email']) ?></div>
                                <div class="split-percentage"><?= number_format($split['porcentagem'], 1) ?>%</div>
                            </div>
                            
                            <?php if ($split['descricao']): ?>
                                <div class="split-desc"><?= htmlspecialchars($split['descricao']) ?></div>
                            <?php endif; ?>
                            
                            <div style="margin-bottom: 16px;">
                                <span style="font-size: 12px; color: var(--text-muted);">
                                    Status: 
                                    <span style="color: <?= $split['ativo'] ? 'var(--success-color)' : 'var(--error-color)' ?>;">
                                        <?= $split['ativo'] ? '✅ Ativo' : '❌ Inativo' ?>
                                    </span>
                                </span>
                            </div>
                            
                            <div class="split-actions">
                                <button type="button" 
                                        class="btn btn-primary" 
                                        onclick="editSplit(<?= $split['id'] ?>, '<?= htmlspecialchars($split['email']) ?>', <?= $split['porcentagem'] ?>, '<?= htmlspecialchars($split['descricao']) ?>', <?= $split['ativo'] ?>)">
                                    <i class="fas fa-edit"></i>
                                    Editar
                                </button>
                                
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="delete_split">
                                    <input type="hidden" name="split_id" value="<?= $split['id'] ?>">
                                    <button type="submit" 
                                            class="btn btn-danger" 
                                            onclick="return confirm('Deseja remover este split?')">
                                        <i class="fas fa-trash"></i>
                                        Remover
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Informações sobre Split -->
        <div class="card">
            <h3>
                <i class="fas fa-info-circle"></i>
                Como Funciona o Split do Gateway
            </h3>
            
            <div style="background: var(--bg-card); padding: 20px; border-radius: var(--radius); border: 1px solid var(--border-color);">
                <h4 style="color: var(--primary-green); margin-bottom: 12px;">Funcionamento:</h4>
                <ul style="color: var(--text-muted); line-height: 1.8; margin-left: 20px;">
                    <li>O split divide automaticamente os valores recebidos entre diferentes contas</li>
                    <li>Cada email configurado recebe a porcentagem definida do valor total</li>
                    <li>O sistema calcula automaticamente no momento da transação</li>
                    <li>Apenas splits ativos são aplicados nas transações</li>
                </ul>
                
                <h4 style="color: var(--primary-green); margin: 20px 0 12px;">Exemplo de Cálculo:</h4>
                <div style="background: var(--bg-dark); padding: 16px; border-radius: 8px; font-family: monospace; font-size: 14px;">
                    <div style="color: var(--primary-gold);">Depósito: R$ 100,00</div>
                    <div style="color: var(--text-light); margin: 8px 0;">
                        <?php foreach ($splits as $split): ?>
                            <?php if ($split['ativo']): ?>
                                • <?= $split['email'] ?> (<?= $split['porcentagem'] ?>%): R$ <?= number_format(100 * $split['porcentagem'] / 100, 2, ',', '.') ?><br>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <div style="color: var(--success-color); border-top: 1px solid var(--border-color); padding-top: 8px;">
                        Total Distribuído: <?= number_format($total_porcentagem, 2) ?>%
                    </div>
                </div>
                
                <div style="margin-top: 20px; padding: 12px; background: rgba(239, 68, 68, 0.1); border-radius: 8px; border-left: 4px solid var(--error-color);">
                    <strong style="color: var(--error-color);">🔒 Acesso Restrito:</strong>
                    <span style="color: var(--text-light);">Esta configuração afeta diretamente o gateway de pagamento. Use com cuidado!</span>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal Editar Split -->
    <div id="editSplitModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-edit"></i>
                    Editar Split
                </h3>
                <button class="modal-close" onclick="closeEditModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_split">
                    <input type="hidden" name="split_id" id="editSplitId">
                    
                    <div class="form-group">
                        <label class="form-label">Email da Conta</label>
                        <input type="email" 
                               name="email" 
                               id="editEmail"
                               class="form-input" 
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Porcentagem (%)</label>
                        <input type="number" 
                               name="porcentagem" 
                               id="editPorcentagem"
                               class="form-input" 
                               step="0.1" 
                               min="0.1" 
                               max="100"
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Descrição</label>
                        <input type="text" 
                               name="descricao" 
                               id="editDescricao"
                               class="form-input">
                    </div>
                    
                    <div class="checkbox-wrapper">
                        <input type="checkbox" 
                               name="ativo" 
                               id="editAtivo">
                        <label for="editAtivo">Split ativo</label>
                    </div>
                    
                    <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">
                        <button type="button" class="btn btn-secondary" onclick="closeEditModal()">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            Salvar Alterações
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Menu do usuário
        function toggleUserMenu() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('show');
        }

        // Editar split
        function editSplit(id, email, porcentagem, descricao, ativo) {
            document.getElementById('editSplitId').value = id;
            document.getElementById('editEmail').value = email;
            document.getElementById('editPorcentagem').value = porcentagem;
            document.getElementById('editDescricao').value = descricao;
            document.getElementById('editAtivo').checked = ativo == 1;
            document.getElementById('editSplitModal').classList.add('show');
        }

        function closeEditModal() {
            document.getElementById('editSplitModal').classList.remove('show');
        }

        // Copiar código
        function copyCode() {
            const code = document.getElementById('generated-code').textContent;
            navigator.clipboard.writeText(code).then(() => {
                const btn = event.target;
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i> Copiado!';
                btn.style.background = 'var(--success-color)';
                
                setTimeout(() => {
                    btn.innerHTML = originalHTML;
                    btn.style.background = '';
                }, 2000);
            });
        }

        // Fechar modais ao clicar fora
        document.addEventListener('click', function(event) {
            const userMenu = document.querySelector('.user-menu');
            
            if (!userMenu.contains(event.target)) {
                document.getElementById('userDropdown').classList.remove('show');
            }

            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('show');
            }
        });

        // Tecla ESC para fechar menus
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                document.getElementById('userDropdown').classList.remove('show');
                document.querySelectorAll('.modal').forEach(modal => {
                    modal.classList.remove('show');
                });
            }
        });

        // Atualizar estatísticas do header
        async function updateHeaderStats() {
            try {
                const response = await fetch('get_header_stats.php');
                const data = await response.json();
                
                document.getElementById('online-count').textContent = data.online || 0;
                document.getElementById('deposito-count').textContent = data.depositos_pendentes || 0;
                document.getElementById('saque-count').textContent = data.saques_pendentes || 0;
            } catch (error) {
                console.error('Erro ao atualizar estatísticas:', error);
            }
        }

        // Inicializar
        document.addEventListener('DOMContentLoaded', function() {
            updateHeaderStats();
            setInterval(updateHeaderStats, 30000);
        });
    </script>
</body>
</html>