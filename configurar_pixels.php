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

// Criar tabela de pixels se não existir
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pixels_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        plataforma VARCHAR(50) NOT NULL,
        pixel_id VARCHAR(255),
        codigo_head TEXT,
        codigo_body TEXT,
        ativo TINYINT(1) DEFAULT 1,
        data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    
    // Inserir pixels padrão se não existirem
    $pdo->exec("INSERT IGNORE INTO pixels_config (id, plataforma, pixel_id, codigo_head, codigo_body, ativo) VALUES
        (1, 'Facebook Pixel', '', '', '', 1),
        (2, 'Google Analytics', '', '', '', 1),
        (3, 'Google Ads', '', '', '', 1),
        (4, 'TikTok Pixel', '', '', '', 1),
        (5, 'Kwai Ads', '', '', '', 1)
    ");
} catch (PDOException $e) {
    // Tabela já existe
}

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        foreach ($_POST['pixels'] as $id => $data) {
            $stmt = $pdo->prepare("
                UPDATE pixels_config SET 
                    pixel_id = ?, 
                    codigo_head = ?, 
                    codigo_body = ?, 
                    ativo = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                trim($data['pixel_id'] ?? ''),
                trim($data['codigo_head'] ?? ''),
                trim($data['codigo_body'] ?? ''),
                isset($data['ativo']) ? 1 : 0,
                $id
            ]);
        }
        
        $_SESSION['success'] = 'Pixels configurados com sucesso!';
        header('Location: configurar_pixels.php');
        exit;
        
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Erro ao salvar pixels: ' . $e->getMessage();
    }
}

// Buscar pixels atuais
$pixels = $pdo->query("SELECT * FROM pixels_config ORDER BY id")->fetchAll();
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurar Pixels - Admin</title>
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

        /* Form Styles */
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

        .form-textarea {
            min-height: 120px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            resize: vertical;
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

        /* Platform Icons */
        .platform-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            margin-right: 12px;
        }

        .platform-icon.facebook { background: #1877f2; color: white; }
        .platform-icon.google { background: #4285f4; color: white; }
        .platform-icon.tiktok { background: #000000; color: white; }
        .platform-icon.kwai { background: #ff6b35; color: white; }
        .platform-icon.ads { background: #34a853; color: white; }

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

                <a href="configurar_pixels.php" class="config-nav-item active">
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
                <i class="fas fa-code"></i>
                Configurar Pixels de Rastreamento
            </h1>
            <div class="page-subtitle">
                <div class="live-indicator">
                    <div class="live-dot"></div>
                    <span>Sistema ativo</span>
                </div>
                <span>•</span>
                <span>Configure pixels do Facebook, Google, TikTok e Kwai</span>
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
            <?php foreach ($pixels as $pixel): ?>
                <div class="card">
                    <h3>
                        <?php
                        $icon_class = '';
                        $platform_class = '';
                        switch ($pixel['plataforma']) {
                            case 'Facebook Pixel':
                                $icon_class = 'fab fa-facebook';
                                $platform_class = 'facebook';
                                break;
                            case 'Google Analytics':
                                $icon_class = 'fab fa-google';
                                $platform_class = 'google';
                                break;
                            case 'Google Ads':
                                $icon_class = 'fab fa-google';
                                $platform_class = 'ads';
                                break;
                            case 'TikTok Pixel':
                                $icon_class = 'fab fa-tiktok';
                                $platform_class = 'tiktok';
                                break;
                            case 'Kwai Ads':
                                $icon_class = 'fas fa-video';
                                $platform_class = 'kwai';
                                break;
                        }
                        ?>
                        <div class="platform-icon <?= $platform_class ?>">
                            <i class="<?= $icon_class ?>"></i>
                        </div>
                        <?= $pixel['plataforma'] ?>
                    </h3>
                    
                    <div class="form-group">
                        <label class="form-label">ID do Pixel / Tracking ID</label>
                        <input type="text" 
                               name="pixels[<?= $pixel['id'] ?>][pixel_id]" 
                               class="form-input" 
                               value="<?= htmlspecialchars($pixel['pixel_id']) ?>" 
                               placeholder="Ex: 123456789012345">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Código para &lt;head&gt;</label>
                        <textarea name="pixels[<?= $pixel['id'] ?>][codigo_head]" 
                                  class="form-input form-textarea" 
                                  placeholder="Cole aqui o código que deve ser inserido no <head>"><?= htmlspecialchars($pixel['codigo_head']) ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Código para &lt;body&gt;</label>
                        <textarea name="pixels[<?= $pixel['id'] ?>][codigo_body]" 
                                  class="form-input form-textarea" 
                                  placeholder="Cole aqui o código que deve ser inserido no <body>"><?= htmlspecialchars($pixel['codigo_body']) ?></textarea>
                    </div>
                    
                    <div class="checkbox-wrapper">
                        <input type="checkbox" 
                               name="pixels[<?= $pixel['id'] ?>][ativo]" 
                               id="ativo_<?= $pixel['id'] ?>"
                               <?= $pixel['ativo'] ? 'checked' : '' ?>>
                        <label for="ativo_<?= $pixel['id'] ?>">Ativar este pixel</label>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <div style="text-align: center; margin: 40px 0;">
                <button type="submit" class="btn btn-primary" style="font-size: 16px; padding: 16px 32px;">
                    <i class="fas fa-save"></i>
                    Salvar Configurações de Pixels
                </button>
            </div>
        </form>

        <!-- Informações sobre Pixels -->
        <div class="card">
            <h3>
                <i class="fas fa-info-circle"></i>
                Informações sobre Pixels
            </h3>
            
            <div style="background: var(--bg-card); padding: 20px; border-radius: var(--radius); border: 1px solid var(--border-color);">
                <h4 style="color: var(--primary-green); margin-bottom: 12px;">Como Configurar:</h4>
                <ul style="color: var(--text-muted); line-height: 1.8; margin-left: 20px;">
                    <li><strong>Facebook Pixel:</strong> Copie o ID do pixel e o código do Gerenciador de Eventos</li>
                    <li><strong>Google Analytics:</strong> Use o ID de medição (G-XXXXXXXXXX)</li>
                    <li><strong>Google Ads:</strong> Configure o código de conversão</li>
                    <li><strong>TikTok Pixel:</strong> Copie o código do TikTok Ads Manager</li>
                    <li><strong>Kwai Ads:</strong> Configure o pixel do Kwai para Business</li>
                </ul>
                
                <h4 style="color: var(--primary-green); margin: 20px 0 12px;">Eventos Rastreados:</h4>
                <ul style="color: var(--text-muted); line-height: 1.8; margin-left: 20px;">
                    <li>🔍 <strong>PageView:</strong> Visualização de páginas</li>
                    <li>💰 <strong>Purchase:</strong> Depósitos realizados</li>
                    <li>🎯 <strong>Lead:</strong> Cadastros de usuários</li>
                    <li>🎮 <strong>AddToCart:</strong> Seleção de caixas</li>
                    <li>📝 <strong>CompleteRegistration:</strong> Cadastros completos</li>
                </ul>
                
                <div style="margin-top: 20px; padding: 12px; background: rgba(251, 206, 0, 0.1); border-radius: 8px; border-left: 4px solid var(--primary-gold);">
                    <strong style="color: var(--primary-gold);">⚠️ Importante:</strong>
                    <span style="color: var(--text-light);">Os pixels serão inseridos automaticamente em todas as páginas do site quando ativados.</span>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Menu do usuário
        function toggleUserMenu() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('show');
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