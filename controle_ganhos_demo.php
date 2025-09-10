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

// Processar configurações de demo
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Criar tabela de configurações demo se não existir
        $pdo->exec("CREATE TABLE IF NOT EXISTS demo_config (
            id INT AUTO_INCREMENT PRIMARY KEY,
            saldo_inicial_demo DECIMAL(10,2) DEFAULT 1000.00,
            percentual_ganho_demo DECIMAL(5,2) DEFAULT 80.00,
            limite_diario_demo DECIMAL(10,2) DEFAULT 500.00,
            duracao_demo INT DEFAULT 7,
            data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        
        // Inserir ou atualizar configurações
        $stmt = $pdo->prepare("
            INSERT INTO demo_config (id, saldo_inicial_demo, percentual_ganho_demo, limite_diario_demo, duracao_demo) 
            VALUES (1, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                saldo_inicial_demo = VALUES(saldo_inicial_demo),
                percentual_ganho_demo = VALUES(percentual_ganho_demo),
                limite_diario_demo = VALUES(limite_diario_demo),
                duracao_demo = VALUES(duracao_demo)
        ");
        
        $stmt->execute([
            floatval($_POST['saldo_inicial_demo'] ?? 1000),
            floatval($_POST['percentual_ganho_demo'] ?? 80),
            floatval($_POST['limite_diario_demo'] ?? 500),
            intval($_POST['duracao_demo'] ?? 7)
        ]);
        
        $_SESSION['success'] = 'Configurações de demo salvas com sucesso!';
        header('Location: controle_ganhos_demo.php');
        exit;
        
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Erro ao salvar configurações: ' . $e->getMessage();
    }
}

// Buscar contas demo
try {
    $contas_demo = $pdo->query("
        SELECT u.*, 
               COUNT(h.id) as total_jogadas,
               COALESCE(SUM(h.valor_apostado), 0) as total_apostado,
               COALESCE(SUM(h.valor_premiado), 0) as total_ganho
        FROM usuarios u
        LEFT JOIN historico_jogos h ON u.id = h.usuario_id
        WHERE u.conta_demo = 1
        GROUP BY u.id
        ORDER BY u.data_cadastro DESC
    ")->fetchAll();
} catch (PDOException $e) {
    $contas_demo = [];
}

// Buscar configurações atuais de demo
try {
    $config_demo = $pdo->query("SELECT * FROM demo_config LIMIT 1")->fetch();
    if (!$config_demo) {
        $config_demo = [
            'saldo_inicial_demo' => 1000.00,
            'percentual_ganho_demo' => 80.00,
            'limite_diario_demo' => 500.00,
            'duracao_demo' => 7
        ];
    }
} catch (PDOException $e) {
    $config_demo = [
        'saldo_inicial_demo' => 1000.00,
        'percentual_ganho_demo' => 80.00,
        'limite_diario_demo' => 500.00,
        'duracao_demo' => 7
    ];
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Controle de Ganhos Demo - Admin</title>
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--bg-panel);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 20px;
            text-align: center;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--primary-green), transparent);
            transition: var(--transition);
        }

        .stat-card:hover {
            border-color: var(--primary-green);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .stat-card:hover::before {
            left: 100%;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 800;
            color: var(--primary-green);
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Tables */
        .table-container {
            overflow: hidden;
        }

        .table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
            background: transparent;
        }

        .table th {
            padding: 14px;
            color: var(--primary-green);
            font-weight: 700;
            text-align: left;
            background: var(--bg-panel);
            border: 1px solid var(--border-color);
            user-select: none;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table th:first-child {
            border-radius: var(--radius) 0 0 var(--radius);
        }

        .table th:last-child {
            border-radius: 0 var(--radius) var(--radius) 0;
        }

        .table td {
            padding: 14px;
            color: var(--text-muted);
            vertical-align: middle;
            border: 1px solid var(--border-color);
            border-top: none;
            font-size: 13px;
            background: var(--bg-panel);
        }

        .table td:first-child {
            border-radius: var(--radius) 0 0 var(--radius);
            border-left: 1px solid var(--border-color);
        }

        .table td:last-child {
            border-radius: 0 var(--radius) var(--radius) 0;
            border-right: 1px solid var(--border-color);
        }

        .table tr:hover {
            background: rgba(0, 212, 170, 0.05);
            transform: translateY(-1px);
        }

        /* Form Styles */
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

        /* Buttons */
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

        .btn-secondary {
            background: var(--bg-card);
            color: var(--text-light);
            border: 1px solid var(--border-color);
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

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

        /* Status badges */
        .status-badge {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .status-demo {
            background: rgba(139, 92, 246, 0.15);
            color: var(--purple-color);
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

            .form-grid {
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

                <a href="controle_ganhos_demo.php" class="config-nav-item active">
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

                <a href="configurar_split.php" class="config-nav-item">
                    <i class="fas fa-chart-pie config-nav-icon"></i>
                    <div class="config-nav-title">Configurar Split</div>
                    <div class="config-nav-desc">Edição total do split do site</div>
                </a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-flask"></i>
                Controle de Ganhos Demo
            </h1>
            <div class="page-subtitle">
                <div class="live-indicator">
                    <div class="live-dot"></div>
                    <span>Sistema ativo</span>
                </div>
                <span>•</span>
                <span>Gerencie contas demonstração e controle de ganhos</span>
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

        <!-- Estatísticas de Contas Demo -->
        <div class="stats-grid">
            <?php
            try {
                $total_demo = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE conta_demo = 1")->fetchColumn();
                $demo_ativas = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE conta_demo = 1 AND ativo = 1")->fetchColumn();
                $jogadas_demo = $pdo->query("SELECT COUNT(*) FROM historico_jogos h JOIN usuarios u ON h.usuario_id = u.id WHERE u.conta_demo = 1")->fetchColumn();
                $volume_demo = $pdo->query("SELECT COALESCE(SUM(h.valor_apostado), 0) FROM historico_jogos h JOIN usuarios u ON h.usuario_id = u.id WHERE u.conta_demo = 1")->fetchColumn();
            } catch (PDOException $e) {
                $total_demo = $demo_ativas = $jogadas_demo = $volume_demo = 0;
            }
            ?>
            <div class="stat-card">
                <div class="stat-value"><?= number_format($total_demo) ?></div>
                <div class="stat-label">Contas Demo</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--success-color);"><?= number_format($demo_ativas) ?></div>
                <div class="stat-label">Demo Ativas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= number_format($jogadas_demo) ?></div>
                <div class="stat-label">Jogadas Demo</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">R$ <?= number_format($volume_demo, 2, ',', '.') ?></div>
                <div class="stat-label">Volume Demo</div>
            </div>
        </div>

        <!-- Configurações de Demo -->
        <form method="POST">
            <div class="card">
                <h3>
                    <i class="fas fa-sliders-h"></i>
                    Configurações de Contas Demo
                </h3>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Saldo Inicial Demo (R$)</label>
                        <input type="number" 
                               name="saldo_inicial_demo" 
                               class="form-input" 
                               value="<?= $config_demo['saldo_inicial_demo'] ?>" 
                               step="0.01" 
                               min="0">
                        <small style="color: var(--text-muted); font-size: 12px; margin-top: 4px; display: block;">
                            Saldo inicial para novas contas demo
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Percentual de Ganho Demo (%)</label>
                        <input type="number" 
                               name="percentual_ganho_demo" 
                               class="form-input" 
                               value="<?= $config_demo['percentual_ganho_demo'] ?>" 
                               step="0.1" 
                               min="0" 
                               max="100">
                        <small style="color: var(--text-muted); font-size: 12px; margin-top: 4px; display: block;">
                            Chance de ganho para contas demo (0-100%)
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Limite Diário Demo (R$)</label>
                        <input type="number" 
                               name="limite_diario_demo" 
                               class="form-input" 
                               value="<?= $config_demo['limite_diario_demo'] ?>" 
                               step="0.01" 
                               min="0">
                        <small style="color: var(--text-muted); font-size: 12px; margin-top: 4px; display: block;">
                            Limite máximo de apostas por dia
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Duração Demo (dias)</label>
                        <input type="number" 
                               name="duracao_demo" 
                               class="form-input" 
                               value="<?= $config_demo['duracao_demo'] ?>" 
                               min="1" 
                               max="30">
                        <small style="color: var(--text-muted); font-size: 12px; margin-top: 4px; display: block;">
                            Quantos dias a conta demo fica ativa
                        </small>
                    </div>
                </div>
                
                <div style="text-align: center; margin-top: 20px;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        Salvar Configurações Demo
                    </button>
                </div>
            </div>
        </form>

        <!-- Lista de Contas Demo -->
        <div class="card">
            <h3>
                <i class="fas fa-list"></i>
                Contas Demo Ativas
            </h3>
            
            <?php if (empty($contas_demo)): ?>
                <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                    <i class="fas fa-flask" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
                    <p>Nenhuma conta demo registrada.</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Usuário</th>
                                <th>Email</th>
                                <th>Saldo</th>
                                <th>Jogadas</th>
                                <th>Total Apostado</th>
                                <th>Total Ganho</th>
                                <th>Data Cadastro</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contas_demo as $demo): ?>
                                <tr>
                                    <td><?= $demo['id'] ?></td>
                                    <td style="color: var(--text-light); font-weight: 600;">
                                        <?= htmlspecialchars($demo['nome'] ?: 'Demo #' . $demo['id']) ?>
                                        <span class="status-badge status-demo" style="margin-left: 8px;">
                                            <i class="fas fa-flask"></i>
                                            DEMO
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($demo['email']) ?></td>
                                    <td style="color: var(--primary-gold); font-weight: 700;">
                                        R$ <?= number_format($demo['saldo'], 2, ',', '.') ?>
                                    </td>
                                    <td><?= number_format($demo['total_jogadas']) ?></td>
                                    <td style="color: var(--warning-color); font-weight: 700;">
                                        R$ <?= number_format($demo['total_apostado'], 2, ',', '.') ?>
                                    </td>
                                    <td style="color: var(--success-color); font-weight: 700;">
                                        R$ <?= number_format($demo['total_ganho'], 2, ',', '.') ?>
                                    </td>
                                    <td><?= date('d/m/Y H:i', strtotime($demo['data_cadastro'])) ?></td>
                                    <td>
                                        <button class="btn btn-warning btn-sm" onclick="resetDemo(<?= $demo['id'] ?>)">
                                            <i class="fas fa-redo"></i>
                                            Reset
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        // Menu do usuário
        function toggleUserMenu() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('show');
        }

        // Reset conta demo
        function resetDemo(userId) {
            if (confirm('Deseja resetar esta conta demo? O saldo será restaurado e o histórico mantido.')) {
                // Implementar reset via AJAX
                console.log('Reset demo user:', userId);
            }
        }

        // Fechar menus ao clicar fora
        document.addEventListener('click', function(event) {
            const userMenu = document.querySelector('.user-menu');
            
            if (!userMenu.contains(event.target)) {
                document.getElementById('userDropdown').classList.remove('show');
            }
        });

        // Tecla ESC para fechar menus
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                document.getElementById('userDropdown').classList.remove('show');
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