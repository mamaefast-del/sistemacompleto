<?php
session_start();
require 'db.php';

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
  header('Location: login.php');
  exit;
}

$mensagem = '';

// Processar ações de usuários
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $usuario_id = intval($_POST['usuario_id']);
        
        switch ($_POST['action']) {
            case 'update_user':
                $saldo = floatval($_POST['saldo']);
                $percentual_ganho = $_POST['percentual_ganho'] !== '' ? floatval($_POST['percentual_ganho']) : null;
                $usar_global = isset($_POST['usar_global']) ? 1 : 0;
                $conta_demo = isset($_POST['conta_demo']) ? 1 : 0;
                $comissao = floatval($_POST['comissao'] ?? 0);
                
                if ($usar_global) {
                    $percentual_ganho = null;
                }
                
                $stmt = $pdo->prepare("UPDATE usuarios SET saldo = ?, percentual_ganho = ?, conta_demo = ?, comissao = ? WHERE id = ?");
                if ($stmt->execute([$saldo, $percentual_ganho, $conta_demo, $comissao, $usuario_id])) {
                    $mensagem = "Usuário atualizado com sucesso!";
                } else {
                    $mensagem = "Erro ao atualizar usuário.";
                }
                break;
                
            case 'delete_user':
                // Excluir dados relacionados primeiro
                $tabelas = ['historico_jogos', 'transacoes_pix', 'saques', 'rollover'];
                foreach ($tabelas as $tabela) {
                    try {
                        // Verificar se a tabela existe antes de tentar deletar
                        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
                        $stmt->execute([$tabela]);
                        if ($stmt->fetch()) {
                            // Verificar quais colunas existem na tabela
                            $stmt = $pdo->prepare("DESCRIBE $tabela");
                            $stmt->execute();
                            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                            
                            $whereConditions = [];
                            $params = [];
                            
                            if (in_array('usuario_id', $columns)) {
                                $whereConditions[] = 'usuario_id = ?';
                                $params[] = $usuario_id;
                            }
                            if (in_array('afiliado_id', $columns)) {
                                $whereConditions[] = 'afiliado_id = ?';
                                $params[] = $usuario_id;
                            }
                            
                            if (!empty($whereConditions)) {
                                $whereClause = implode(' OR ', $whereConditions);
                                $stmt = $pdo->prepare("DELETE FROM $tabela WHERE $whereClause");
                                $stmt->execute($params);
                            }
                        }
                    } catch (PDOException $e) {
                        // Tabela pode não existir ou coluna pode não existir
                        error_log("Erro ao excluir de $tabela: " . $e->getMessage());
                    }
                }
                
                $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
                if ($stmt->execute([$usuario_id])) {
                    $mensagem = "Usuário excluído com sucesso!";
                } else {
                    $mensagem = "Erro ao excluir usuário.";
                }
                break;
                
            case 'add_saldo':
                $valor = floatval($_POST['valor']);
                if ($valor > 0) {
                    $stmt = $pdo->prepare("UPDATE usuarios SET saldo = saldo + ? WHERE id = ?");
                    if ($stmt->execute([$valor, $usuario_id])) {
                        $mensagem = "Saldo adicionado com sucesso!";
                    }
                }
                break;
                
            case 'remove_saldo':
                $valor = floatval($_POST['valor']);
                if ($valor > 0) {
                    $stmt = $pdo->prepare("UPDATE usuarios SET saldo = GREATEST(0, saldo - ?) WHERE id = ?");
                    if ($stmt->execute([$valor, $usuario_id])) {
                        $mensagem = "Saldo removido com sucesso!";
                    }
                }
                break;
        }
    }
}

