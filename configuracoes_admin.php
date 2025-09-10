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

// Buscar configurações atuais
try {
    $config = $pdo->query("SELECT * FROM configuracoes LIMIT 1")->fetch();
    if (!$config) {
        // Inserir configuração padrão se não existir
        $pdo->exec("INSERT INTO configuracoes (id, min_deposito, max_deposito, min_saque, max_saque, valor_comissao, valor_comissao_n2) VALUES (1, 5.00, 10000.00, 30.00, 350.00, 10.00, 5.00)");
        $config = $pdo->query("SELECT * FROM configuracoes LIMIT 1")->fetch();
    }
} catch (PDOException $e) {
    $config = [
        'min_deposito' => 5.00,
        'max_deposito' => 10000.00,
        'min_saque' => 30.00,
        'max_saque' => 350.00,
        'bonus_deposito' => 0,
        'valor_comissao' => 10.00,
        'valor_comissao_n2' => 5.00,
        'min_saque_comissao' => 10.00,
        'max_saque_comissao' => 1000.00
    ];
}

// Processar upload de imagens
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES)) {
    $hasFiles = false;
    foreach ($_FILES as $file) {
        if ($file['error'] === UPLOAD_ERR_OK) {
            $hasFiles = true;
            break;
        }
    }
    
    if ($hasFiles) {
        $jsonFile = 'imagens_menu.json';
        $imagens = file_exists($jsonFile) ? json_decode(file_get_contents($jsonFile), true) : [];
        
        $uploadDir = 'images/';
        $updated = false;
        
        // Processar cada upload
        foreach ($_FILES as $key => $file) {
            if ($file['error'] === UPLOAD_ERR_OK) {
                $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
                
                if (in_array($fileExtension, $allowedExtensions)) {
                    $fileName = $key . '.webp';
                    $filePath = $uploadDir . $fileName;
                    
                    // Converter para WebP se necessário
                    if (extension_loaded('gd')) {
                        $sourceImage = null;
                        switch ($fileExtension) {
                            case 'jpg':
                            case 'jpeg':
                                $sourceImage = imagecreatefromjpeg($file['tmp_name']);
                                break;
                            case 'png':
                                $sourceImage = imagecreatefrompng($file['tmp_name']);
                                break;
                            case 'webp':
                                $sourceImage = imagecreatefromwebp($file['tmp_name']);
                                break;
                        }
                        
                        if ($sourceImage) {
                            // Redimensionar se necessário
                            $maxWidth = ($key === 'banner1') ? 1200 : (($key === 'logo') ? 300 : 400);
                            $maxHeight = ($key === 'banner1') ? 400 : (($key === 'logo') ? 150 : 400);
                            
                            $originalWidth = imagesx($sourceImage);
                            $originalHeight = imagesy($sourceImage);
                            
                            if ($originalWidth > $maxWidth || $originalHeight > $maxHeight) {
                                $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
                                $newWidth = round($originalWidth * $ratio);
                                $newHeight = round($originalHeight * $ratio);
                                
                                $resized = imagecreatetruecolor($newWidth, $newHeight);
                                imagecopyresampled($resized, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
                                
                                imagewebp($resized, $filePath, 90);
                                imagedestroy($resized);
                            } else {
                                imagewebp($sourceImage, $filePath, 90);
                            }
                            
                            imagedestroy($sourceImage);
                            
                            // Atualizar JSON
                            $imagens[$key] = $fileName;
                            $updated = true;
                        }
                    } else {
                        // Se GD não estiver disponível, apenas mover o arquivo
                        move_uploaded_file($file['tmp_name'], $filePath);
                        $imagens[$key] = $fileName;
                        $updated = true;
                    }
                }
            }
        }
        
        if ($updated) {
            file_put_contents($jsonFile, json_encode($imagens, JSON_PRETTY_PRINT));
            $_SESSION['success'] = 'Imagens atualizadas com sucesso!';
            header('Location: configuracoes_admin.php');
            exit;
        } else {
            $_SESSION['error'] = 'Nenhuma imagem válida foi enviada.';
            header('Location: configuracoes_admin.php');
            exit;
        }
    }
}

// Processar configurações gerais
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_FILES)) {
    try {
        $stmt = $pdo->prepare("
            UPDATE configuracoes SET 
                min_deposito = ?, 
                max_deposito = ?, 
                min_saque = ?, 
                max_saque = ?, 
                bonus_deposito = ?, 
                valor_comissao = ?,
                valor_comissao_n2 = ?,
                min_saque_comissao = ?,
                max_saque_comissao = ?
            WHERE id = 1
        ");
        
        $stmt->execute([
            floatval($_POST['min_deposito']),
            floatval($_POST['max_deposito']),
            floatval($_POST['min_saque']),
            floatval($_POST['max_saque']),
            floatval($_POST['bonus_deposito']),
            floatval($_POST['valor_comissao']),
            floatval($_POST['valor_comissao_n2']),
            floatval($_POST['min_saque_comissao']),
            floatval($_POST['max_saque_comissao'])
        ]);
        
        $_SESSION['success'] = 'Configurações salvas com sucesso!';
        header('Location: configuracoes_admin.php');
        exit;
        
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Erro ao salvar configurações: ' . $e->getMessage();
    }
}

// Carregar imagens atuais
$jsonFile = 'imagens_menu.json';
$imagens = file_exists($jsonFile) ? json_decode(file_get_contents($jsonFile), true) : [];
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações Gerais - Admin</title>
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

        .form-input:hover {
            border-color: var(--primary-green);
        }

        /* Images Section */
        .images-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }

        .image-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 20px;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .image-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--primary-green), transparent);
            transition: var(--transition);
        }

        .image-card:hover {
            border-color: var(--primary-green);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .image-card:hover::before {
            left: 100%;
        }

        .image-preview {
            width: 100%;
            height: 150px;
            background: var(--bg-dark);
            border-radius: 8px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border: 2px dashed var(--border-color);
            transition: var(--transition);
        }

        .image-preview:hover {
            border-color: var(--primary-green);
        }

        .image-preview img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            border-radius: 6px;
        }

        .image-placeholder {
            color: var(--text-muted);
            text-align: center;
            font-size: 14px;
        }

        .image-title {
            font-weight: 600;
            color: var(--text-light);
            margin-bottom: 8px;
            font-size: 16px;
        }

        .image-desc {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 16px;
            line-height: 1.5;
        }

        .file-input-wrapper {
            position: relative;
            overflow: hidden;
            display: inline-block;
            width: 100%;
        }

        .file-input {
            position: absolute;
            left: -9999px;
            opacity: 0;
        }

        .file-input-label {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 16px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-gold));
            color: #000;
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            font-size: 14px;
        }

        .file-input-label:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 212, 170, 0.4);
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

        .btn-secondary:hover {
            border-color: var(--primary-green);
            background: rgba(0, 212, 170, 0.1);
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

        /* Save Button Fixed */
        .save-button-fixed {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 1000;
            box-shadow: 0 8px 32px rgba(0, 212, 170, 0.4);
            font-size: 16px;
            padding: 16px 24px;
        }

        .save-button-fixed:hover {
            transform: translateY(-4px) scale(1.05);
            box-shadow: 0 12px 40px rgba(0, 212, 170, 0.5);
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

            .images-grid {
                grid-template-columns: 1fr;
            }

            .save-button-fixed {
                bottom: 16px;
                right: 16px;
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
                <a href="configuracoes_admin.php" class="config-nav-item active">
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
                <i class="fas fa-cog"></i>
                Configurações Gerais
            </h1>
            <div class="page-subtitle">
                <div class="live-indicator">
                    <div class="live-dot"></div>
                    <span>Sistema ativo</span>
                </div>
                <span>•</span>
                <span>Configure limites, bônus, logo e banner principal</span>
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

        <!-- Configurações de Limites -->
        <form method="POST" id="configForm">
            <div class="card">
                <h3>
                    <i class="fas fa-money-bill-wave"></i>
                    Limites de Depósito e Saque
                </h3>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Depósito Mínimo (R$)</label>
                        <input type="number" 
                               name="min_deposito" 
                               class="form-input" 
                               value="<?= $config['min_deposito'] ?>" 
                               step="0.01" 
                               min="1" 
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Depósito Máximo (R$)</label>
                        <input type="number" 
                               name="max_deposito" 
                               class="form-input" 
                               value="<?= $config['max_deposito'] ?>" 
                               step="0.01" 
                               min="1" 
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Saque Mínimo (R$)</label>
                        <input type="number" 
                               name="min_saque" 
                               class="form-input" 
                               value="<?= $config['min_saque'] ?>" 
                               step="0.01" 
                               min="1" 
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Saque Máximo (R$)</label>
                        <input type="number" 
                               name="max_saque" 
                               class="form-input" 
                               value="<?= $config['max_saque'] ?>" 
                               step="0.01" 
                               min="1" 
                               required>
                    </div>
                </div>
            </div>

            <!-- Configurações de Bônus -->
            <div class="card">
                <h3>
                    <i class="fas fa-gift"></i>
                    Sistema de Bônus
                </h3>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Bônus de Depósito (%)</label>
                        <input type="number" 
                               name="bonus_deposito" 
                               class="form-input" 
                               value="<?= $config['bonus_deposito'] ?>" 
                               step="0.1" 
                               min="0" 
                               max="100">
                        <small style="color: var(--text-muted); font-size: 12px; margin-top: 4px; display: block;">
                            Percentual de bônus sobre depósitos (0 = desabilitado)
                        </small>
                    </div>
                </div>
            </div>

            <!-- Configurações de Afiliados -->
            <div class="card">
                <h3>
                    <i class="fas fa-handshake"></i>
                    Sistema de Afiliados
                </h3>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Comissão Nível 1 (%)</label>
                        <input type="number" 
                               name="valor_comissao" 
                               class="form-input" 
                               value="<?= $config['valor_comissao'] ?>" 
                               step="0.1" 
                               min="0" 
                               max="50" 
                               required>
                        <small style="color: var(--text-muted); font-size: 12px; margin-top: 4px; display: block;">
                            Comissão para quem indica diretamente
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Comissão Nível 2 (%)</label>
                        <input type="number" 
                               name="valor_comissao_n2" 
                               class="form-input" 
                               value="<?= $config['valor_comissao_n2'] ?>" 
                               step="0.1" 
                               min="0" 
                               max="25" 
                               required>
                        <small style="color: var(--text-muted); font-size: 12px; margin-top: 4px; display: block;">
                            Comissão para sub-afiliação (afiliado que indica outro afiliado)
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Saque Mínimo Comissão (R$)</label>
                        <input type="number" 
                               name="min_saque_comissao" 
                               class="form-input" 
                               value="<?= $config['min_saque_comissao'] ?>" 
                               step="0.01" 
                               min="1" 
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Saque Máximo Comissão (R$)</label>
                        <input type="number" 
                               name="max_saque_comissao" 
                               class="form-input" 
                               value="<?= $config['max_saque_comissao'] ?>" 
                               step="0.01" 
                               min="1" 
                               required>
                    </div>
                </div>
            </div>
        </form>

        <!-- Gerenciar Imagens -->
        <div class="card">
            <h3>
                <i class="fas fa-images"></i>
                Gerenciar Imagens do Site
            </h3>
            
            <form method="POST" enctype="multipart/form-data" id="imageForm">
                <!-- Logotipo -->
                <div class="images-grid">
                    <div class="image-card">
                        <div class="image-preview" id="preview-logo">
                            <?php if (isset($imagens['logo']) && file_exists('images/' . $imagens['logo'])): ?>
                                <img src="images/<?= $imagens['logo'] ?>?v=<?= time() ?>" alt="Logo atual">
                            <?php else: ?>
                                <div class="image-placeholder">
                                    <i class="fas fa-image" style="font-size: 32px; margin-bottom: 8px;"></i>
                                    <div>Nenhuma logo definida</div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="image-title">Logo Principal</div>
                        <div class="image-desc">Logo exibida no header do site. Recomendado: 300x150px</div>
                        <div class="file-input-wrapper">
                            <input type="file" name="logo" id="logo" class="file-input" accept="image/*" onchange="previewImage(this, 'preview-logo')">
                            <label for="logo" class="file-input-label">
                                <i class="fas fa-upload"></i>
                                Escolher Nova Logo
                            </label>
                        </div>
                    </div>

                    <!-- Banner Principal -->
                    <div class="image-card">
                        <div class="image-preview" id="preview-banner1">
                            <?php if (isset($imagens['banner1']) && file_exists('images/' . $imagens['banner1'])): ?>
                                <img src="images/<?= $imagens['banner1'] ?>?v=<?= time() ?>" alt="Banner atual">
                            <?php else: ?>
                                <div class="image-placeholder">
                                    <i class="fas fa-image" style="font-size: 32px; margin-bottom: 8px;"></i>
                                    <div>Nenhum banner definido</div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="image-title">Banner do Topo</div>
                        <div class="image-desc">Banner exibido no topo da página inicial. Recomendado: 1200x400px</div>
                        <div class="file-input-wrapper">
                            <input type="file" name="banner1" id="banner1" class="file-input" accept="image/*" onchange="previewImage(this, 'preview-banner1')">
                            <label for="banner1" class="file-input-label">
                                <i class="fas fa-upload"></i>
                                Escolher Novo Banner
                            </label>
                        </div>
                    </div>

                    <?php for ($i = 1; $i <= 8; $i++): ?>
                        <div class="image-card">
                            <div class="image-preview" id="preview-menu<?= $i ?>">
                                <?php if (isset($imagens["menu$i"]) && file_exists('images/' . $imagens["menu$i"])): ?>
                                    <img src="images/<?= $imagens["menu$i"] ?>?v=<?= time() ?>" alt="Caixa <?= $i ?> atual">
                                <?php else: ?>
                                    <div class="image-placeholder">
                                        <i class="fas fa-box" style="font-size: 32px; margin-bottom: 8px;"></i>
                                        <div>Caixa <?= $i ?> sem imagem</div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="image-title">Caixa de Prêmios <?= $i ?></div>
                            <div class="image-desc">Imagem da caixa <?= $i ?> exibida no menu principal. Recomendado: 400x400px</div>
                            <div class="file-input-wrapper">
                                <input type="file" name="menu<?= $i ?>" id="menu<?= $i ?>" class="file-input" accept="image/*" onchange="previewImage(this, 'preview-menu<?= $i ?>')">
                                <label for="menu<?= $i ?>" class="file-input-label">
                                    <i class="fas fa-upload"></i>
                                    Escolher Imagem
                                </label>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>

                <!-- Botão Salvar Imagens -->
                <div style="text-align: center; margin: 20px 0;">
                    <button type="submit" class="btn btn-primary" style="font-size: 16px; padding: 16px 32px;">
                        <i class="fas fa-save"></i>
                        Salvar Imagens
                    </button>
                </div>
            </form>
        </div>

        <!-- Informações Técnicas -->
        <div class="card">
            <h3>
                <i class="fas fa-info-circle"></i>
                Informações Técnicas
            </h3>
            
            <div style="background: var(--bg-card); padding: 20px; border-radius: var(--radius); border: 1px solid var(--border-color);">
                <h4 style="color: var(--primary-green); margin-bottom: 12px;">Formatos Suportados:</h4>
                <ul style="color: var(--text-muted); line-height: 1.8; margin-left: 20px;">
                    <li>JPG/JPEG - Boa compressão para fotos</li>
                    <li>PNG - Suporte a transparência</li>
                    <li>WebP - Formato moderno com melhor compressão</li>
                </ul>
                
                <h4 style="color: var(--primary-green); margin: 20px 0 12px;">Recomendações:</h4>
                <ul style="color: var(--text-muted); line-height: 1.8; margin-left: 20px;">
                    <li><strong>Logo:</strong> 300x150px ou proporção 2:1</li>
                    <li><strong>Banner:</strong> 1200x400px ou proporção 3:1</li>
                    <li><strong>Caixas:</strong> 400x400px (quadrado)</li>
                    <li><strong>Tamanho:</strong> Máximo 2MB por imagem</li>
                </ul>
                
                <div style="margin-top: 20px; padding: 12px; background: rgba(251, 206, 0, 0.1); border-radius: 8px; border-left: 4px solid var(--primary-gold);">
                    <strong style="color: var(--primary-gold);">💡 Dica:</strong>
                    <span style="color: var(--text-light);">As imagens são automaticamente otimizadas e convertidas para WebP para melhor performance.</span>
                </div>
            </div>
        </div>
    </main>

    <!-- Botão Salvar Configurações Flutuante -->
    <button type="submit" form="configForm" class="btn btn-primary save-button-fixed">
        <i class="fas fa-save"></i>
        Salvar Configurações
    </button>

    <script>
        // Menu do usuário
        function toggleUserMenu() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('show');
        }

        // Preview de imagem
        function previewImage(input, previewId) {
            const preview = document.getElementById(previewId);
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    preview.innerHTML = `<img src="${e.target.result}" alt="Preview">`;
                };
                
                reader.readAsDataURL(input.files[0]);
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

        // Validação do formulário
        document.getElementById('configForm').addEventListener('submit', function(e) {
            const minDeposito = parseFloat(document.querySelector('input[name="min_deposito"]').value);
            const maxDeposito = parseFloat(document.querySelector('input[name="max_deposito"]').value);
            const minSaque = parseFloat(document.querySelector('input[name="min_saque"]').value);
            const maxSaque = parseFloat(document.querySelector('input[name="max_saque"]').value);
            
            if (minDeposito >= maxDeposito) {
                e.preventDefault();
                alert('Depósito mínimo deve ser menor que o máximo!');
                return;
            }
            
            if (minSaque >= maxSaque) {
                e.preventDefault();
                alert('Saque mínimo deve ser menor que o máximo!');
                return;
            }
            
            // Animação do botão
            const btn = document.querySelector('.save-button-fixed');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
            btn.disabled = true;
        });

        // Animação do formulário de imagens
        document.getElementById('imageForm').addEventListener('submit', function(e) {
            // Verificar se pelo menos um arquivo foi selecionado
            const fileInputs = this.querySelectorAll('input[type="file"]');
            let hasFile = false;
            
            fileInputs.forEach(input => {
                if (input.files && input.files.length > 0) {
                    hasFile = true;
                }
            });
            
            if (!hasFile) {
                e.preventDefault();
                alert('Selecione pelo menos uma imagem para fazer upload!');
                return;
            }
            
            const btn = this.querySelector('button[type="submit"]');
            if (btn) {
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
                btn.disabled = true;
            }
        });

        // Inicializar
        document.addEventListener('DOMContentLoaded', function() {
            updateHeaderStats();
            setInterval(updateHeaderStats, 30000);
        });
    </script>
</body>
</html>