<?php
session_start();

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    header('Location: login.php');
    exit;
}

require 'db.php';

// Buscar produtos/raspadinhas
try {
    $stmt = $pdo->query("SELECT * FROM raspadinhas_config ORDER BY valor ASC");
    $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Garantir que todos os produtos tenham a chave 'ativo'
    foreach ($produtos as &$produto) {
        if (!isset($produto['ativo'])) {
            $produto['ativo'] = 1; // Padrão ativo
        }
    }
    unset($produto); // Limpar referência
    
} catch (PDOException $e) {
    $produtos = [];
}

// Buscar estatísticas
try {
    $total_produtos = count($produtos);
    $produtos_ativos = count(array_filter($produtos, function($p) { return $p['ativo'] == 1; }));
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM historico_jogos WHERE DATE(data_jogo) = CURDATE()");
    $jogadas_hoje = $stmt->fetchColumn() ?: 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM historico_jogos WHERE premio_ganho > 0 AND DATE(data_jogo) = CURDATE()");
    $premios_hoje = $stmt->fetchColumn() ?: 0;
    
    $stmt = $pdo->query("
        SELECT r.nome, COUNT(*) as total 
        FROM historico_jogos h 
        JOIN raspadinhas_config r ON h.produto_id = r.id 
        WHERE DATE(h.data_jogo) = CURDATE() 
        GROUP BY h.produto_id, r.nome 
        ORDER BY total DESC 
        LIMIT 1
    ");
    $produto_popular = $stmt->fetch();
    $produto_popular_nome = $produto_popular ? $produto_popular['nome'] : 'Nenhum';
    
} catch (PDOException $e) {
    $total_produtos = 0;
    $produtos_ativos = 0;
    $jogadas_hoje = 0;
    $premios_hoje = 0;
    $produto_popular_nome = 'Erro';
}

// Processar requisições AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'toggle_produto':
                $produto_id = intval($_POST['produto_id']);
                $ativo = intval($_POST['ativo']);
                
                $stmt = $pdo->prepare("UPDATE raspadinhas_config SET ativo = ? WHERE id = ?");
                $result = $stmt->execute([$ativo, $produto_id]);
                
                echo json_encode(['success' => $result]);
                break;
                
            case 'salvar_produto':
                $produto_id = intval($_POST['produto_id']);
                $nome = trim($_POST['nome']);
                $valor = floatval($_POST['valor']);
                $chance_ganho = floatval($_POST['chance_ganho']);
                
                if ($produto_id > 0) {
                    // Editar
                    $stmt = $pdo->prepare("UPDATE raspadinhas_config SET nome = ?, valor = ?, chance_ganho = ? WHERE id = ?");
                    $result = $stmt->execute([$nome, $valor, $chance_ganho, $produto_id]);
                } else {
                    // Criar novo
                    $stmt = $pdo->prepare("INSERT INTO raspadinhas_config (nome, valor, chance_ganho, premios_json, ativo) VALUES (?, ?, ?, '{}', 1)");
                    $result = $stmt->execute([$nome, $valor, $chance_ganho]);
                }
                
                echo json_encode(['success' => $result]);
                break;
                
            case 'excluir_produto':
                $produto_id = intval($_POST['produto_id']);
                
                $stmt = $pdo->prepare("DELETE FROM raspadinhas_config WHERE id = ?");
                $result = $stmt->execute([$produto_id]);
                
                echo json_encode(['success' => $result]);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Ação inválida']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Produtos - Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
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

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow);
            border-color: var(--primary-green);
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

        .stat-card:hover::before {
            left: 100%;
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: #000;
        }

        .stat-icon.produtos { background: linear-gradient(135deg, var(--primary-green), #00b894); }
        .stat-icon.ativos { background: linear-gradient(135deg, var(--success-color), #16a34a); }
        .stat-icon.jogadas { background: linear-gradient(135deg, var(--info-color), #2563eb); }
        .stat-icon.premios { background: linear-gradient(135deg, var(--warning-color), #f59e0b); }
        .stat-icon.popular { background: linear-gradient(135deg, var(--purple-color), #7c3aed); }

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
            font-weight: 600;
            color: var(--success-color);
        }

        .stat-value {
            font-size: 28px;
            font-weight: 800;
            color: var(--text-light);
            margin-bottom: 4px;
        }

        .stat-label {
            color: var(--text-muted);
            font-size: 14px;
            margin-bottom: 8px;
        }

        .stat-detail {
            color: var(--text-muted);
            font-size: 12px;
        }

        /* Tabs */
        .tabs-container {
            margin-bottom: 32px;
        }

        .tabs-nav {
            display: flex;
            gap: 4px;
            background: var(--bg-panel);
            border-radius: var(--radius);
            padding: 4px;
            border: 1px solid var(--border-color);
        }

        .tab-btn {
            flex: 1;
            padding: 12px 20px;
            background: transparent;
            border: none;
            border-radius: 8px;
            color: var(--text-muted);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .tab-btn.active {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-gold));
            color: #000;
            box-shadow: 0 2px 8px rgba(0, 212, 170, 0.3);
        }

        .tab-btn:hover:not(.active) {
            background: var(--bg-card);
            color: var(--text-light);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
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
            color: var(--text-light);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 18px;
            font-weight: 700;
        }

        /* Seção de Gerenciamento */
        .management-section {
            background: var(--bg-panel);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 24px;
        }

        .management-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border-color);
        }

        .management-title {
            color: var(--primary-green);
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .add-product-btn {
            background: linear-gradient(135deg, var(--primary-green), #00b894);
            color: #000;
            border: none;
            padding: 12px 20px;
            border-radius: var(--radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .add-product-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 212, 170, 0.4);
        }

        /* Product Grid */
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
        }

        .product-card {
            background: var(--bg-panel);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 20px;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .product-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow);
            border-color: var(--primary-green);
        }

        .product-card.add-new {
            border: 2px dashed var(--border-color);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 200px;
            cursor: pointer;
            text-align: center;
        }

        .product-card.add-new:hover {
            border-color: var(--primary-green);
            background: rgba(0, 212, 170, 0.05);
        }

        .product-card.add-new i {
            font-size: 48px;
            color: var(--primary-green);
            margin-bottom: 16px;
        }

        .product-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .product-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-gold));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: #000;
        }

        .product-toggle {
            position: relative;
            width: 48px;
            height: 24px;
        }

        .toggle-input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--border-color);
            transition: var(--transition);
            border-radius: 24px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background: white;
            transition: var(--transition);
            border-radius: 50%;
        }

        .toggle-input:checked + .toggle-slider {
            background: var(--primary-green);
        }

        .toggle-input:checked + .toggle-slider:before {
            transform: translateX(24px);
        }

        .product-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-light);
            margin-bottom: 8px;
        }

        .product-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }

        .info-item {
            background: var(--bg-card);
            padding: 12px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .info-label {
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .info-value {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-light);
        }

        .product-actions {
            display: flex;
            gap: 8px;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            font-size: 12px;
            flex: 1;
            justify-content: center;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-green), #00b894);
            color: #000;
        }

        .btn-secondary {
            background: var(--bg-card);
            color: var(--text-light);
            border: 1px solid var(--border-color);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--error-color), #dc2626);
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(5px);
            z-index: 1000;
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
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-gold));
            color: #000;
            padding: 20px;
            border-radius: var(--radius) var(--radius) 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #000;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: var(--transition);
        }

        .modal-close:hover {
            background: rgba(0, 0, 0, 0.1);
        }

        .modal-body {
            padding: 24px;
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
            border-radius: 8px;
            color: var(--text-light);
            font-size: 14px;
            transition: var(--transition);
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(0, 212, 170, 0.1);
        }

        .modal-footer {
            padding: 20px 24px;
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }

        /* Messages */
        .message {
            padding: 16px 20px;
            border-radius: var(--radius);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            animation: slideInDown 0.4s ease;
        }

        .message.success {
            background: rgba(34, 197, 94, 0.15);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: var(--success-color);
        }

        .message.error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
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

        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .product-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 16px;
            text-align: center;
            transition: var(--transition);
            min-height: 300px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .product-card:hover {
            border-color: var(--primary-green);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .product-image {
            width: 80px;
            height: 80px;
            margin: 0 auto 12px;
            border-radius: 8px;
            overflow: hidden;
            background: var(--bg-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-dark);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .product-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 4px;
        }

        .product-value {
            font-size: 16px;
            font-weight: 600;
            min-height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: var(--primary-green);
            margin-bottom: 4px;
        }

        .product-chance {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 12px;
        }

        .product-actions {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin-top: auto;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .nav-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 768px) {
            .header-content {
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
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .products-grid {
                grid-template-columns: 1fr;
            }

            .tabs-nav {
                flex-direction: column;
            }

            .tab-btn {
                justify-content: flex-start;
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

            .product-info {
                grid-template-columns: 1fr;
            }

            .product-actions {
                flex-direction: column;
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
                    <span id="online-count">0</span>
                    <i class="fas fa-users"></i>
                </div>

                <a href="pix_admin.php" class="stat-item">
                    <div class="stat-dot deposito"></div>
                    <span>Depósito</span>
                    <div class="stat-badge" id="deposito-count">0</div>
                </a>

                <a href="admin_saques.php" class="stat-item">
                    <div class="stat-dot saque"></div>
                    <span>Saque</span>
                    <div class="stat-badge" id="saque-count">0</div>
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
                <i class="fas fa-gift"></i>
                Gerenciar Produtos
            </h1>
            <div class="page-subtitle">
                <div class="live-indicator">
                    <div class="live-dot"></div>
                    <span>Sistema de prêmios ativo</span>
                </div>
                <span>•</span>
                <span>Última atualização: <span id="last-update"><?= date('H:i:s') ?></span></span>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon produtos">
                        <i class="fas fa-box"></i>
                    </div>
                    <div class="stat-trend">
                        <i class="fas fa-chart-line"></i>
                        <span>Total</span>
                    </div>
                </div>
                <div class="stat-value"><?= number_format($total_produtos) ?></div>
                <div class="stat-label">Total de Produtos</div>
                <div class="stat-detail">Produtos cadastrados no sistema</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon ativos">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-trend">
                        <i class="fas fa-arrow-up"></i>
                        <span>Ativos</span>
                    </div>
                </div>
                <div class="stat-value"><?= number_format($produtos_ativos) ?></div>
                <div class="stat-label">Produtos Ativos</div>
                <div class="stat-detail">Disponíveis para os usuários</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon jogadas">
                        <i class="fas fa-play-circle"></i>
                    </div>
                    <div class="stat-trend" style="color: var(--info-color);">
                        <i class="fas fa-clock"></i>
                        <span>24h</span>
                    </div>
                </div>
                <div class="stat-value"><?= number_format($jogadas_hoje) ?></div>
                <div class="stat-label">Jogadas Hoje</div>
                <div class="stat-detail">Atividade do dia atual</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon premios">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <div class="stat-trend" style="color: var(--warning-color);">
                        <i class="fas fa-gift"></i>
                        <span>Ganhos</span>
                    </div>
                </div>
                <div class="stat-value"><?= number_format($premios_hoje) ?></div>
                <div class="stat-label">Prêmios Hoje</div>
                <div class="stat-detail">Prêmios distribuídos hoje</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon popular">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="stat-trend" style="color: var(--purple-color);">
                        <i class="fas fa-fire"></i>
                        <span>Popular</span>
                    </div>
                </div>
                <div class="stat-value" style="font-size: 18px;"><?= htmlspecialchars($produto_popular_nome) ?></div>
                <div class="stat-label">Produto Mais Jogado</div>
                <div class="stat-detail">Produto com mais jogadas hoje</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs-container">
            <div class="tabs-nav">
                <button class="tab-btn active" onclick="switchTab('configuracoes')">
                    <i class="fas fa-cog"></i>
                    <span>Configurações</span>
                </button>
                <button class="tab-btn" onclick="switchTab('produtos')">
                    <i class="fas fa-plus-circle"></i>
                    <span>Produtos</span>
                </button>
                <button class="tab-btn" onclick="switchTab('imersao')">
                    <i class="fas fa-magic"></i>
                    <span>Sistema de Imersão</span>
                </button>
            </div>
        </div>

        <!-- Tab Content: Configurações -->
        <div id="tab-configuracoes" class="tab-content active">
            <div class="card">
                <h3>
                    <i class="fas fa-cog"></i> Gerenciar Todos os Produtos/Caixas
                </h3>
                
                <div class="products-grid">
                    <!-- Card Adicionar Novo -->
                    <div class="product-card add-new" onclick="openProductModal()">
                        <i class="fas fa-plus"></i>
                        <h3 style="color: var(--primary-green); margin: 0;">Adicionar Produto</h3>
                        <p style="color: var(--text-muted); margin-top: 8px;">Criar nova caixa/raspadinha</p>
                    </div>

                    <!-- Cards dos Produtos -->
                    <?php foreach ($produtos as $produto): ?>
                        <div class="product-card">
                            <div class="product-header">
                                <div class="product-icon">
                                    <i class="fas fa-gift"></i>
                                </div>
                                <label class="product-toggle">
                                    <input type="checkbox" 
                                           class="toggle-input" 
                                           <?= isset($produto['ativo']) && $produto['ativo'] ? 'checked' : '' ?>
                                           onchange="toggleProduct(<?= $produto['id'] ?>, this.checked)">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>

                            <h3 class="product-title"><?= htmlspecialchars($produto['nome']) ?></h3>

                            <div class="product-info">
                                <div class="info-item">
                                    <div class="info-label">Valor</div>
                                    <div class="info-value">R$ <?= number_format($produto['valor'], 2, ',', '.') ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Chance de Ganho</div>
                                    <div class="info-value"><?= number_format($produto['chance_ganho'], 2) ?>%</div>
                                </div>
                            </div>

                            <div class="info-item" style="margin-bottom: 16px;">
                                <div class="info-label">Prêmios Disponíveis</div>
                                <div class="info-value">
                                    <?php
                                    $premios = json_decode($produto['premios_json'], true) ?: [];
                                    $total_premios = is_array($premios) ? count($premios) : 0;
                                    echo $total_premios . ' prêmios';
                                    ?>
                                </div>
                            </div>

                            <div class="product-actions">
                                <button class="btn btn-primary" onclick="editProduct(<?= $produto['id'] ?>, '<?= htmlspecialchars($produto['nome']) ?>', <?= $produto['valor'] ?>, <?= $produto['chance_ganho'] ?>)">
                                    <i class="fas fa-edit"></i>
                                    Editar
                                </button>
                                <button class="btn btn-secondary" onclick="managePrizes('<?= $produto['id'] ?>', '<?= htmlspecialchars($produto['nome']) ?>')">
                                    <i class="fas fa-gift"></i>
                                    Prêmios
                                </button>
                                <button class="btn btn-danger" onclick="deleteProduct(<?= $produto['id'] ?>, '<?= htmlspecialchars($produto['nome']) ?>')">
                                    <i class="fas fa-trash"></i>
                                    Excluir
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Tab Content: Produtos -->
        <div id="tab-produtos" class="tab-content">
            <div class="card">
                <h3 id="selected-package-title">
                    <i class="fas fa-gift"></i> Produtos da Caixa
                </h3>
                
                <div class="management-section" id="management-section" style="display: none;">
                    <div class="management-header">
                        <div class="management-title">
                            <i class="fas fa-cogs"></i>
                            Gerenciar Produtos da Caixa
                        </div>
                        <button class="add-product-btn" onclick="openAddProductModal()">
                            <i class="fas fa-plus"></i>
                            Adicionar Produto
                        </button>
                    </div>
                    
                    <div id="products-grid" class="products-grid">
                        <!-- Produtos serão carregados aqui -->
                    </div>
                </div>
                
                <div id="empty-products" class="empty-products">
                    <i class="fas fa-cube"></i>
                    <h4>Nenhuma caixa selecionada</h4>
                    <p>Clique em "Prêmios" em uma das caixas acima para gerenciar seus produtos.</p>
                </div>
            </div>
        </div>

        <!-- Tab Content: Sistema de Imersão -->
        <div id="tab-imersao" class="tab-content">
            <div class="card">
                <h3>
                    <i class="fas fa-magic"></i> Sistema de Imersão
                </h3>
                <p style="color: var(--text-muted); margin-bottom: 24px;">
                    Configure elementos visuais e sonoros para aumentar o engajamento dos usuários
                </p>
                
                <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                    <i class="fas fa-tools" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
                    <p>Em desenvolvimento...</p>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal Produto -->
    <div id="productModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modal-title">Adicionar Produto</h3>
                <button class="modal-close" onclick="closeProductModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="productForm">
                    <input type="hidden" id="produto_id" value="0">
                    
                    <div class="form-group">
                        <label class="form-label" for="nome">Nome do Produto</label>
                        <input type="text" id="nome" class="form-input" placeholder="Ex: Caixa Misteriosa Premium" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="valor">Valor (R$)</label>
                        <input type="number" id="valor" class="form-input" step="0.01" min="0.01" placeholder="0.00" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="chance_ganho">Chance de Ganho (%)</label>
                        <input type="number" id="chance_ganho" class="form-input" step="0.01" min="0" max="100" placeholder="0.00" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeProductModal()">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="saveProduct()">Salvar</button>
            </div>
        </div>
    </div>

    <script>
        // Variáveis globais
        let selectedPackageId = null;
        let selectedPackageName = null;

        // Função para trocar de aba
        function switchTab(tabName) {
            console.log('switchTab chamada com:', tabName);
            
            // Remover classe active de todos os botões e conteúdos
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            // Adicionar classe active no botão e conteúdo corretos
            document.querySelector(`[onclick="switchTab('${tabName}')"]`).classList.add('active');
            document.getElementById(`tab-${tabName}`).classList.add('active');
            
            // Se mudou para produtos, esconder seção de gerenciamento se não há caixa selecionada
            if (tabName === 'produtos' && !selectedPackageId) {
                document.getElementById('management-section').style.display = 'none';
                document.getElementById('empty-products').style.display = 'block';
            }
            
            console.log('Aba alterada para:', tabName);
        }

        // Função para gerenciar prêmios
        function managePrizes(packageId, packageName) {
            console.log('managePrizes chamada com:', packageId, packageName);
            
            try {
                selectedPackageId = packageId;
                selectedPackageName = packageName;
                
                // Mudar para aba produtos
                switchTab('produtos');
                
                // Aguardar um pouco e carregar produtos
                setTimeout(() => {
                    loadPackageProducts(packageId, packageName);
                }, 100);
                
            } catch (error) {
                console.error('Erro em managePrizes:', error);
                alert('Erro ao carregar produtos da caixa');
            }
        }

        // Função para carregar produtos de uma caixa
        function loadPackageProducts(packageId, packageName) {
            console.log('loadPackageProducts chamada com:', packageId, packageName);
            
            // Garantir que packageId é um número
            const numericPackageId = parseInt(packageId);
            if (isNaN(numericPackageId) || numericPackageId <= 0) {
                console.error('Package ID inválido:', packageId);
                return;
            }
            
            // Mostrar seção de gerenciamento
            document.getElementById('management-section').style.display = 'block';
            document.getElementById('empty-products').style.display = 'none';
            
            const productsContainer = document.getElementById('products-grid');
            const selectedPackageTitle = document.getElementById('selected-package-title');
            
            if (!productsContainer) {
                console.error('Container de produtos não encontrado');
                return;
            }
            
            if (selectedPackageTitle) {
                selectedPackageTitle.innerHTML = `
                    <i class="fas fa-box-open"></i>
                    Produtos da Caixa: ${packageName}
                `;
            }
            
            // Mostrar loading
            productsContainer.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-spin" style="font-size: 32px; color: var(--primary-green);"></i><br><br>Carregando produtos...</div>';
            
            // Fazer requisição para buscar produtos
            fetch('get_package_products.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: JSON.stringify({ packageId: numericPackageId })
            })
            .then(response => response.json())
            .then(data => {
                console.log('Resposta do servidor:', data);
                
                if (data.success) {
                    console.log('Produtos encontrados:', data.products);
                    displayProducts(data.products);
                } else {
                    console.error('Erro do servidor:', data.message);
                    alert('Erro ao carregar produtos: ' + data.message);
                    productsContainer.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--error-color);"><i class="fas fa-exclamation-triangle" style="font-size: 32px; margin-bottom: 16px;"></i><br>Erro ao carregar produtos: ' + (data.message || 'Erro desconhecido') + '</div>';
                }
            })
            .catch(error => {
                console.error('Erro na requisição:', error);
                alert('Erro na comunicação com o servidor');
                productsContainer.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--error-color);"><i class="fas fa-exclamation-triangle" style="font-size: 32px; margin-bottom: 16px;"></i><br>Erro de conexão</div>';
            });
        }

        // Função para exibir produtos
        function displayProducts(products) {
            console.log('displayProducts chamada com:', products);
            
            const container = document.getElementById('products-grid');
            if (!container) {
                console.error('Container products-grid não encontrado');
                return;
            }
            
            if (!products || products.length === 0) {
                container.innerHTML = '<p style="text-align: center; color: var(--text-muted); padding: 40px;">Nenhum produto cadastrado nesta caixa.</p>';
                return;
            }
            
            let html = '';
            
            // Produtos existentes
            products.forEach(product => {
                const productName = product.nome || `Prêmio R$ ${parseFloat(product.valor || 0).toFixed(2).replace('.', ',')}`;
                const productValue = parseFloat(product.valor || 0);
                const productChance = parseFloat(product.chance || 0);
                
                // Determinar caminho da imagem
                let imagePath = 'images/caixa/default.png';
                if (productValue > 0) {
                    imagePath = `images/caixa/${productValue}.webp`;
                }
                
                html += `
                    <div class="product-card">
                        <img src="${imagePath}" 
                             alt="${productName}" 
                             class="product-image"
                             onerror="this.src='images/caixa/default.png'">
                        <div class="product-info">
                            <h4 class="product-name">${productName}</h4>
                            <div class="product-value">R$ ${productValue.toFixed(2).replace('.', ',')}</div>
                            <div class="product-chance">${productChance.toFixed(2)}% de chance</div>
                        </div>
                        <div class="product-actions">
                            <button class="btn-edit" onclick="editProduct('${product.valor}')">
                                <i class="fas fa-edit"></i>
                                Editar
                            </button>
                            <button class="btn-edit-image" onclick="editProductImage('${product.valor}')">
                                <i class="fas fa-image"></i>
                                Imagem
                            </button>
                            <button class="btn-remove" onclick="removeProduct('${product.valor}')">
                                <i class="fas fa-trash"></i>
                                Remover
                            </button>
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }

        // Funções do Modal
        function openAddProductModal() {
            if (!selectedPackageId) {
                alert('Selecione uma caixa primeiro');
                return;
            }
            
            document.getElementById('modal-title').innerHTML = '<i class="fas fa-plus"></i> Adicionar Produto';
            document.getElementById('product-package-id').value = selectedPackageId;
            document.getElementById('product-edit-value').value = '';
            document.getElementById('productForm').reset();
            document.getElementById('image-preview').style.display = 'none';
            document.getElementById('productModal').classList.add('show');
        }

        function editProduct(productValue) {
            // Implementar edição de produto
            console.log('Editando produto:', productValue);
        }

        function editProductImage(productValue) {
            // Implementar edição de imagem
            console.log('Editando imagem do produto:', productValue);
        }

        function removeProduct(productValue) {
            if (!confirm('Tem certeza que deseja remover este produto?')) {
                return;
            }
            
            // Implementar remoção
            console.log('Removendo produto:', productValue);
        }

        function closeProductModal() {
            document.getElementById('productModal').classList.remove('show');
        }

        function previewImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('image-preview');
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Fechar modal ao clicar fora
        document.getElementById('productModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeProductModal();
            }
        });

        // Função para alternar status do produto
        function toggleProduct(productId, isActive) {
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'toggle_produto');
            formData.append('produto_id', productId);
            formData.append('ativo', isActive ? 1 : 0);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage('Status do produto atualizado com sucesso!', 'success');
                } else {
                    showMessage('Erro ao atualizar status do produto', 'error');
                    // Reverter o toggle em caso de erro
                    event.target.checked = !isActive;
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                showMessage('Erro de conexão', 'error');
                // Reverter o toggle em caso de erro
                event.target.checked = !isActive;
            });
        }

        // Função para abrir modal de produto
        function openProductModal() {
            document.getElementById('productModal').classList.add('show');
            document.getElementById('modal-title').textContent = 'Adicionar Produto';
            document.getElementById('productForm').reset();
            document.getElementById('produto_id').value = '0';
        }

        // Função para fechar modal de produto
        function closeProductModal() {
            document.getElementById('productModal').classList.remove('show');
        }

        // Função para editar produto
        function editProduct(id, nome, valor, chanceGanho) {
            document.getElementById('productModal').classList.add('show');
            document.getElementById('modal-title').textContent = 'Editar Produto';
            document.getElementById('produto_id').value = id;
            document.getElementById('nome').value = nome;
            document.getElementById('valor').value = valor;
            document.getElementById('chance_ganho').value = chanceGanho;
        }

        // Função para salvar produto
        function saveProduct() {
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'salvar_produto');
            formData.append('produto_id', document.getElementById('produto_id').value);
            formData.append('nome', document.getElementById('nome').value);
            formData.append('valor', document.getElementById('valor').value);
            formData.append('chance_ganho', document.getElementById('chance_ganho').value);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage('Produto salvo com sucesso!', 'success');
                    closeProductModal();
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showMessage('Erro ao salvar produto', 'error');
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                showMessage('Erro de conexão', 'error');
            });
        }

        // Função para excluir produto
        function deleteProduct(id, nome) {
            if (!confirm(`Tem certeza que deseja excluir o produto "${nome}"?`)) {
                return;
            }

            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'excluir_produto');
            formData.append('produto_id', id);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage('Produto excluído com sucesso!', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showMessage('Erro ao excluir produto', 'error');
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                showMessage('Erro de conexão', 'error');
            });
        }

        // Função para mostrar mensagens
        function showMessage(message, type) {
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${type}`;
            messageDiv.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}"></i>
                <span>${message}</span>
            `;
            
            document.querySelector('.main-content').insertBefore(messageDiv, document.querySelector('.page-header').nextSibling);
            
            setTimeout(() => {
                messageDiv.remove();
            }, 5000);
        }

        // Função para alternar menu do usuário
        function toggleUserMenu() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('show');
        }

        // Fechar dropdown ao clicar fora
        document.addEventListener('click', function(event) {
            const userMenu = document.querySelector('.user-menu');
            const dropdown = document.getElementById('userDropdown');
            
            if (!userMenu.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });

        // Fechar modal ao clicar fora
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('productModal');
            if (event.target === modal) {
                closeProductModal();
            }
        });

        // Atualizar horário
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('pt-BR');
            const timeElement = document.getElementById('last-update');
            if (timeElement) {
                timeElement.textContent = timeString;
            }
        }

        // Atualizar a cada minuto
        setInterval(updateTime, 60000);

        // Inicialização
        document.addEventListener('DOMContentLoaded', function() {
            updateTime();
        });
    </script>
</body>
</html>