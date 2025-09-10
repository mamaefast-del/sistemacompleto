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

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user_id = intval($_POST['user_id'] ?? 0);
    
    try {
        switch ($action) {
            case 'make_affiliate':
                // Verificar se já é afiliado
                $stmt = $pdo->prepare("SELECT codigo_afiliado, afiliado_ativo FROM usuarios WHERE id = ?");
                $stmt->execute([$user_id]);
                $current = $stmt->fetch();
                
                if (!empty($current['codigo_afiliado'])) {
                    if ($current['afiliado_ativo']) {
                        $_SESSION['error'] = 'Usuário já é afiliado ativo!';
                    } else {
                        // Reativar afiliado
                        $stmt = $pdo->prepare("UPDATE usuarios SET afiliado_ativo = 1 WHERE id = ?");
                        $stmt->execute([$user_id]);
                        $_SESSION['success'] = 'Afiliado reativado com sucesso!';
                    }
                } else {
                    // Gerar código único
                    do {
                        $codigo = strtoupper(substr(md5(uniqid() . $user_id . time()), 0, 8));
                        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE codigo_afiliado = ?");
                        $stmt->execute([$codigo]);
                    } while ($stmt->fetch());
                    
                    $stmt = $pdo->prepare("UPDATE usuarios SET codigo_afiliado = ?, afiliado_ativo = 1, porcentagem_afiliado = 10.00, data_aprovacao_afiliado = NOW() WHERE id = ?");
                    $stmt->execute([$codigo, $user_id]);
                    
                    // Registrar no histórico
                    $stmt = $pdo->prepare("INSERT INTO historico_afiliados (afiliado_id, acao, detalhes) VALUES (?, 'admin_approval', ?)");
                    $stmt->execute([$user_id, "Transformado em afiliado pelo admin - Código: $codigo"]);
                    
                    $_SESSION['success'] = 'Usuário transformado em afiliado! Código: ' . $codigo;
                }
                break;
                
            case 'deactivate_affiliate':
                $stmt = $pdo->prepare("UPDATE usuarios SET afiliado_ativo = 0 WHERE id = ?");
                $stmt->execute([$user_id]);
                
                // Registrar no histórico
                $stmt = $pdo->prepare("INSERT INTO historico_afiliados (afiliado_id, acao, detalhes) VALUES (?, 'admin_deactivation', 'Afiliado desativado pelo admin')");
                $stmt->execute([$user_id]);
                
                $_SESSION['success'] = 'Afiliado desativado com sucesso!';
                break;
                
            case 'update_commission':
                $new_rate = floatval($_POST['commission_rate'] ?? 10);
                if ($new_rate < 0 || $new_rate > 50) {
                    $_SESSION['error'] = 'Taxa de comissão deve estar entre 0% e 50%';
                } else {
                    $stmt = $pdo->prepare("UPDATE usuarios SET porcentagem_afiliado = ? WHERE id = ?");
                    $stmt->execute([$new_rate, $user_id]);
                    $_SESSION['success'] = 'Taxa de comissão atualizada para ' . number_format($new_rate, 1) . '%';
                }
                break;
                
            case 'pay_commission':
                // Buscar comissão pendente
                $stmt = $pdo->prepare("SELECT comissao FROM usuarios WHERE id = ?");
                $stmt->execute([$user_id]);
                $comissao_pendente = floatval($stmt->fetchColumn());
                
                if ($comissao_pendente > 0) {
                    // Mover para saldo de comissão e zerar pendente
                    $stmt = $pdo->prepare("UPDATE usuarios SET saldo_comissao = saldo_comissao + ?, comissao = 0 WHERE id = ?");
                    $stmt->execute([$comissao_pendente, $user_id]);
                    
                    // Atualizar status das comissões
                    $stmt = $pdo->prepare("UPDATE comissoes SET status = 'pago', data_pagamento = NOW() WHERE afiliado_id = ? AND status = 'pendente'");
                    $stmt->execute([$user_id]);
                    
                    $_SESSION['success'] = 'Comissão de R$ ' . number_format($comissao_pendente, 2, ',', '.') . ' paga com sucesso!';
                } else {
                    $_SESSION['error'] = 'Nenhuma comissão pendente para este afiliado.';
                }
                break;
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Erro ao processar ação: ' . $e->getMessage();
    }
    
    header('Location: admin_afiliados.php');
    exit;
}

