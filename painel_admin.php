<?php
session_start();

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    header('Location: login.php');
    exit;
}

require 'db.php';

// Configurar fuso horário para Brasília
date_default_timezone_set('America/Sao_Paulo');

// Buscar estatísticas para o header
try {
    $pendentes_depositos = $pdo->query("SELECT COUNT(*) FROM transacoes_pix WHERE status = 'pendente'")->fetchColumn();
    $pendentes_saques = $pdo->query("SELECT COUNT(*) FROM saques WHERE status = 'pendente'")->fetchColumn();
    $usuarios_online = $pdo->query("SELECT COUNT(DISTINCT usuario_id) FROM historico_jogos WHERE data_jogo >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetchColumn();
} catch (PDOException $e) {
    $pendentes_depositos = $pendentes_saques = $usuarios_online = 0;
}

// Buscar estatísticas gerais
try {
    $total_usuarios = $pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
    $usuarios_ativos = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE ativo = 1 AND conta_demo = 0")->fetchColumn();
    $total_depositos = $pdo->query("
        SELECT COALESCE(SUM(t.valor), 0) 
        FROM transacoes_pix t 
        JOIN usuarios u ON t.usuario_id = u.id 
        WHERE t.status = 'aprovado' AND u.conta_demo = 0
    ")->fetchColumn();
    
    // Separar saques por tipo
    $total_saques_jogadores = $pdo->query("
        SELECT COALESCE(SUM(s.valor), 0) 
        FROM saques s 
        JOIN usuarios u ON s.usuario_id = u.id 
        WHERE s.status = 'aprovado' AND u.conta_demo = 0 AND (s.tipo = 'saldo' OR s.tipo IS NULL)
    ")->fetchColumn();
    
    $total_saques_afiliados = $pdo->query("
        SELECT COALESCE(SUM(s.valor), 0) 
        FROM saques s 
        JOIN usuarios u ON s.usuario_id = u.id 
        WHERE s.status = 'aprovado' AND u.conta_demo = 0 AND s.tipo = 'comissao'
    ")->fetchColumn();
    
    $jogadas_hoje = $pdo->query("
        SELECT COUNT(*) 
        FROM historico_jogos h 
        JOIN usuarios u ON h.usuario_id = u.id 
        WHERE DATE(CONVERT_TZ(h.data_jogo, '+00:00', '-03:00')) = CURDATE() AND u.conta_demo = 0
    ")->fetchColumn();
    $receita_hoje = $pdo->query("
        SELECT COALESCE(SUM(h.valor_apostado - h.valor_premiado), 0) 
        FROM historico_jogos h 
        JOIN usuarios u ON h.usuario_id = u.id 
        WHERE DATE(CONVERT_TZ(h.data_jogo, '+00:00', '-03:00')) = CURDATE() AND u.conta_demo = 0
    ")->fetchColumn();
    
    // Estatísticas de afiliados
    $total_afiliados = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE codigo_afiliado IS NOT NULL AND codigo_afiliado != '' AND conta_demo = 0")->fetchColumn();
    $afiliados_ativos = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE afiliado_ativo = 1 AND conta_demo = 0")->fetchColumn();
    $comissoes_pendentes = $pdo->query("SELECT COALESCE(SUM(comissao), 0) FROM usuarios WHERE afiliado_ativo = 1 AND conta_demo = 0")->fetchColumn();
    
} catch (PDOException $e) {
    $total_usuarios = $usuarios_ativos = $total_depositos = $total_saques_jogadores = $total_saques_afiliados = $jogadas_hoje = $receita_hoje = 0;
    $total_afiliados = $afiliados_ativos = $comissoes_pendentes = 0;
}

// Buscar últimas jogadas
try {
    $ultimas_jogadas = $pdo->query("
        SELECT h.*, u.nome, u.email, r.nome as raspadinha_nome, u.conta_demo
        FROM historico_jogos h 
        LEFT JOIN usuarios u ON h.usuario_id = u.id 
        LEFT JOIN raspadinhas_config r ON h.raspadinha_id = r.id 
        WHERE u.conta_demo = 0
        ORDER BY h.data_jogo DESC 
        LIMIT 10
    ")->fetchAll();
} catch (PDOException $e) {
    $ultimas_jogadas = [];
}

// Buscar gráfico de receita dos últimos 7 dias
try {
    $receita_7_dias = $pdo->query("
        SELECT 
            DATE(CONVERT_TZ(h.data_jogo, '+00:00', '-03:00')) as data,
            COALESCE(SUM(h.valor_apostado - h.valor_premiado), 0) as receita
        FROM historico_jogos h
        JOIN usuarios u ON h.usuario_id = u.id
        WHERE CONVERT_TZ(h.data_jogo, '+00:00', '-03:00') >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND u.conta_demo = 0
        GROUP BY DATE(CONVERT_TZ(h.data_jogo, '+00:00', '-03:00'))
        ORDER BY data ASC
    ")->fetchAll();
} catch (PDOException $e) {
    $receita_7_dias = [];
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Administrativo</title>
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
            position: relative;
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

        /* Navigation */
        .nav-container {
            background: var(--bg-panel);
            border-bottom: 1px solid var(--border-color);
            padding: 20px 0;
        }

        .nav-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 24px;
        }

        .nav-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        .nav-item {
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

        .nav-item:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow);
            border-color: var(--primary-green);
        }

        .nav-item.active {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-gold));
            color: #000;
            box-shadow: 0 8px 32px rgba(0, 212, 170, 0.3);
        }

        .nav-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--primary-green), transparent);
            transition: var(--transition);
        }

        .nav-item:hover::before {
            left: 100%;
        }

        .nav-icon {
            font-size: 24px;
            margin-bottom: 12px;
            display: block;
        }

        .nav-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .nav-desc {
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: var(--bg-panel);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 24px;
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
            transform: translateY(-4px);
            box-shadow: var(--shadow);
        }

        .stat-card:hover::before {
            left: 100%;
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .stat-title {
            font-size: 14px;
            color: var(--text-muted);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 800;
            color: var(--text-light);
            margin-bottom: 8px;
        }

        .stat-change {
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .stat-change.positive {
            color: var(--success-color);
        }

        .stat-change.negative {
            color: var(--error-color);
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

        /* Chart Container */
        .chart-container {
            height: 300px;
            background: var(--bg-card);
            border-radius: var(--radius);
            padding: 20px;
            margin-top: 20px;
            border: 1px solid var(--border-color);
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
            max-height: 80vh;
            overflow: hidden;
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
            max-height: 400px;
            overflow-y: auto;
        }

        .user-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .user-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: var(--bg-card);
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .user-avatar-small {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--primary-green);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 12px;
            color: #000;
        }

        .user-info {
            flex: 1;
        }

        .user-name {
            font-weight: 500;
            color: var(--text-light);
            font-size: 14px;
        }

        .user-email {
            color: var(--text-muted);
            font-size: 12px;
        }

        .user-status {
            font-size: 11px;
            color: var(--success-color);
            font-weight: 500;
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

            .nav-content {
                padding: 0 16px;
            }

            .nav-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }

            .nav-item {
                padding: 16px;
            }

            .main-content {
                padding: 24px 16px;
            }

            .page-title {
                font-size: 24px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .header-stats {
                display: none;
            }

            .nav-grid {
                grid-template-columns: 1fr;
            }

            .page-title {
                font-size: 20px;
            }

            .page-subtitle {
                font-size: 14px;
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
                <div class="stat-item" onclick="showOnlineUsers()">
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
                    <a href="configuracoes_admin.php" class="dropdown-item">
                        <i class="fas fa-cog"></i>
                        <span>Configurações</span>
                    </a>
                    <a href="usuarios_admin.php" class="dropdown-item">
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

       <!-- Navigation -->
    <div class="nav-container">
        <div class="nav-content">
            <div class="nav-grid">
                <a href="painel_admin.php" class="nav-item">
                    <i class="fas fa-chart-line nav-icon"></i>
                    <div class="nav-title">Dashboard</div>
                    <div class="nav-desc">Visão geral e métricas</div>
                </a>

                <a href="usuarios_admin.php" class="nav-item">
                    <i class="fas fa-users nav-icon"></i>
                    <div class="nav-title">Usuários</div>
                    <div class="nav-desc">Gerenciamento de contas</div>
                </a>

                <a href="premios_admin.php" class="nav-item">
                    <i class="fas fa-gift nav-icon"></i>
                    <div class="nav-title">Produtos</div>
                    <div class="nav-desc">Biblioteca de prêmios</div>
                </a>

                <a href="admin_saques.php" class="nav-item">
                    <i class="fas fa-money-bill-wave nav-icon"></i>
                    <div class="nav-title">Saques</div>
                    <div class="nav-desc">Gerenciar saques PIX</div>
                </a>

                <a href="pix_admin.php" class="nav-item active">
                    <i class="fas fa-exchange-alt nav-icon"></i>
                    <div class="nav-title">Transações PIX</div>
                    <div class="nav-desc">Depósitos e pagamentos</div>
                </a>

                <a href="admin_afiliados.php" class="nav-item">
                    <i class="fas fa-handshake nav-icon"></i>
                    <div class="nav-title">Indicações</div>
                    <div class="nav-desc">Sistema de afiliados</div>
                </a>
            </div>
        </div>
    </div>
    
    <!-- Main Content -->
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-chart-line"></i>
                Dashboard Administrativo
            </h1>
            <div class="page-subtitle">
                <div class="live-indicator">
                    <div class="live-dot"></div>
                    <span>Sistema ativo</span>
                </div>
                <span>•</span>
                <span>Visão geral da plataforma em tempo real</span>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Total de Usuários</div>
                    <div class="stat-icon" style="background: rgba(59, 130, 246, 0.1); color: var(--info-color);">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="stat-value"><?= number_format($total_usuarios) ?></div>
                <div class="stat-change positive">
                    <i class="fas fa-arrow-up"></i>
                    <span><?= number_format($usuarios_ativos) ?> ativos</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Total Depositado</div>
                    <div class="stat-icon" style="background: rgba(0, 212, 170, 0.1); color: var(--primary-green);">
                        <i class="fas fa-arrow-down"></i>
                    </div>
                </div>
                <div class="stat-value">R$ <?= number_format($total_depositos, 0, ',', '.') ?></div>
                <div class="stat-change positive">
                    <i class="fas fa-arrow-up"></i>
                    <span>Volume total</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Saques Jogadores</div>
                    <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); color: var(--warning-color);">
                        <i class="fas fa-arrow-up"></i>
                    </div>
                </div>
                <div class="stat-value">R$ <?= number_format($total_saques_jogadores, 0, ',', '.') ?></div>
                <div class="stat-change">
                    <i class="fas fa-minus"></i>
                    <span>Saldo normal</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Saques Afiliados</div>
                    <div class="stat-icon" style="background: rgba(139, 92, 246, 0.1); color: var(--purple-color);">
                        <i class="fas fa-percentage"></i>
                    </div>
                </div>
                <div class="stat-value">R$ <?= number_format($total_saques_afiliados, 0, ',', '.') ?></div>
                <div class="stat-change">
                    <i class="fas fa-handshake"></i>
                    <span>Comissões</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Jogadas Hoje</div>
                    <div class="stat-icon" style="background: rgba(139, 92, 246, 0.1); color: var(--purple-color);">
                        <i class="fas fa-gamepad"></i>
                    </div>
                </div>
                <div class="stat-value"><?= number_format($jogadas_hoje) ?></div>
                <div class="stat-change positive">
                    <i class="fas fa-arrow-up"></i>
                    <span>Atividade</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Receita Hoje</div>
                    <div class="stat-icon" style="background: rgba(251, 206, 0, 0.1); color: var(--primary-gold);">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                </div>
                <div class="stat-value">R$ <?= number_format($receita_hoje, 0, ',', '.') ?></div>
                <div class="stat-change positive">
                    <i class="fas fa-arrow-up"></i>
                    <span>Lucro líquido</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Afiliados</div>
                    <div class="stat-icon" style="background: rgba(34, 197, 94, 0.1); color: var(--success-color);">
                        <i class="fas fa-handshake"></i>
                    </div>
                </div>
                <div class="stat-value"><?= number_format($total_afiliados) ?></div>
                <div class="stat-change positive">
                    <i class="fas fa-arrow-up"></i>
                    <span><?= number_format($afiliados_ativos) ?> ativos</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Comissões Pendentes</div>
                    <div class="stat-icon" style="background: rgba(239, 68, 68, 0.1); color: var(--error-color);">
                        <i class="fas fa-percentage"></i>
                    </div>
                </div>
                <div class="stat-value">R$ <?= number_format($comissoes_pendentes, 0, ',', '.') ?></div>
                <div class="stat-change">
                    <i class="fas fa-clock"></i>
                    <span>A pagar</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Usuários Online</div>
                    <div class="stat-icon" style="background: rgba(34, 197, 94, 0.1); color: var(--success-color);">
                        <i class="fas fa-circle"></i>
                    </div>
                </div>
                <div class="stat-value"><?= number_format($usuarios_online) ?></div>
                <div class="stat-change positive">
                    <i class="fas fa-arrow-up"></i>
                    <span>Agora</span>
                </div>
            </div>
        </div>

        <!-- Gráfico de Receita -->
        <div class="card">
            <h3>
                <i class="fas fa-chart-area"></i>
                Receita dos Últimos 7 Dias
            </h3>
            <div class="chart-container">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>

        <!-- Últimas Jogadas -->
        <div class="card" id="jogadas">
            <h3>
                <i class="fas fa-gamepad"></i>
                Últimas Jogadas
            </h3>
            
            <?php if (empty($ultimas_jogadas)): ?>
                <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                    <i class="fas fa-gamepad" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
                    <p>Nenhuma jogada registrada ainda.</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Usuário</th>
                                <th>Jogo</th>
                                <th>Apostado</th>
                                <th>Ganho</th>
                                <th>Resultado</th>
                                <th>Data</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ultimas_jogadas as $jogada): ?>
                                <tr>
                                    <td style="color: var(--text-light); font-weight: 600;">
                                        <?= htmlspecialchars($jogada['nome'] ?: 'Usuário #' . $jogada['usuario_id']) ?>
                                        <div style="font-size: 11px; color: var(--text-muted);">
                                            <?= htmlspecialchars($jogada['email']) ?>
                                        </div>
                                    </td>
                                    <td style="color: var(--primary-green);">
                                        <?= htmlspecialchars($jogada['raspadinha_nome'] ?: 'Jogo #' . $jogada['raspadinha_id']) ?>
                                    </td>
                                    <td style="color: var(--warning-color); font-weight: 700;">
                                        R$ <?= number_format($jogada['valor_apostado'], 2, ',', '.') ?>
                                    </td>
                                    <td style="color: var(--success-color); font-weight: 700;">
                                        R$ <?= number_format($jogada['valor_premiado'], 2, ',', '.') ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $lucro = $jogada['valor_apostado'] - $jogada['valor_premiado'];
                                        $cor = $lucro > 0 ? 'var(--success-color)' : ($lucro < 0 ? 'var(--error-color)' : 'var(--text-muted)');
                                        $icone = $lucro > 0 ? 'fa-arrow-up' : ($lucro < 0 ? 'fa-arrow-down' : 'fa-minus');
                                        ?>
                                        <span style="color: <?= $cor ?>; font-weight: 700;">
                                            <i class="fas <?= $icone ?>"></i>
                                            R$ <?= number_format(abs($lucro), 2, ',', '.') ?>
                                        </span>
                                    </td>
                                    <td><?= date('d/m/Y H:i', strtotime($jogada['data_jogo'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Modal Usuários Online -->
    <div id="onlineUsersModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-users"></i>
                    Usuários Online
                </h3>
                <button class="modal-close" onclick="closeOnlineModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div id="online-users-list" class="user-list">
                    <div style="text-align: center; padding: 20px; color: var(--text-muted);">
                        <i class="fas fa-spinner fa-spin"></i>
                        <p>Carregando usuários online...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Menu do usuário
        function toggleUserMenu() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('show');
        }

        // Modal usuários online
        function showOnlineUsers() {
            document.getElementById('onlineUsersModal').classList.add('show');
            loadOnlineUsers();
        }

        function closeOnlineModal() {
            document.getElementById('onlineUsersModal').classList.remove('show');
        }

        async function loadOnlineUsers() {
            try {
                const response = await fetch('get_online_users.php');
                const users = await response.json();
                
                const container = document.getElementById('online-users-list');
                
                if (users.length === 0) {
                    container.innerHTML = `
                        <div style="text-align: center; padding: 20px; color: var(--text-muted);">
                            <i class="fas fa-user-slash" style="font-size: 32px; margin-bottom: 12px; opacity: 0.5;"></i>
                            <p>Nenhum usuário online no momento</p>
                        </div>
                    `;
                    return;
                }
                
                container.innerHTML = users.map(user => `
                    <div class="user-item">
                        <div class="user-avatar-small">
                            ${(user.nome || user.email).charAt(0).toUpperCase()}
                        </div>
                        <div class="user-info">
                            <div class="user-name">${user.nome || 'Usuário'}</div>
                            <div class="user-email">${user.email}</div>
                        </div>
                        <div class="user-status">Online</div>
                    </div>
                `).join('');
            } catch (error) {
                console.error('Erro ao carregar usuários online:', error);
                document.getElementById('online-users-list').innerHTML = `
                    <div style="text-align: center; padding: 20px; color: var(--error-color);">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Erro ao carregar usuários</p>
                    </div>
                `;
            }
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

        // Gráfico de receita
        const ctx = document.getElementById('revenueChart').getContext('2d');
        const revenueData = <?= json_encode($receita_7_dias) ?>;
        
        const labels = revenueData.map(item => {
            const date = new Date(item.data);
            return date.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
        });
        
        const data = revenueData.map(item => parseFloat(item.receita));
        
        // Criar gradiente moderno 2025
        const gradient = ctx.createLinearGradient(0, 0, 0, 300);
        gradient.addColorStop(0, 'rgba(0, 212, 170, 0.8)');
        gradient.addColorStop(0.5, 'rgba(251, 206, 0, 0.4)');
        gradient.addColorStop(1, 'rgba(0, 212, 170, 0.05)');
        
        const borderGradient = ctx.createLinearGradient(0, 0, ctx.canvas.width, 0);
        borderGradient.addColorStop(0, '#00d4aa');
        borderGradient.addColorStop(0.5, '#fbce00');
        borderGradient.addColorStop(1, '#00d4aa');
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Receita (R$)',
                    data: data,
                    borderColor: borderGradient,
                    backgroundColor: gradient,
                    borderWidth: 4,
                    fill: true,
                    tension: 0.5,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 3,
                    pointRadius: 8,
                    pointHoverRadius: 12,
                    pointHoverBackgroundColor: '#fbce00',
                    pointHoverBorderColor: '#ffffff',
                    pointHoverBorderWidth: 4,
                    shadowOffsetX: 0,
                    shadowOffsetY: 4,
                    shadowBlur: 10,
                    shadowColor: 'rgba(0, 212, 170, 0.3)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                elements: {
                    point: {
                        hoverRadius: 12
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(17, 19, 24, 0.95)',
                        titleColor: '#ffffff',
                        bodyColor: '#8b949e',
                        borderColor: '#00d4aa',
                        borderWidth: 2,
                        cornerRadius: 12,
                        displayColors: false,
                        titleFont: {
                            size: 14,
                            weight: 'bold'
                        },
                        bodyFont: {
                            size: 13
                        },
                        callbacks: {
                            title: function(context) {
                                return 'Receita do dia ' + context[0].label;
                            },
                            label: function(context) {
                                return 'R$ ' + context.parsed.y.toLocaleString('pt-BR', {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                });
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        border: {
                            display: false
                        },
                        grid: {
                            color: 'rgba(255, 255, 255, 0.05)',
                            lineWidth: 1
                        },
                        ticks: {
                            color: '#8b949e',
                            font: {
                                size: 12,
                                weight: '500'
                            },
                            padding: 12,
                            callback: function(value) {
                                return 'R$ ' + value.toLocaleString('pt-BR', {
                                    minimumFractionDigits: 0,
                                    maximumFractionDigits: 0
                                });
                            }
                        }
                    },
                    x: {
                        border: {
                            display: false
                        },
                        grid: {
                            color: 'rgba(255, 255, 255, 0.05)',
                            lineWidth: 1
                        },
                        ticks: {
                            color: '#8b949e',
                            font: {
                                size: 12,
                                weight: '500'
                            },
                            padding: 8
                        }
                    }
                },
                animation: {
                    duration: 2000,
                    easing: 'easeInOutQuart'
                }
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
            setInterval(updateHeaderStats, 30000); // Atualizar a cada 30 segundos
        });
    </script>
</body>
</html>