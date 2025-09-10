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

// Verificar se as colunas necessárias existem
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'ativo'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN ativo TINYINT(1) DEFAULT 1");
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN ultimo_login TIMESTAMP NULL");
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN total_indicados INT DEFAULT 0");
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN total_comissao_gerada DECIMAL(10,2) DEFAULT 0.00");
    }
} catch (PDOException $e) {
    error_log("Erro ao verificar/criar colunas: " . $e->getMessage());
}

// Buscar usuários
$search = $_GET['search'] ?? '';
$filter = $_GET['filter'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(nome LIKE ? OR email LIKE ? OR telefone LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}

if ($filter) {
    switch ($filter) {
        case 'ativos':
            $where_conditions[] = "ativo = 1";
            break;
        case 'inativos':
            $where_conditions[] = "ativo = 0";
            break;
        case 'afiliados':
            $where_conditions[] = "codigo_afiliado IS NOT NULL AND codigo_afiliado != ''";
            break;
        case 'com_saldo':
            $where_conditions[] = "saldo > 0";
            break;
        case 'demo':
            $where_conditions[] = "conta_demo = 1";
            break;
    }
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios $where_clause");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("
        SELECT id, nome, email, telefone, saldo, ativo, data_cadastro, ultimo_login,
               codigo_afiliado, codigo_afiliado_usado, porcentagem_afiliado, afiliado_ativo, 
               saldo_comissao, conta_demo, comissao
        FROM usuarios 
        $where_clause 
        ORDER BY data_cadastro DESC 
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $usuarios = $stmt->fetchAll();
} catch (PDOException $e) {
    $usuarios = [];
    $total = 0;
    error_log("Erro ao buscar usuários: " . $e->getMessage());
}

$total_pages = ceil($total / $limit);

// Estatísticas gerais
try {
    $total_usuarios = $pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
    $usuarios_ativos = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE ativo = 1")->fetchColumn();
    $usuarios_com_saldo = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE saldo > 0")->fetchColumn();
    $total_saldo = $pdo->query("SELECT COALESCE(SUM(saldo), 0) FROM usuarios")->fetchColumn();
    $total_afiliados = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE codigo_afiliado IS NOT NULL AND codigo_afiliado != ''")->fetchColumn();
    $afiliados_ativos = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE afiliado_ativo = 1")->fetchColumn();
    $contas_demo = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE conta_demo = 1")->fetchColumn();
} catch (PDOException $e) {
    $total_usuarios = $usuarios_ativos = $usuarios_com_saldo = $total_saldo = $total_afiliados = $afiliados_ativos = $contas_demo = 0;
}

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user_id = intval($_POST['user_id'] ?? 0);
    
    try {
        switch ($action) {
            case 'block':
                $stmt = $pdo->prepare("UPDATE usuarios SET ativo = 0 WHERE id = ?");
                $stmt->execute([$user_id]);
                $_SESSION['success'] = 'Usuário bloqueado com sucesso!';
                break;
                
            case 'unblock':
                $stmt = $pdo->prepare("UPDATE usuarios SET ativo = 1 WHERE id = ?");
                $stmt->execute([$user_id]);
                $_SESSION['success'] = 'Usuário desbloqueado com sucesso!';
                break;
                
            case 'update_saldo':
                $novo_saldo = floatval($_POST['novo_saldo'] ?? 0);
                $stmt = $pdo->prepare("UPDATE usuarios SET saldo = ? WHERE id = ?");
                $stmt->execute([$novo_saldo, $user_id]);
                $_SESSION['success'] = 'Saldo atualizado com sucesso!';
                break;
                
            case 'toggle_demo':
                $stmt = $pdo->prepare("UPDATE usuarios SET conta_demo = NOT conta_demo WHERE id = ?");
                $stmt->execute([$user_id]);
                $_SESSION['success'] = 'Status de conta demo alterado!';
                break;
                
            case 'make_affiliate':
                // Verificar se já é afiliado
                $stmt = $pdo->prepare("SELECT codigo_afiliado FROM usuarios WHERE id = ?");
                $stmt->execute([$user_id]);
                $current_code = $stmt->fetchColumn();
                
                if (empty($current_code)) {
                    // Gerar código único
                    do {
                        $codigo = strtoupper(substr(md5(uniqid() . $user_id . time()), 0, 8));
                        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE codigo_afiliado = ?");
                        $stmt->execute([$codigo]);
                    } while ($stmt->fetch());
                    
                    $stmt = $pdo->prepare("UPDATE usuarios SET codigo_afiliado = ?, afiliado_ativo = 1, porcentagem_afiliado = 10.00 WHERE id = ?");
                    $stmt->execute([$codigo, $user_id]);
                    
                    // Registrar no histórico
                    $stmt = $pdo->prepare("INSERT INTO historico_afiliados (afiliado_id, acao, detalhes) VALUES (?, 'ativacao', ?)");
                    $stmt->execute([$user_id, "Transformado em afiliado pelo admin - Código: $codigo"]);
                    
                    $_SESSION['success'] = 'Usuário transformado em afiliado! Código: ' . $codigo;
                } else {
                    $_SESSION['error'] = 'Usuário já é afiliado!';
                }
                break;
                
            case 'remove_affiliate':
                $stmt = $pdo->prepare("UPDATE usuarios SET afiliado_ativo = 0 WHERE id = ?");
                $stmt->execute([$user_id]);
                
                // Registrar no histórico
                $stmt = $pdo->prepare("INSERT INTO historico_afiliados (afiliado_id, acao, detalhes) VALUES (?, 'desativacao', 'Status de afiliado removido pelo admin')");
                $stmt->execute([$user_id]);
                
                $_SESSION['success'] = 'Status de afiliado removido!';
                break;
                
            case 'delete_user':
                // Verificar se o usuário pode ser excluído
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM transacoes_pix WHERE usuario_id = ? AND status = 'aprovado'");
                $stmt->execute([$user_id]);
                $has_transactions = $stmt->fetchColumn() > 0;
                
                if ($has_transactions) {
                    $_SESSION['error'] = 'Não é possível excluir usuário com transações aprovadas!';
                } else {
                    // Excluir dados relacionados primeiro
                    $tables = ['rollover', 'historico_jogos', 'saques', 'transacoes_pix', 'comissoes', 'historico_afiliados'];
                    foreach ($tables as $table) {
                        try {
                            $stmt = $pdo->prepare("DELETE FROM $table WHERE usuario_id = ? OR afiliado_id = ?");
                            $stmt->execute([$user_id, $user_id]);
                        } catch (PDOException $e) {
                            // Tabela pode não existir ou coluna pode não existir
                        }
                    }
                    
                    // Excluir usuário
                    $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $_SESSION['success'] = 'Usuário excluído com sucesso!';
                }
                break;
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Erro ao processar ação: ' . $e->getMessage();
    }
    
    header('Location: admin_usuarios.php?' . http_build_query($_GET));
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Usuários - Admin</title>
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

        /* Filters */
        .filters {
            display: flex;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .search-input, .filter-select {
            padding: 12px 16px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            color: var(--text-light);
            transition: var(--transition);
        }

        .search-input {
            flex: 1;
            min-width: 300px;
        }

        .search-input:focus, .filter-select:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(0, 212, 170, 0.1);
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

        .status-badge {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .status-ativo { 
            background: rgba(34, 197, 94, 0.15); 
            color: var(--success-color); 
        }
        .status-inativo { 
            background: rgba(239, 68, 68, 0.15); 
            color: var(--error-color); 
        }
        .status-afiliado { 
            background: rgba(0, 212, 170, 0.15); 
            color: var(--primary-gold); 
        }
        .status-demo { 
            background: rgba(139, 92, 246, 0.15); 
            color: #8b5cf6; 
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
            background: var(--bg-card); 
            color: var(--text-light); 
            border: 1px solid var(--border-color); 
        }
        .btn-success { background: var(--success-color); color: white; }
        .btn-danger { background: var(--error-color); color: white; }
        .btn-warning { background: var(--warning-color); color: white; }
        .btn-info { background: #3b82f6; color: white; }
        .btn-sm { padding: 4px 8px; font-size: 11px; }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 20px;
        }

        .pagination a {
            padding: 8px 12px;
            background: var(--bg-panel);
            color: var(--text-light);
            text-decoration: none;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            transition: var(--transition);
        }

        .pagination a:hover {
            border-color: var(--primary-green);
            background: rgba(0, 212, 170, 0.1);
        }

        .pagination a.active {
            background: var(--primary-green);
            color: #000;
            border-color: var(--primary-green);
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

        .modal.show { display: flex; }

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

        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-light);
        }

        .form-input {
            width: 100%;
            padding: 12px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            color: var(--text-light);
            transition: var(--transition);
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(0, 212, 170, 0.1);
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

            .filters {
                flex-direction: column;
                align-items: stretch;
            }

            .search-input {
                min-width: auto;
            }

            .table {
                font-size: 11px;
            }

            .btn {
                padding: 6px 10px;
                font-size: 11px;
            }

            .modal-content {
                width: 95%;
                margin: 10% auto;
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
                    <a href="painel_admin.php" class="dropdown-item">
                        <i class="fas fa-chart-line"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="configuracoes_admin.php" class="dropdown-item">
                        <i class="fas fa-cog"></i>
                        <span>Configurações</span>
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

                <a href="admin_usuarios.php" class="nav-item active">
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

                <a href="gateways_admin.php" class="nav-item">
                    <i class="fas fa-credit-card nav-icon"></i>
                    <div class="nav-title">Gateways</div>
                    <div class="nav-desc">Configuração de pagamentos</div>
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
                <i class="fas fa-users"></i>
                Gerenciar Usuários
            </h1>
            <div class="page-subtitle">
                <div class="live-indicator">
                    <div class="live-dot"></div>
                    <span>Sistema ativo</span>
                </div>
                <span>•</span>
                <span>Gerencie contas de usuários e afiliados</span>
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

        <!-- Estatísticas -->
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
                    <div class="stat-title">Usuários com Saldo</div>
                    <div class="stat-icon" style="background: rgba(0, 212, 170, 0.1); color: var(--primary-green);">
                        <i class="fas fa-wallet"></i>
                    </div>
                </div>
                <div class="stat-value"><?= number_format($usuarios_com_saldo) ?></div>
                <div class="stat-change positive">
                    <i class="fas fa-arrow-up"></i>
                    <span>Com fundos</span>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Saldo Total</div>
                    <div class="stat-icon" style="background: rgba(251, 206, 0, 0.1); color: var(--primary-gold);">
                        <i class="fas fa-coins"></i>
                    </div>
                </div>
                <div class="stat-value">R$ <?= number_format($total_saldo, 0, ',', '.') ?></div>
                <div class="stat-change positive">
                    <i class="fas fa-arrow-up"></i>
                    <span>Em contas</span>
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
                    <div class="stat-title">Contas Demo</div>
                    <div class="stat-icon" style="background: rgba(139, 92, 246, 0.1); color: var(--purple-color);">
                        <i class="fas fa-flask"></i>
                    </div>
                </div>
                <div class="stat-value"><?= number_format($contas_demo) ?></div>
                <div class="stat-change">
                    <i class="fas fa-vial"></i>
                    <span>Demonstração</span>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="card">
            <h3>
                <i class="fas fa-filter"></i>
                Filtros e Busca
            </h3>
            
            <form method="GET" class="filters">
                <input type="text" 
                       name="search" 
                       class="search-input" 
                       placeholder="🔍 Buscar por nome, email ou telefone..." 
                       value="<?= htmlspecialchars($search) ?>">
                
                <select name="filter" class="filter-select">
                    <option value="">Todos os usuários</option>
                    <option value="ativos" <?= $filter === 'ativos' ? 'selected' : '' ?>>Usuários Ativos</option>
                    <option value="inativos" <?= $filter === 'inativos' ? 'selected' : '' ?>>Usuários Inativos</option>
                    <option value="com_saldo" <?= $filter === 'com_saldo' ? 'selected' : '' ?>>Com Saldo</option>
                    <option value="afiliados" <?= $filter === 'afiliados' ? 'selected' : '' ?>>Afiliados</option>
                    <option value="demo" <?= $filter === 'demo' ? 'selected' : '' ?>>Contas Demo</option>
                </select>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i>
                    Buscar
                </button>
                
                <a href="admin_usuarios.php" class="btn btn-secondary">
                    <i class="fas fa-times"></i>
                    Limpar
                </a>
            </form>
            
            <div style="margin-top: 16px; color: var(--text-muted); font-size: 14px;">
                Mostrando <?= count($usuarios) ?> de <?= number_format($total) ?> usuários
            </div>
        </div>

        <!-- Lista de Usuários -->
        <div class="card">
            <h3>
                <i class="fas fa-table"></i>
                Lista de Usuários
            </h3>
            
            <?php if (empty($usuarios)): ?>
                <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                    <i class="fas fa-users-slash" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
                    <p>Nenhum usuário encontrado com os filtros aplicados.</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Usuário</th>
                                <th>Contato</th>
                                <th>Saldo</th>
                                <th>Status</th>
                                <th>Afiliado</th>
                                <th>Cadastro</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usuarios as $user): ?>
                                <tr>
                                    <td><?= $user['id'] ?></td>
                                    <td style="color: var(--text-light); font-weight: 600;">
                                        <div>
                                            <?= htmlspecialchars($user['nome'] ?: 'Usuário #' . $user['id']) ?>
                                        </div>
                                        <div style="font-size: 11px; color: var(--text-muted);">
                                            ID: <?= $user['id'] ?>
                                            <?php if (isset($user['conta_demo']) && $user['conta_demo']): ?>
                                                <span class="status-badge status-demo" style="margin-left: 4px;">
                                                    <i class="fas fa-flask"></i>
                                                    DEMO
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="color: var(--text-light);">
                                            <?= htmlspecialchars($user['email']) ?>
                                        </div>
                                        <?php if ($user['telefone']): ?>
                                            <div style="font-size: 11px; color: var(--text-muted);">
                                                <?= htmlspecialchars($user['telefone']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="color: var(--primary-gold); font-weight: 700;">
                                        <div>R$ <?= number_format($user['saldo'], 2, ',', '.') ?></div>
                                        <?php if (isset($user['saldo_comissao']) && $user['saldo_comissao'] > 0): ?>
                                            <div style="font-size: 11px; color: var(--success-color);">
                                                Comissão: R$ <?= number_format($user['saldo_comissao'], 2, ',', '.') ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (isset($user['comissao']) && $user['comissao'] > 0): ?>
                                            <div style="font-size: 11px; color: var(--warning-color);">
                                                Pendente: R$ <?= number_format($user['comissao'], 2, ',', '.') ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?= $user['ativo'] ? 'ativo' : 'inativo' ?>">
                                            <i class="fas fa-<?= $user['ativo'] ? 'check-circle' : 'times-circle' ?>"></i>
                                            <?= $user['ativo'] ? 'Ativo' : 'Inativo' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($user['codigo_afiliado'])): ?>
                                            <span class="status-badge status-afiliado">
                                                <i class="fas fa-handshake"></i>
                                                <?= $user['codigo_afiliado'] ?>
                                            </span>
                                            <?php if (isset($user['afiliado_ativo']) && $user['afiliado_ativo']): ?>
                                                <div style="font-size: 10px; color: var(--success-color); margin-top: 2px;">
                                                    <?= number_format($user['porcentagem_afiliado'] ?? 10, 1) ?>% comissão
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted); font-size: 11px;">
                                                Não afiliado
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if (isset($user['codigo_afiliado_usado']) && !empty($user['codigo_afiliado_usado'])): ?>
                                            <div style="font-size: 10px; color: var(--text-muted); margin-top: 2px;">
                                                Indicado por: <?= $user['codigo_afiliado_usado'] ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?= date('d/m/Y', strtotime($user['data_cadastro'])) ?></div>
                                        <?php if ($user['ultimo_login']): ?>
                                            <div style="font-size: 11px; color: var(--text-muted);">
                                                Último: <?= date('d/m/Y H:i', strtotime($user['ultimo_login'])) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                            <button type="button" 
                                                    class="btn btn-primary btn-sm" 
                                                    onclick="editSaldo(<?= $user['id'] ?>, <?= $user['saldo'] ?>)"
                                                    title="Editar Saldo">
                                                <i class="fas fa-dollar-sign"></i>
                                            </button>
                                            
                                            <?php if ($user['ativo']): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="block">
                                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                    <button type="submit" 
                                                            class="btn btn-warning btn-sm" 
                                                            onclick="return confirm('Bloquear usuário?')"
                                                            title="Bloquear Usuário">
                                                        <i class="fas fa-ban"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="unblock">
                                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                    <button type="submit" 
                                                            class="btn btn-success btn-sm" 
                                                            onclick="return confirm('Desbloquear usuário?')"
                                                            title="Desbloquear Usuário">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="toggle_demo">
                                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                <button type="submit" 
                                                        class="btn btn-info btn-sm" 
                                                        onclick="return confirm('Alternar status de conta demo?')"
                                                        title="<?= (isset($user['conta_demo']) && $user['conta_demo']) ? 'Remover Demo' : 'Tornar Demo' ?>">
                                                    <i class="fas fa-flask"></i>
                                                </button>
                                            </form>
                                            
                                            <?php if (empty($user['codigo_afiliado'])): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="make_affiliate">
                                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                    <button type="submit" 
                                                            class="btn btn-secondary btn-sm" 
                                                            onclick="return confirm('Transformar em afiliado?')"
                                                            title="Tornar Afiliado">
                                                        <i class="fas fa-handshake"></i>
                                                    </button>
                                                </form>
                                            <?php elseif (isset($user['afiliado_ativo']) && $user['afiliado_ativo']): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="remove_affiliate">
                                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                    <button type="submit" 
                                                            class="btn btn-warning btn-sm" 
                                                            onclick="return confirm('Desativar afiliado?')"
                                                            title="Desativar Afiliado">
                                                        <i class="fas fa-user-times"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                <button type="submit" 
                                                        class="btn btn-danger btn-sm" 
                                                        onclick="return confirm('ATENÇÃO: Esta ação é irreversível! Excluir usuário?')"
                                                        title="Excluir Usuário">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Paginação -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php
                $query_params = $_GET;
                for ($i = 1; $i <= $total_pages; $i++):
                    $query_params['page'] = $i;
                    $url = '?' . http_build_query($query_params);
                ?>
                    <a href="<?= $url ?>" class="<?= $i === $page ? 'active' : '' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </main>

    <!-- Modal Editar Saldo -->
    <div id="editSaldoModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-dollar-sign"></i>
                    Editar Saldo
                </h3>
                <button class="modal-close" onclick="closeEditSaldoModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_saldo">
                    <input type="hidden" name="user_id" id="editUserId">
                    
                    <div class="form-group">
                        <label class="form-label">Novo Saldo (R$)</label>
                        <input type="number" 
                               name="novo_saldo" 
                               id="editSaldoInput" 
                               class="form-input" 
                               step="0.01" 
                               min="0"
                               required>
                        <small style="color: var(--text-muted); font-size: 11px;">
                            Digite o novo saldo do usuário
                        </small>
                    </div>
                    
                    <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">
                        <button type="button" class="btn btn-secondary" onclick="closeEditSaldoModal()">
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

        // Modal usuários online
        function showOnlineUsers() {
            // Implementar modal de usuários online se necessário
        }

        // Funções do Modal Editar Saldo
        function editSaldo(userId, currentSaldo) {
            document.getElementById('editUserId').value = userId;
            document.getElementById('editSaldoInput').value = currentSaldo;
            document.getElementById('editSaldoModal').classList.add('show');
        }

        function closeEditSaldoModal() {
            document.getElementById('editSaldoModal').classList.remove('show');
        }

        // Fechar modal ao clicar fora
        document.addEventListener('click', function(event) {
            const userMenu = document.querySelector('.user-menu');
            
            if (!userMenu.contains(event.target)) {
                document.getElementById('userDropdown').classList.remove('show');
            }

            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('show');
            }
        });

        // Tecla ESC para fechar modais
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                document.getElementById('userDropdown').classList.remove('show');
                document.querySelectorAll('.modal').forEach(modal => {
                    modal.classList.remove('show');
                });
            }
        });

        // Auto-submit do formulário de filtros quando mudar o select
        document.querySelector('select[name="filter"]').addEventListener('change', function() {
            this.form.submit();
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