// Buscar afiliados ativos
try {
    $stmt = $pdo->query("
        SELECT 
            u.id,
            u.nome,
            u.email,
            u.codigo_afiliado,
            u.porcentagem_afiliado,
            u.afiliado_ativo,
            u.comissao,
            u.saldo_comissao,
            u.data_cadastro,
            u.data_aprovacao_afiliado,
            
            -- Estatísticas usando ambos os métodos (antigo e novo)
            (
                SELECT COUNT(*) FROM usuarios u2 
                WHERE u2.codigo_afiliado_usado = u.codigo_afiliado 
                OR u2.attributed_affiliate_id = u.id
            ) as total_indicados,
            
            (
                SELECT COALESCE(SUM(t.valor), 0) 
                FROM transacoes_pix t 
                WHERE t.status = 'aprovado' 
                AND (
                    t.affiliate_id = u.id 
                    OR EXISTS (
                        SELECT 1 FROM usuarios u3 
                        WHERE u3.id = t.usuario_id 
                        AND (u3.codigo_afiliado_usado = u.codigo_afiliado OR u3.attributed_affiliate_id = u.id)
                    )
                )
            ) as volume_total,
            
            (
                SELECT COUNT(*) 
                FROM affiliate_clicks ac 
                WHERE ac.affiliate_id = u.id OR ac.ref_code = u.codigo_afiliado
            ) as total_clicks,
            
            (
                SELECT COUNT(*) 
                FROM affiliate_clicks ac 
                WHERE (ac.affiliate_id = u.id OR ac.ref_code = u.codigo_afiliado) 
                AND ac.converteu = 1
            ) as clicks_convertidos
            
        FROM usuarios u
        WHERE u.codigo_afiliado IS NOT NULL 
        AND u.codigo_afiliado != ''
        ORDER BY u.afiliado_ativo DESC, u.data_cadastro DESC
    ");
    
    $afiliados = $stmt->fetchAll();
} catch (PDOException $e) {
    $afiliados = [];
    error_log("Erro ao buscar afiliados: " . $e->getMessage());
}

// Buscar usuários que podem se tornar afiliados
try {
    $stmt = $pdo->query("
        SELECT id, nome, email, saldo, data_cadastro
        FROM usuarios 
        WHERE (codigo_afiliado IS NULL OR codigo_afiliado = '') 
        AND ativo = 1
        ORDER BY data_cadastro DESC
        LIMIT 20
    ");
    $usuarios_nao_afiliados = $stmt->fetchAll();
} catch (PDOException $e) {
    $usuarios_nao_afiliados = [];
}

// Estatísticas gerais
try {
    $total_afiliados = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE codigo_afiliado IS NOT NULL AND codigo_afiliado != ''")->fetchColumn();
    $afiliados_ativos = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE afiliado_ativo = 1")->fetchColumn();
    $comissoes_pendentes = $pdo->query("SELECT COALESCE(SUM(comissao), 0) FROM usuarios WHERE afiliado_ativo = 1")->fetchColumn();
    $comissoes_pagas = $pdo->query("SELECT COALESCE(SUM(saldo_comissao), 0) FROM usuarios WHERE afiliado_ativo = 1")->fetchColumn();
    $volume_total = $pdo->query("
        SELECT COALESCE(SUM(t.valor), 0) 
        FROM transacoes_pix t 
        WHERE t.status = 'aprovado' 
        AND (
            t.affiliate_id IS NOT NULL 
            OR EXISTS (
                SELECT 1 FROM usuarios u 
                WHERE u.id = t.usuario_id 
                AND u.codigo_afiliado_usado IS NOT NULL
            )
        )
    ")->fetchColumn();
} catch (PDOException $e) {
    $total_afiliados = $afiliados_ativos = $comissoes_pendentes = $comissoes_pagas = $volume_total = 0;
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Afiliados - Admin</title>
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
            overflow-x: auto;
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
        .btn-info { background: var(--info-color); color: white; }
        .btn-sm { padding: 4px 8px; font-size: 11px; }

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

        .status-ativo {
            background: rgba(34, 197, 94, 0.15);
            color: var(--success-color);
        }

        .status-inativo {
            background: rgba(239, 68, 68, 0.15);
            color: var(--error-color);
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

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .empty-state h3 {
            color: var(--text-muted);
            margin-bottom: 8px;
            font-size: 18px;
        }

        .empty-state p {
            font-size: 14px;
            line-height: 1.6;
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

            .table {
                font-size: 11px;
            }

            .btn {
                padding: 6px 10px;
                font-size: 11px;
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

    <!-- Navigation -->
    <div class="nav-container">
        <div class="nav-content">
            <div class="nav-grid">
                <a href="painel_admin.php" class="nav-item">
                    <i class="fas fa-chart-line nav-icon"></i>
                    <div class="nav-title">Dashboard</div>
                    <div class="nav-desc">Visão geral e métricas</div>
                </a>

                <a href="admin_usuarios.php" class="nav-item">
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

                <a href="admin_afiliados.php" class="nav-item active">
                    <i class="fas fa-handshake nav-icon"></i>
                    <div class="nav-title">Afiliados</div>
                    <div class="nav-desc">Sistema de indicações</div>
                </a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-handshake"></i>
                Gerenciar Afiliados
            </h1>
            <div class="page-subtitle">
                <div class="live-indicator">
                    <div class="live-dot"></div>
                    <span>Sistema ativo</span>
                </div>
                <span>•</span>
                <span>Gerencie o programa de indicações e comissões</span>
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

        <!-- Estatísticas Gerais -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= number_format($total_afiliados) ?></div>
                <div class="stat-label">Total Afiliados</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--success-color);"><?= number_format($afiliados_ativos) ?></div>
                <div class="stat-label">Afiliados Ativos</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">R$ <?= number_format($volume_total, 2, ',', '.') ?></div>
                <div class="stat-label">Volume Total</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--warning-color);">R$ <?= number_format($comissoes_pendentes, 2, ',', '.') ?></div>
                <div class="stat-label">Comissões Pendentes</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--success-color);">R$ <?= number_format($comissoes_pagas, 2, ',', '.') ?></div>
                <div class="stat-label">Comissões Pagas</div>
            </div>
        </div>

        <!-- Lista de Afiliados Ativos -->
        <div class="card">
            <h3>
                <i class="fas fa-users"></i>
                Lista de Afiliados Ativos
            </h3>
            
            <?php if (empty($afiliados)): ?>
                <div class="empty-state">
                    <i class="fas fa-handshake"></i>
                    <h3>Nenhum afiliado encontrado</h3>
                    <p>Transforme usuários em afiliados na seção abaixo.</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Afiliado</th>
                                <th>Código</th>
                                <th>Comissão</th>
                                <th>Status</th>
                                <th>Indicados</th>
                                <th>Cliques</th>
                                <th>Volume</th>
                                <th>Pendente</th>
                                <th>Recebido</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($afiliados as $afiliado): ?>
                                <?php
                                $taxa_conversao = $afiliado['total_clicks'] > 0 ? 
                                    round(($afiliado['clicks_convertidos'] / $afiliado['total_clicks']) * 100, 1) : 0;
                                ?>
                                <tr>
                                    <td style="color: var(--text-light); font-weight: 600;">
                                        <div><?= htmlspecialchars($afiliado['nome']) ?></div>
                                        <div style="font-size: 11px; color: var(--text-muted);">
                                            <?= htmlspecialchars($afiliado['email']) ?>
                                        </div>
                                    </td>
                                    <td style="font-family: monospace; color: var(--primary-gold); font-weight: 700;">
                                        <?= htmlspecialchars($afiliado['codigo_afiliado']) ?>
                                    </td>
                                    <td style="color: var(--primary-green); font-weight: 700;">
                                        <?= number_format($afiliado['porcentagem_afiliado'], 1) ?>%
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?= $afiliado['afiliado_ativo'] ? 'ativo' : 'inativo' ?>">
                                            <i class="fas fa-<?= $afiliado['afiliado_ativo'] ? 'check-circle' : 'times-circle' ?>"></i>
                                            <?= $afiliado['afiliado_ativo'] ? 'Ativo' : 'Inativo' ?>
                                        </span>
                                    </td>
                                    <td style="color: var(--info-color); font-weight: 700;">
                                        <?= number_format($afiliado['total_indicados']) ?>
                                    </td>
                                    <td style="color: var(--purple-color); font-weight: 700;">
                                        <?= number_format($afiliado['total_clicks']) ?>
                                        <?php if ($afiliado['total_clicks'] > 0): ?>
                                            <div style="font-size: 10px; color: var(--text-muted);">
                                                <?= $taxa_conversao ?>% conversão
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="color: var(--success-color); font-weight: 700;">
                                        R$ <?= number_format($afiliado['volume_total'], 2, ',', '.') ?>
                                    </td>
                                    <td style="color: var(--warning-color); font-weight: 700;">
                                        R$ <?= number_format($afiliado['comissao'], 2, ',', '.') ?>
                                    </td>
                                    <td style="color: var(--success-color); font-weight: 700;">
                                        R$ <?= number_format($afiliado['saldo_comissao'], 2, ',', '.') ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                            <button type="button" 
                                                    class="btn btn-info btn-sm" 
                                                    onclick="editCommission(<?= $afiliado['id'] ?>, <?= $afiliado['porcentagem_afiliado'] ?>)"
                                                    title="Editar Comissão">
                                                <i class="fas fa-percentage"></i>
                                            </button>
                                            
                                            <?php if ($afiliado['comissao'] > 0): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="pay_commission">
                                                    <input type="hidden" name="user_id" value="<?= $afiliado['id'] ?>">
                                                    <button type="submit" 
                                                            class="btn btn-success btn-sm" 
                                                            onclick="return confirm('Pagar comissão de R$ <?= number_format($afiliado['comissao'], 2, ',', '.') ?>?')"
                                                            title="Pagar Comissão">
                                                        <i class="fas fa-dollar-sign"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <?php if ($afiliado['afiliado_ativo']): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="deactivate_affiliate">
                                                    <input type="hidden" name="user_id" value="<?= $afiliado['id'] ?>">
                                                    <button type="submit" 
                                                            class="btn btn-warning btn-sm" 
                                                            onclick="return confirm('Desativar afiliado?')"
                                                            title="Desativar Afiliado">
                                                        <i class="fas fa-pause"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="make_affiliate">
                                                    <input type="hidden" name="user_id" value="<?= $afiliado['id'] ?>">
                                                    <button type="submit" 
                                                            class="btn btn-success btn-sm" 
                                                            onclick="return confirm('Reativar afiliado?')"
                                                            title="Reativar Afiliado">
                                                        <i class="fas fa-play"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <button type="button" 
                                                    class="btn btn-primary btn-sm" 
                                                    onclick="viewDetails(<?= $afiliado['id'] ?>)"
                                                    title="Ver Detalhes">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Usuários que podem se tornar afiliados -->
        <div class="card">
            <h3>
                <i class="fas fa-user-plus"></i>
                Transformar em Afiliados
            </h3>
            
            <?php if (empty($usuarios_nao_afiliados)): ?>
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <h3>Todos os usuários já são afiliados</h3>
                    <p>Não há usuários disponíveis para transformar em afiliados.</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Usuário</th>
                                <th>Email</th>
                                <th>Saldo</th>
                                <th>Data Cadastro</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usuarios_nao_afiliados as $usuario): ?>
                                <tr>
                                    <td style="color: var(--text-light); font-weight: 600;">
                                        <?= htmlspecialchars($usuario['nome']) ?>
                                    </td>
                                    <td><?= htmlspecialchars($usuario['email']) ?></td>
                                    <td style="color: var(--primary-gold); font-weight: 700;">
                                        R$ <?= number_format($usuario['saldo'], 2, ',', '.') ?>
                                    </td>
                                    <td><?= date('d/m/Y H:i', strtotime($usuario['data_cadastro'])) ?></td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="make_affiliate">
                                            <input type="hidden" name="user_id" value="<?= $usuario['id'] ?>">
                                            <button type="submit" 
                                                    class="btn btn-primary btn-sm" 
                                                    onclick="return confirm('Transformar em afiliado?')"
                                                    title="Tornar Afiliado">
                                                <i class="fas fa-handshake"></i>
                                                Tornar Afiliado
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Modal Editar Comissão -->
    <div id="editCommissionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-percentage"></i>
                    Editar Taxa de Comissão
                </h3>
                <button class="modal-close" onclick="closeEditModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_commission">
                    <input type="hidden" name="user_id" id="editUserId">
                    
                    <div class="form-group">
                        <label class="form-label">Nova Taxa de Comissão (%)</label>
                        <input type="number" 
                               name="commission_rate" 
                               id="editCommissionRate" 
                               class="form-input" 
                               step="0.1" 
                               max="100" 
                               max="50"
                               required>
                        <small style="color: var(--text-muted); font-size: 11px;">
                            Digite a nova taxa de comissão (0% a 100%)
                        </small>
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

    <!-- Modal Ver Detalhes -->
    <div id="detailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-chart-line"></i>
                    Detalhes do Afiliado
                </h3>
                <button class="modal-close" onclick="closeDetailsModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div id="affiliateDetails">
                    <div style="text-align: center; padding: 20px; color: var(--text-muted);">
                        <i class="fas fa-spinner fa-spin"></i>
                        <p>Carregando detalhes...</p>
                    </div>
                </div>
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
            // Implementar se necessário
        }

        // Editar comissão
        function editCommission(userId, currentRate) {
            document.getElementById('editUserId').value = userId;
            document.getElementById('editCommissionRate').value = currentRate;
            document.getElementById('editCommissionModal').classList.add('show');
        }

        function closeEditModal() {
            document.getElementById('editCommissionModal').classList.remove('show');
        }

        // Ver detalhes do afiliado
        function viewDetails(affiliateId) {
            document.getElementById('detailsModal').classList.add('show');
            
            fetch(`get_affiliate_details.php?id=${affiliateId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayAffiliateDetails(data.affiliate);
                    } else {
                        document.getElementById('affiliateDetails').innerHTML = `
                            <div style="text-align: center; padding: 20px; color: var(--error-color);">
                                <i class="fas fa-exclamation-triangle"></i>
                                <p>Erro ao carregar detalhes: ${data.error}</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    document.getElementById('affiliateDetails').innerHTML = `
                        <div style="text-align: center; padding: 20px; color: var(--error-color);">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>Erro ao carregar detalhes</p>
                        </div>
                    `;
                });
        }

        function displayAffiliateDetails(affiliate) {
            const html = `
                <div style="margin-bottom: 20px;">
                    <h4 style="color: var(--primary-green); margin-bottom: 12px;">Informações Gerais</h4>
                    <div style="background: var(--bg-card); padding: 16px; border-radius: 8px;">
                        <p><strong>Nome:</strong> ${affiliate.nome}</p>
                        <p><strong>Email:</strong> ${affiliate.email}</p>
                        <p><strong>Código:</strong> ${affiliate.codigo_afiliado}</p>
                        <p><strong>Taxa:</strong> ${parseFloat(affiliate.porcentagem_afiliado).toFixed(1)}%</p>
                        <p><strong>Cadastro:</strong> ${new Date(affiliate.data_cadastro).toLocaleDateString('pt-BR')}</p>
                    </div>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <h4 style="color: var(--primary-green); margin-bottom: 12px;">Estatísticas</h4>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;">
                        <div style="background: var(--bg-card); padding: 12px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 18px; font-weight: 700; color: var(--primary-gold);">${affiliate.total_referidos}</div>
                            <div style="font-size: 11px; color: var(--text-muted);">Indicados</div>
                        </div>
                        <div style="background: var(--bg-card); padding: 12px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 18px; font-weight: 700; color: var(--success-color);">R$ ${parseFloat(affiliate.volume_total).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</div>
                            <div style="font-size: 11px; color: var(--text-muted);">Volume</div>
                        </div>
                        <div style="background: var(--bg-card); padding: 12px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 18px; font-weight: 700; color: var(--warning-color);">R$ ${parseFloat(affiliate.comissao).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</div>
                            <div style="font-size: 11px; color: var(--text-muted);">Pendente</div>
                        </div>
                        <div style="background: var(--bg-card); padding: 12px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 18px; font-weight: 700; color: var(--success-color);">R$ ${parseFloat(affiliate.saldo_comissao).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</div>
                            <div style="font-size: 11px; color: var(--text-muted);">Recebido</div>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('affiliateDetails').innerHTML = html;
        }

        function closeDetailsModal() {
            document.getElementById('detailsModal').classList.remove('show');
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