$busca = trim($_GET['busca'] ?? '');
$filtro_status = $_GET['status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];

if ($busca !== '') {
    $where[] = "(email LIKE ? OR telefone LIKE ? OR nome LIKE ?)";
    $params = array_merge($params, ["%$busca%", "%$busca%", "%$busca%"]);
}

if ($filtro_status !== '') {
    if ($filtro_status === 'ativo') {
        $where[] = "conta_demo = 0";
    } elseif ($filtro_status === 'demo') {
        $where[] = "conta_demo = 1";
    }
}

$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Contar total de usuários
$stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios $where_clause");
$stmt->execute($params);
$total = $stmt->fetchColumn();

$total_pages = ceil($total / $limit);

// Buscar usuários com paginação
$stmt = $pdo->prepare("SELECT * FROM usuarios $where_clause ORDER BY id DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas gerais
$stmt = $pdo->query("
    SELECT 
        COUNT(*) as total_usuarios,
        COUNT(CASE WHEN conta_demo = 0 THEN 1 END) as usuarios_ativos,
        COUNT(CASE WHEN conta_demo = 1 THEN 1 END) as contas_demo,
        SUM(saldo) as saldo_total,
        AVG(saldo) as saldo_medio
    FROM usuarios
");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Usuários cadastrados hoje
$stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE DATE(data_cadastro) = CURDATE()");
$novos_hoje = $stmt->fetchColumn();
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Usuários - Admin</title>
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

        .stat-icon.users { background: linear-gradient(135deg, var(--primary-green), #00b894); }
        .stat-icon.active { background: linear-gradient(135deg, var(--success-color), #16a34a); }
        .stat-icon.demo { background: linear-gradient(135deg, var(--warning-color), #f59e0b); }
        .stat-icon.money { background: linear-gradient(135deg, var(--info-color), #2563eb); }
        .stat-icon.new { background: linear-gradient(135deg, var(--purple-color), #7c3aed); }

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

        /* Filters */
        .filters {
            display: flex;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .search-input {
            flex: 1;
            min-width: 300px;
            padding: 12px 16px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            color: var(--text-light);
            font-size: 14px;
            transition: var(--transition);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(0, 212, 170, 0.1);
        }

        .search-input::placeholder {
            color: var(--text-muted);
        }

        .select-input {
            padding: 12px 16px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            color: var(--text-light);
            font-size: 14px;
            transition: var(--transition);
        }

        .select-input:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(0, 212, 170, 0.1);
        }

        /* Buttons */
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
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-green), #00b894);
            color: #000;
            box-shadow: 0 4px 16px rgba(0, 212, 170, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 212, 170, 0.4);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #16a34a);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--error-color), #dc2626);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color), #d97706);
            color: white;
        }

        .btn-secondary {
            background: var(--bg-card);
            color: var(--text-light);
            border: 1px solid var(--border-color);
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        /* Tables */
        .table-container {
            overflow-x: auto;
            border-radius: var(--radius);
            background: var(--bg-panel);
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
            background: transparent;
        }

        thead tr th {
            padding: 14px;
            color: var(--primary-green);
            font-weight: 700;
            text-align: left;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            user-select: none;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        thead tr th:first-child {
            border-radius: var(--radius) 0 0 var(--radius);
        }

        thead tr th:last-child {
            border-radius: 0 var(--radius) var(--radius) 0;
        }

        tbody tr {
            background: var(--bg-panel);
            transition: var(--transition);
        }

        tbody tr:hover {
            background: rgba(0, 212, 170, 0.05);
            transform: translateY(-1px);
        }

        tbody tr td {
            padding: 14px;
            color: var(--text-light);
            vertical-align: middle;
            border: 1px solid var(--border-color);
            border-top: none;
            font-size: 13px;
        }

        tbody tr td:first-child {
            border-radius: var(--radius) 0 0 var(--radius);
            border-left: 1px solid var(--border-color);
        }

        tbody tr td:last-child {
            border-radius: 0 var(--radius) var(--radius) 0;
            border-right: 1px solid var(--border-color);
        }

        /* Form Elements */
        .form-input {
            padding: 8px 12px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-light);
            font-size: 12px;
            transition: var(--transition);
            width: 80px;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 2px rgba(0, 212, 170, 0.1);
        }

        .form-input.wide {
            width: 120px;
        }

        .checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
        }

        .checkbox-item input[type="checkbox"] {
            accent-color: var(--primary-green);
            cursor: pointer;
        }

        .checkbox-item label {
            color: var(--text-muted);
            cursor: pointer;
            user-select: none;
        }

        /* Status badges */
        .status-badge {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-transform: uppercase;
        }

        .status-ativo {
            background: rgba(34, 197, 94, 0.15);
            color: var(--success-color);
        }

        .status-demo {
            background: rgba(251, 206, 0, 0.15);
            color: var(--warning-color);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }

        .quick-action {
            padding: 4px 8px;
            font-size: 10px;
            border-radius: 4px;
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

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.3;
        }

        .empty-state h3 {
            font-size: 20px;
            margin-bottom: 8px;
            color: var(--text-light);
        }

        .empty-state p {
            font-size: 14px;
            line-height: 1.5;
        }

        /* Pagination */
        .pagination {
            margin-top: 25px;
            text-align: center;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
        }

        .pagination a {
            color: var(--primary-green);
            text-decoration: none;
            padding: 8px 16px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            background: var(--bg-panel);
            font-weight: 600;
            transition: var(--transition);
        }

        .pagination a:hover {
            background: var(--primary-green);
            color: #000;
            transform: translateY(-1px);
        }

        .pagination span {
            color: var(--text-muted);
            font-weight: 500;
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
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
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

        .form-control {
            width: 100%;
            padding: 12px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            color: var(--text-light);
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(0, 212, 170, 0.1);
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

            .filters {
                flex-direction: column;
                align-items: stretch;
            }

            .search-input {
                min-width: auto;
            }

            table {
                font-size: 11px;
            }

            .form-input {
                width: 60px;
                font-size: 11px;
            }

            .btn {
                padding: 8px 12px;
                font-size: 12px;
            }

            .checkbox-item {
                font-size: 10px;
            }

            .action-buttons {
                flex-direction: column;
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
                <i class="fas fa-users"></i>
                Gerenciar Usuários
            </h1>
            <div class="page-subtitle">
                <div class="live-indicator">
                    <div class="live-dot"></div>
                    <span>Dados atualizados em tempo real</span>
                </div>
                <span>•</span>
                <span>Última atualização: <span id="last-update"><?= date('H:i:s') ?></span></span>
            </div>
        </div>

        <?php if ($mensagem): ?>
            <div class="message success">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($mensagem) ?>
            </div>
        <?php endif; ?>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon users">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-trend">
                        <i class="fas fa-arrow-up"></i>
                        <span>Total</span>
                    </div>
                </div>
                <div class="stat-value"><?= number_format($stats['total_usuarios']) ?></div>
                <div class="stat-label">Total de Usuários</div>
                <div class="stat-detail">Todos os usuários cadastrados</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon active">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-trend">
                        <i class="fas fa-check"></i>
                        <span>Ativos</span>
                    </div>
                </div>
                <div class="stat-value"><?= number_format($stats['usuarios_ativos']) ?></div>
                <div class="stat-label">Contas Normais</div>
                <div class="stat-detail">Usuários com conta ativa</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon demo">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="stat-trend" style="color: var(--warning-color);">
                        <i class="fas fa-vial"></i>
                        <span>Demo</span>
                    </div>
                </div>
                <div class="stat-value"><?= number_format($stats['contas_demo']) ?></div>
                <div class="stat-label">Contas Demo</div>
                <div class="stat-detail">Usuários em modo demonstração</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon money">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                    <div class="stat-trend">
                        <i class="fas fa-wallet"></i>
                        <span>Saldo</span>
                    </div>
                </div>
                <div class="stat-value">R$ <?= number_format($stats['saldo_total'] ?? 0, 0, ',', '.') ?></div>
                <div class="stat-label">Saldo Total</div>
                <div class="stat-detail">Soma de todos os saldos</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon new">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div class="stat-trend" style="color: var(--purple-color);">
                        <i class="fas fa-calendar-day"></i>
                        <span>Hoje</span>
                    </div>
                </div>
                <div class="stat-value"><?= number_format($novos_hoje) ?></div>
                <div class="stat-label">Novos Hoje</div>
                <div class="stat-detail">Cadastros de hoje</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card">
            <h3>
                <i class="fas fa-filter"></i> Filtros e Busca
            </h3>
            
            <form method="GET" class="filters">
                <input
                    type="text"
                    name="busca"
                    class="search-input"
                    placeholder="🔍 Buscar por email, telefone ou nome..."
                    value="<?= htmlspecialchars($busca) ?>"
                    autocomplete="off"
                />
                
                <select name="status" class="select-input">
                    <option value="">Todos os Status</option>
                    <option value="ativo" <?= $filtro_status === 'ativo' ? 'selected' : '' ?>>Contas Normais</option>
                    <option value="demo" <?= $filtro_status === 'demo' ? 'selected' : '' ?>>Contas Demo</option>
                </select>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i>
                    Buscar
                </button>
                
                <?php if ($busca || $filtro_status): ?>
                    <a href="usuarios_admin.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i>
                        Limpar
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Users Table -->
        <div class="card">
            <h3>
                <i class="fas fa-table"></i> Lista de Usuários
                <?php if ($busca || $filtro_status): ?>
                    <span style="font-size: 14px; color: var(--text-muted); font-weight: 400;">(<?= $total ?> resultados)</span>
                <?php endif; ?>
            </h3>
            
            <?php if (empty($usuarios)): ?>
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <h3>Nenhum usuário encontrado</h3>
                    <p><?= $busca ? 'Nenhum usuário encontrado para esta busca.' : 'Ainda não há usuários cadastrados na plataforma.' ?></p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Usuário</th>
                                <th>Contato</th>
                                <th>Saldo</th>
                                <th>Estatísticas</th>
                                <th>% Ganho</th>
                                <th>Comissão</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usuarios as $u): ?>
                                <?php
                                  // Buscar estatísticas do usuário
                                  $indicacoes = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE indicado_por = ?");
                                  $indicacoes->execute([$u['id']]);
                                  $indicados = $indicacoes->fetchColumn();

                                  $depositos = $pdo->prepare("SELECT SUM(valor) FROM transacoes_pix WHERE usuario_id = ? AND status = 'aprovado'");
                                  $depositos->execute([$u['id']]);
                                  $total_depositos = $depositos->fetchColumn() ?? 0;

                                  $saques = $pdo->prepare("SELECT SUM(valor) FROM saques WHERE usuario_id = ? AND status = 'aprovado'");
                                  $saques->execute([$u['id']]);
                                  $total_saques = $saques->fetchColumn() ?? 0;

                                  $jogadas = $pdo->prepare("SELECT COUNT(*) FROM historico_jogos WHERE usuario_id = ?");
                                  $jogadas->execute([$u['id']]);
                                  $total_jogadas = $jogadas->fetchColumn() ?? 0;
                                ?>
                                <tr>
                                    <td>
                                        <strong style="color: var(--primary-green);">#<?= $u['id'] ?></strong>
                                    </td>
                                    <td>
                                        <div>
                                            <strong style="color: var(--text-light);"><?= htmlspecialchars($u['nome'] ?: 'Sem nome') ?></strong>
                                            <br>
                                            <small style="color: var(--text-muted);"><?= htmlspecialchars($u['email']) ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-size: 12px;">
                                            <div style="color: var(--text-light);">
                                                <i class="fas fa-phone"></i> <?= htmlspecialchars($u['telefone'] ?: 'N/A') ?>
                                            </div>
                                            <div style="color: var(--text-muted); margin-top: 4px;">
                                                <i class="fas fa-calendar"></i> <?= date('d/m/Y', strtotime($u['data_cadastro'])) ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 700; color: var(--primary-green); font-size: 14px;">
                                            R$ <?= number_format($u['saldo'], 2, ',', '.') ?>
                                        </div>
                                        <div style="display: flex; gap: 4px; margin-top: 4px;">
                                            <button type="button" class="btn quick-action btn-success" onclick="showSaldoModal(<?= $u['id'] ?>, 'add', <?= $u['saldo'] ?>)">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                            <button type="button" class="btn quick-action btn-warning" onclick="showSaldoModal(<?= $u['id'] ?>, 'remove', <?= $u['saldo'] ?>)">
                                                <i class="fas fa-minus"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-size: 11px; line-height: 1.4;">
                                            <div><i class="fas fa-arrow-down" style="color: #22c55e;"></i> R$ <?= number_format($total_depositos, 2, ',', '.') ?></div>
                                            <div><i class="fas fa-arrow-up" style="color: #ef4444;"></i> R$ <?= number_format($total_saques, 2, ',', '.') ?></div>
                                            <div><i class="fas fa-gamepad" style="color: var(--primary-green);"></i> <?= $total_jogadas ?> jogadas</div>
                                        </div>
                                    </td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="update_user">
                                            <input type="hidden" name="usuario_id" value="<?= $u['id'] ?>">
                                            <input type="hidden" name="saldo" value="<?= $u['saldo'] ?>">
                                            <input type="hidden" name="comissao" value="<?= $u['comissao'] ?? 0 ?>">
                                            
                                            <input
                                                type="number"
                                                step="0.1"
                                                name="percentual_ganho"
                                                value="<?= is_null($u['percentual_ganho']) ? '' : $u['percentual_ganho'] ?>"
                                                class="form-input"
                                                placeholder="Global"
                                                style="margin-bottom: 4px;"
                                            />
                                            <div class="checkbox-group">
                                                <div class="checkbox-item">
                                                    <input type="checkbox" name="usar_global" value="1" <?= is_null($u['percentual_ganho']) ? 'checked' : '' ?> id="global_<?= $u['id'] ?>">
                                                    <label for="global_<?= $u['id'] ?>">Padrão</label>
                                                </div>
                                            </div>
                                    </td>
                                    <td>
                                        <div style="color: var(--primary-green); font-weight: 600; font-size: 12px;">
                                            R$ <?= number_format($u['comissao'] ?? 0, 2, ',', '.') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="margin-bottom: 8px;">
                                            <?php if (!($u['conta_demo'] ?? 0)): ?>
                                                <span class="status-badge status-ativo">
                                                    <i class="fas fa-check"></i> Ativo
                                                </span>
                                            <?php else: ?>
                                                <span class="status-badge status-demo">
                                                    <i class="fas fa-star"></i> Demo
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="checkbox-group">
                                            <div class="checkbox-item">
                                                <input type="checkbox" name="conta_demo" value="1" <?= ($u['conta_demo'] ?? 0) ? 'checked' : '' ?> id="demo_<?= $u['id'] ?>">
                                                <label for="demo_<?= $u['id'] ?>">Demo</label>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button type="submit" class="btn btn-success btn-sm">
                                                <i class="fas fa-save"></i>
                                                Salvar
                                            </button>
                                        </form>
                                            
                                            <button type="button" class="btn btn-danger btn-sm" onclick="confirmDelete(<?= $u['id'] ?>, '<?= htmlspecialchars($u['email']) ?>')">
                                                <i class="fas fa-trash"></i>
                                                Excluir
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php
                        $query_params = ['busca' => $busca, 'status' => $filtro_status];
                        $query_string = http_build_query(array_filter($query_params));
                        ?>

                        <?php if ($page > 1): ?>
                            <a href="?<?= $query_string ?>&page=<?= $page - 1 ?>">
                                <i class="fas fa-chevron-left"></i> Anterior
                            </a>
                        <?php endif; ?>

                        <span>Página <?= $page ?> de <?= $total_pages ?></span>

                        <?php if ($page < $total_pages): ?>
                            <a href="?<?= $query_string ?>&page=<?= $page + 1 ?>">
                                Próximo <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <!-- Modal de Confirmação de Exclusão -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-exclamation-triangle"></i>
                    Confirmar Exclusão
                </h3>
                <button class="modal-close" onclick="closeDeleteModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <p style="margin-bottom: 20px; color: var(--text-light);">
                    Tem certeza que deseja excluir o usuário <strong id="userEmail"></strong>?
                </p>
                <p style="margin-bottom: 20px; color: var(--error-color); font-size: 14px;">
                    <i class="fas fa-warning"></i>
                    Esta ação não pode ser desfeita e excluirá todos os dados relacionados!
                </p>
                
                <form method="POST" id="deleteForm">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="usuario_id" id="deleteUserId">
                    
                    <div style="display: flex; gap: 12px; justify-content: flex-end;">
                        <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">
                            <i class="fas fa-times"></i>
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash"></i>
                            Excluir Usuário
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de Saldo -->
    <div id="saldoModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-dollar-sign"></i>
                    <span id="saldoModalTitle">Gerenciar Saldo</span>
                </h3>
                <button class="modal-close" onclick="closeSaldoModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST" id="saldoForm">
                    <input type="hidden" name="action" id="saldoAction">
                    <input type="hidden" name="usuario_id" id="saldoUserId">
                    
                    <div class="form-group">
                        <label class="form-label">Saldo Atual</label>
                        <div style="font-size: 18px; font-weight: 700; color: var(--primary-green); margin-bottom: 16px;" id="currentSaldo">
                            R$ 0,00
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" id="valorLabel">Valor</label>
                        <input type="number" step="0.01" name="valor" class="form-control" required min="0.01" placeholder="0,00">
                    </div>
                    
                    <div style="display: flex; gap: 12px; justify-content: flex-end;">
                        <button type="button" class="btn btn-secondary" onclick="closeSaldoModal()">
                            <i class="fas fa-times"></i>
                            Cancelar
                        </button>
                        <button type="submit" class="btn" id="saldoSubmitBtn">
                            <i class="fas fa-save"></i>
                            Confirmar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Atualizar estatísticas do header
        async function updateHeaderStats() {
            try {
                const response = await fetch('get_header_stats.php');
                const data = await response.json();
                
                document.getElementById('online-count').textContent = data.online || 0;
                document.getElementById('deposito-count').textContent = data.depositos_pendentes || 0;
                document.getElementById('saque-count').textContent = data.saques_pendentes || 0;
                document.getElementById('last-update').textContent = new Date().toLocaleTimeString('pt-BR');
            } catch (error) {
                console.error('Erro ao atualizar estatísticas:', error);
            }
        }

        // Menu do usuário
        function toggleUserMenu() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('show');
        }

        function confirmDelete(userId, userEmail) {
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('userEmail').textContent = userEmail;
            document.getElementById('deleteModal').classList.add('show');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('show');
        }

        function showSaldoModal(userId, action, currentSaldo) {
            document.getElementById('saldoUserId').value = userId;
            document.getElementById('saldoAction').value = action === 'add' ? 'add_saldo' : 'remove_saldo';
            document.getElementById('currentSaldo').textContent = 'R$ ' + parseFloat(currentSaldo).toLocaleString('pt-BR', {minimumFractionDigits: 2});
            
            const title = document.getElementById('saldoModalTitle');
            const label = document.getElementById('valorLabel');
            const btn = document.getElementById('saldoSubmitBtn');
            
            if (action === 'add') {
                title.innerHTML = '<i class="fas fa-plus"></i> Adicionar Saldo';
                label.textContent = 'Valor a Adicionar';
                btn.className = 'btn btn-success';
                btn.innerHTML = '<i class="fas fa-plus"></i> Adicionar';
            } else {
                title.innerHTML = '<i class="fas fa-minus"></i> Remover Saldo';
                label.textContent = 'Valor a Remover';
                btn.className = 'btn btn-warning';
                btn.innerHTML = '<i class="fas fa-minus"></i> Remover';
            }
            
            document.getElementById('saldoModal').classList.add('show');
        }

        function closeSaldoModal() {
            document.getElementById('saldoModal').classList.remove('show');
            document.getElementById('saldoForm').reset();
        }

        // Fechar modais ao clicar fora
        document.addEventListener('click', function(event) {
            const userMenu = document.querySelector('.user-menu');
            const deleteModal = document.getElementById('deleteModal');
            const saldoModal = document.getElementById('saldoModal');
            
            if (!userMenu.contains(event.target)) {
                document.getElementById('userDropdown').classList.remove('show');
            }
            
            if (event.target === deleteModal) {
                closeDeleteModal();
            }
            if (event.target === saldoModal) {
                closeSaldoModal();
            }
        });

        // Tecla ESC para fechar modais
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeDeleteModal();
                closeSaldoModal();
                document.getElementById('userDropdown').classList.remove('show');
            }
        });

        // Gerenciar checkbox "usar padrão"
        document.addEventListener('DOMContentLoaded', function() {
            updateHeaderStats();
            setInterval(updateHeaderStats, 30000); // Atualizar a cada 30 segundos
            
            const globalCheckboxes = document.querySelectorAll('input[name="usar_global"]');
            
            globalCheckboxes.forEach(checkbox => {
                const row = checkbox.closest('tr');
                const percentualInput = row.querySelector('input[name="percentual_ganho"]');
                
                checkbox.addEventListener('change', function() {
                    if (this.checked) {
                        percentualInput.value = '';
                        percentualInput.disabled = true;
                        percentualInput.style.opacity = '0.5';
                    } else {
                        percentualInput.disabled = false;
                        percentualInput.style.opacity = '1';
                        percentualInput.focus();
                    }
                });
                
                // Aplicar estado inicial
                if (checkbox.checked) {
                    percentualInput.disabled = true;
                    percentualInput.style.opacity = '0.5';
                }
            });
        });
    </script>
</body>
</html>