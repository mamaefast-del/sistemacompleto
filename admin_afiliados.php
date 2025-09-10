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
    $affiliate_id = intval($_POST['affiliate_id'] ?? 0);
    
    try {
        switch ($action) {
            case 'create_affiliate':
                $user_id = intval($_POST['user_id'] ?? 0);
                $percentage = floatval($_POST['percentage'] ?? 10);
                
                // Verificar se usuário existe
                $stmt = $pdo->prepare("SELECT id, codigo_afiliado FROM usuarios WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
                
                if ($user) {
                    if (empty($user['codigo_afiliado'])) {
                        // Gerar código único
                        do {
                            $codigo = strtoupper(substr(md5(uniqid() . $user_id . time()), 0, 8));
                            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE codigo_afiliado = ?");
                            $stmt->execute([$codigo]);
                        } while ($stmt->fetch());
                        
                        // Criar afiliado
                        $stmt = $pdo->prepare("
                            UPDATE usuarios 
                            SET codigo_afiliado = ?, afiliado_ativo = 1, porcentagem_afiliado = ?, data_aprovacao_afiliado = NOW(), aprovado_por = ? 
                            WHERE id = ?
                        ");
                        $stmt->execute([$codigo, $percentage, $_SESSION['admin_id'] ?? 1, $user_id]);
                        
                        // Registrar no histórico
                        $stmt = $pdo->prepare("INSERT INTO historico_afiliados (afiliado_id, acao, detalhes, admin_id) VALUES (?, 'create_affiliate', ?, ?)");
                        $stmt->execute([$user_id, "Usuário transformado em afiliado - Código: $codigo", $_SESSION['admin_id'] ?? 1]);
                        
                        $_SESSION['success'] = "Afiliado criado com sucesso! Código: $codigo";
                    } else {
                        $_SESSION['error'] = 'Usuário já é afiliado!';
                    }
                }
                break;
                
            case 'toggle_status':
                $stmt = $pdo->prepare("UPDATE usuarios SET afiliado_ativo = NOT afiliado_ativo WHERE id = ?");
                $stmt->execute([$affiliate_id]);
                
                // Registrar no histórico
                $stmt = $pdo->prepare("INSERT INTO historico_afiliados (afiliado_id, acao, detalhes, admin_id) VALUES (?, 'toggle_status', 'Status alterado pelo admin', ?)");
                $stmt->execute([$affiliate_id, $_SESSION['admin_id'] ?? 1]);
                
                $_SESSION['success'] = 'Status do afiliado alterado!';
                break;
                
            case 'update_commission':
                $new_percentage = floatval($_POST['new_percentage'] ?? 10);
                
                $stmt = $pdo->prepare("SELECT porcentagem_afiliado FROM usuarios WHERE id = ?");
                $stmt->execute([$affiliate_id]);
                $old_percentage = $stmt->fetchColumn();
                
                $stmt = $pdo->prepare("UPDATE usuarios SET porcentagem_afiliado = ? WHERE id = ?");
                $stmt->execute([$new_percentage, $affiliate_id]);
                
                // Registrar no histórico
                $stmt = $pdo->prepare("INSERT INTO historico_afiliados (afiliado_id, acao, detalhes, valor_anterior, valor_novo, admin_id) VALUES (?, 'update_commission', 'Percentual de comissão alterado', ?, ?, ?)");
                $stmt->execute([$affiliate_id, $old_percentage, $new_percentage, $_SESSION['admin_id'] ?? 1]);
                
                $_SESSION['success'] = 'Percentual de comissão atualizado!';
                break;
                
            case 'pay_commission':
                // Buscar comissão pendente
                $stmt = $pdo->prepare("SELECT comissao FROM usuarios WHERE id = ?");
                $stmt->execute([$affiliate_id]);
                $comissao_pendente = $stmt->fetchColumn();
                
                if ($comissao_pendente > 0) {
                    // Transferir para saldo_comissao e zerar comissao
                    $stmt = $pdo->prepare("UPDATE usuarios SET saldo_comissao = saldo_comissao + comissao, comissao = 0 WHERE id = ?");
                    $stmt->execute([$affiliate_id]);
                    
                    // Atualizar status das comissões para 'pago'
                    $stmt = $pdo->prepare("UPDATE comissoes SET status = 'pago', data_pagamento = NOW() WHERE afiliado_id = ? AND status = 'pendente'");
                    $stmt->execute([$affiliate_id]);
                    
                    // Registrar no histórico
                    $stmt = $pdo->prepare("INSERT INTO historico_afiliados (afiliado_id, acao, detalhes, valor_novo, admin_id) VALUES (?, 'pay_commission', 'Comissão paga pelo admin', ?, ?)");
                    $stmt->execute([$affiliate_id, $comissao_pendente, $_SESSION['admin_id'] ?? 1]);
                    
                    $_SESSION['success'] = "Comissão de R$ " . number_format($comissao_pendente, 2, ',', '.') . " paga com sucesso!";
                } else {
                    $_SESSION['error'] = 'Nenhuma comissão pendente para este afiliado.';
                }
                break;
                
            case 'reset_stats':
                // Recalcular estatísticas do afiliado
                $stmt = $pdo->prepare("SELECT codigo_afiliado FROM usuarios WHERE id = ?");
                $stmt->execute([$affiliate_id]);
                $codigo = $stmt->fetchColumn();
                
                if ($codigo) {
                    // Contar indicados
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE codigo_afiliado_usado = ?");
                    $stmt->execute([$codigo]);
                    $total_indicados = $stmt->fetchColumn();
                    
                    // Calcular comissão total gerada
                    $stmt = $pdo->prepare("SELECT COALESCE(SUM(valor_comissao), 0) FROM comissoes WHERE afiliado_id = ?");
                    $stmt->execute([$affiliate_id]);
                    $total_comissao = $stmt->fetchColumn();
                    
                    // Atualizar estatísticas
                    $stmt = $pdo->prepare("UPDATE usuarios SET total_indicados = ?, total_comissao_gerada = ? WHERE id = ?");
                    $stmt->execute([$total_indicados, $total_comissao, $affiliate_id]);
                    
                    // Registrar no histórico
                    $stmt = $pdo->prepare("INSERT INTO historico_afiliados (afiliado_id, acao, detalhes, admin_id) VALUES (?, 'reset_stats', 'Estatísticas recalculadas', ?)");
                    $stmt->execute([$affiliate_id, $_SESSION['admin_id'] ?? 1]);
                    
                    $_SESSION['success'] = 'Estatísticas recalculadas com sucesso!';
                }
                break;
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Erro ao processar ação: ' . $e->getMessage();
    }
    
    header('Location: admin_afiliados.php');
    exit;
}

// Buscar afiliados com estatísticas
try {
    $afiliados = $pdo->query("
        SELECT 
            u.id,
            u.nome,
            u.email,
            u.telefone,
            u.codigo_afiliado,
            u.porcentagem_afiliado,
            u.afiliado_ativo,
            u.comissao,
            u.saldo_comissao,
            u.total_indicados,
            u.total_comissao_gerada,
            u.data_cadastro,
            u.data_aprovacao_afiliado,
            COALESCE(clicks.total_clicks, 0) as total_clicks,
            COALESCE(clicks.cliques_convertidos, 0) as cliques_convertidos,
            COALESCE(volume.volume_gerado, 0) as volume_gerado,
            CASE 
                WHEN COALESCE(clicks.total_clicks, 0) > 0 
                THEN (COALESCE(clicks.cliques_convertidos, 0) / COALESCE(clicks.total_clicks, 1)) * 100 
                ELSE 0 
            END as taxa_conversao
        FROM usuarios u
        LEFT JOIN (
            SELECT 
                afiliado_id, 
                COUNT(*) as total_clicks,
                COUNT(CASE WHEN converteu = 1 THEN 1 END) as cliques_convertidos
            FROM affiliate_clicks 
            GROUP BY afiliado_id
        ) clicks ON clicks.afiliado_id = u.id
        LEFT JOIN (
            SELECT 
                a.id as afiliado_id,
                COALESCE(SUM(t.valor), 0) as volume_gerado
            FROM usuarios a
            JOIN usuarios u2 ON u2.codigo_afiliado_usado = a.codigo_afiliado
            LEFT JOIN transacoes_pix t ON t.usuario_id = u2.id AND t.status = 'aprovado'
            GROUP BY a.id
        ) volume ON volume.afiliado_id = u.id
        WHERE u.codigo_afiliado IS NOT NULL AND u.codigo_afiliado != ''
        ORDER BY u.total_comissao_gerada DESC
    ")->fetchAll();
} catch (PDOException $e) {
    $afiliados = [];
}

// Buscar usuários não afiliados para criar afiliados
try {
    $usuarios_nao_afiliados = $pdo->query("
        SELECT id, nome, email, data_cadastro 
        FROM usuarios 
        WHERE (codigo_afiliado IS NULL OR codigo_afiliado = '') 
        ORDER BY data_cadastro DESC 
        LIMIT 20
    ")->fetchAll();
} catch (PDOException $e) {
    $usuarios_nao_afiliados = [];
}

// Estatísticas gerais
try {
    $stats = $pdo->query("
        SELECT 
            COUNT(*) as total_afiliados,
            COUNT(CASE WHEN afiliado_ativo = 1 THEN 1 END) as afiliados_ativos,
            COUNT(CASE WHEN afiliado_ativo = 0 THEN 1 END) as afiliados_inativos,
            SUM(total_indicados) as total_indicados,
            SUM(total_comissao_gerada) as total_comissao_gerada,
            SUM(comissao) as total_comissao_pendente,
            SUM(saldo_comissao) as total_comissao_paga,
            AVG(porcentagem_afiliado) as media_percentual
        FROM usuarios 
        WHERE codigo_afiliado IS NOT NULL AND codigo_afiliado != ''
    ")->fetch();
    
    $total_clicks = $pdo->query("SELECT COUNT(*) FROM affiliate_clicks")->fetchColumn();
    $cliques_convertidos = $pdo->query("SELECT COUNT(*) FROM affiliate_clicks WHERE converteu = 1")->fetchColumn();
    $taxa_conversao_geral = $total_clicks > 0 ? ($cliques_convertidos / $total_clicks) * 100 : 0;
} catch (PDOException $e) {
    $stats = [
        'total_afiliados' => 0,
        'afiliados_ativos' => 0,
        'afiliados_inativos' => 0,
        'total_indicados' => 0,
        'total_comissao_gerada' => 0,
        'total_comissao_pendente' => 0,
        'total_comissao_paga' => 0,
        'media_percentual' => 0
    ];
    $total_clicks = $cliques_convertidos = $taxa_conversao_geral = 0;
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Afiliados - Admin</title>
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

        /* Tabs */
        .tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 32px;
            background: var(--bg-panel);
            padding: 8px;
            border-radius: var(--radius);
            border: 1px solid var(--border-color);
        }

        .tab-button {
            flex: 1;
            padding: 16px 24px;
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
            font-size: 14px;
        }

        .tab-button:hover {
            background: var(--bg-card);
            color: var(--text-light);
        }

        .tab-button.active {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-gold));
            color: #000;
            box-shadow: 0 4px 16px rgba(0, 212, 170, 0.3);
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
            color: var(--primary-green);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 20px;
        }

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

        /* Performance bars */
        .performance-bar {
            width: 100%;
            height: 6px;
            background: var(--bg-card);
            border-radius: 3px;
            overflow: hidden;
            margin-top: 4px;
        }

        .performance-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-green), var(--primary-gold));
            border-radius: 3px;
            transition: width 0.8s ease;
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
            border: 2px solid var(--primary-green);
            border-radius: var(--radius);
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-gold));
            color: #000;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
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
            max-height: 60vh;
            overflow-y: auto;
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
                <i class="fas fa-handshake"></i>
                Sistema de Afiliados
            </h1>
            <div class="page-subtitle">
                <div class="live-indicator">
                    <div class="live-dot"></div>
                    <span>Sistema ativo</span>
                </div>
                <span>•</span>
                <span>Gerencie afiliados e comissões da plataforma</span>
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
                <div class="stat-value"><?= number_format($stats['total_afiliados']) ?></div>
                <div class="stat-label">Total Afiliados</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--success-color);"><?= number_format($stats['afiliados_ativos']) ?></div>
                <div class="stat-label">Afiliados Ativos</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= number_format($stats['total_indicados']) ?></div>
                <div class="stat-label">Total Indicados</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= number_format($total_clicks) ?></div>
                <div class="stat-label">Total Cliques</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= number_format($taxa_conversao_geral, 1) ?>%</div>
                <div class="stat-label">Taxa Conversão</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">R$ <?= number_format($stats['total_comissao_pendente'], 2, ',', '.') ?></div>
                <div class="stat-label">Comissões Pendentes</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--success-color);">R$ <?= number_format($stats['total_comissao_paga'], 2, ',', '.') ?></div>
                <div class="stat-label">Comissões Pagas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= number_format($stats['media_percentual'], 1) ?>%</div>
                <div class="stat-label">Média Percentual</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab-button active" onclick="switchTab('afiliados')">
                <i class="fas fa-users"></i>
                Afiliados Ativos
            </button>
            <button class="tab-button" onclick="switchTab('criar')">
                <i class="fas fa-user-plus"></i>
                Criar Afiliados
            </button>
            <button class="tab-button" onclick="switchTab('materiais')">
                <i class="fas fa-images"></i>
                Materiais
            </button>
        </div>

        <!-- Tab Content: Afiliados Ativos -->
        <div id="afiliados" class="tab-content active">
            <div class="card">
                <h3>
                    <i class="fas fa-users"></i>
                    Lista de Afiliados Ativos
                </h3>
                
                <?php if (empty($afiliados)): ?>
                    <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                        <i class="fas fa-handshake" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
                        <p>Nenhum afiliado cadastrado ainda.</p>
                        <p style="font-size: 12px; margin-top: 8px;">Use a aba "Criar Afiliados" para começar.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Afiliado</th>
                                    <th>Código</th>
                                    <th>Performance</th>
                                    <th>Comissões</th>
                                    <th>Status</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($afiliados as $afiliado): ?>
                                    <tr>
                                        <td style="color: var(--text-light); font-weight: 600;">
                                            <div>
                                                <?= htmlspecialchars($afiliado['nome'] ?: 'Afiliado #' . $afiliado['id']) ?>
                                            </div>
                                            <div style="font-size: 11px; color: var(--text-muted);">
                                                <?= htmlspecialchars($afiliado['email']) ?>
                                            </div>
                                            <?php if ($afiliado['telefone']): ?>
                                                <div style="font-size: 11px; color: var(--text-muted);">
                                                    <?= htmlspecialchars($afiliado['telefone']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="background: var(--bg-card); padding: 4px 8px; border-radius: 6px; font-family: monospace; font-weight: 700; color: var(--primary-green); border: 1px solid var(--border-color); font-size: 11px; display: inline-block;">
                                                <?= htmlspecialchars($afiliado['codigo_afiliado']) ?>
                                            </div>
                                            <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">
                                                <?= number_format($afiliado['porcentagem_afiliado'], 1) ?>% comissão
                                            </div>
                                        </td>
                                        <td>
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                                <span style="font-size: 11px; color: var(--text-muted);">Indicados:</span>
                                                <span style="font-weight: 600; color: var(--primary-green);"><?= $afiliado['total_indicados'] ?></span>
                                            </div>
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                                <span style="font-size: 11px; color: var(--text-muted);">Cliques:</span>
                                                <span style="font-weight: 600;"><?= $afiliado['total_clicks'] ?></span>
                                            </div>
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                                <span style="font-size: 11px; color: var(--text-muted);">Conversão:</span>
                                                <span style="font-weight: 600; color: var(--primary-gold);"><?= number_format($afiliado['taxa_conversao'], 1) ?>%</span>
                                            </div>
                                            <div class="performance-bar">
                                                <div class="performance-fill" style="width: <?= min(100, $afiliado['taxa_conversao']) ?>%;"></div>
                                            </div>
                                            <div style="font-size: 10px; color: var(--text-muted); margin-top: 4px;">
                                                Volume: R$ <?= number_format($afiliado['volume_gerado'], 2, ',', '.') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                                <span style="font-size: 11px; color: var(--text-muted);">Pendente:</span>
                                                <span style="font-weight: 600; color: var(--warning-color);">R$ <?= number_format($afiliado['comissao'], 2, ',', '.') ?></span>
                                            </div>
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                                <span style="font-size: 11px; color: var(--text-muted);">Pago:</span>
                                                <span style="font-weight: 600; color: var(--success-color);">R$ <?= number_format($afiliado['saldo_comissao'], 2, ',', '.') ?></span>
                                            </div>
                                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                                <span style="font-size: 11px; color: var(--text-muted);">Total:</span>
                                                <span style="font-weight: 600; color: var(--primary-green);">R$ <?= number_format($afiliado['total_comissao_gerada'], 2, ',', '.') ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?= $afiliado['afiliado_ativo'] ? 'ativo' : 'inativo' ?>">
                                                <i class="fas fa-<?= $afiliado['afiliado_ativo'] ? 'check-circle' : 'times-circle' ?>"></i>
                                                <?= $afiliado['afiliado_ativo'] ? 'Ativo' : 'Inativo' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                                <button type="button" 
                                                        class="btn btn-info btn-sm" 
                                                        onclick="showAffiliateDetails(<?= $afiliado['id'] ?>)"
                                                        title="Ver Detalhes">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                
                                                <button type="button" 
                                                        class="btn btn-primary btn-sm" 
                                                        onclick="editCommission(<?= $afiliado['id'] ?>, <?= $afiliado['porcentagem_afiliado'] ?>)"
                                                        title="Editar Comissão">
                                                    <i class="fas fa-percentage"></i>
                                                </button>
                                                
                                                <?php if ($afiliado['comissao'] > 0): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="pay_commission">
                                                        <input type="hidden" name="affiliate_id" value="<?= $afiliado['id'] ?>">
                                                        <button type="submit" 
                                                                class="btn btn-success btn-sm" 
                                                                onclick="return confirm('Pagar comissão de R$ <?= number_format($afiliado['comissao'], 2, ',', '.') ?>?')"
                                                                title="Pagar Comissão">
                                                            <i class="fas fa-dollar-sign"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="affiliate_id" value="<?= $afiliado['id'] ?>">
                                                    <button type="submit" 
                                                            class="btn btn-<?= $afiliado['afiliado_ativo'] ? 'warning' : 'success' ?> btn-sm" 
                                                            onclick="return confirm('<?= $afiliado['afiliado_ativo'] ? 'Desativar' : 'Ativar' ?> afiliado?')"
                                                            title="<?= $afiliado['afiliado_ativo'] ? 'Desativar' : 'Ativar' ?> Afiliado">
                                                        <i class="fas fa-<?= $afiliado['afiliado_ativo'] ? 'ban' : 'check' ?>"></i>
                                                    </button>
                                                </form>
                                                
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="reset_stats">
                                                    <input type="hidden" name="affiliate_id" value="<?= $afiliado['id'] ?>">
                                                    <button type="submit" 
                                                            class="btn btn-secondary btn-sm" 
                                                            onclick="return confirm('Recalcular estatísticas?')"
                                                            title="Recalcular Estatísticas">
                                                        <i class="fas fa-sync-alt"></i>
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
        </div>

        <!-- Tab Content: Criar Afiliados -->
        <div id="criar" class="tab-content">
            <div class="card">
                <h3>
                    <i class="fas fa-user-plus"></i>
                    Transformar Usuários em Afiliados
                </h3>
                
                <?php if (empty($usuarios_nao_afiliados)): ?>
                    <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                        <i class="fas fa-users-slash" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
                        <p>Todos os usuários já são afiliados ou não há usuários cadastrados.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Usuário</th>
                                    <th>Email</th>
                                    <th>Data Cadastro</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($usuarios_nao_afiliados as $user): ?>
                                    <tr>
                                        <td style="color: var(--text-light); font-weight: 600;">
                                            <?= htmlspecialchars($user['nome'] ?: 'Usuário #' . $user['id']) ?>
                                        </td>
                                        <td><?= htmlspecialchars($user['email']) ?></td>
                                        <td><?= date('d/m/Y H:i', strtotime($user['data_cadastro'])) ?></td>
                                        <td>
                                            <button type="button" 
                                                    class="btn btn-primary btn-sm" 
                                                    onclick="createAffiliate(<?= $user['id'] ?>, '<?= htmlspecialchars($user['nome']) ?>')"
                                                    title="Transformar em Afiliado">
                                                <i class="fas fa-handshake"></i>
                                                Tornar Afiliado
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tab Content: Materiais -->
        <div id="materiais" class="tab-content">
            <div class="card">
                <h3>
                    <i class="fas fa-images"></i>
                    Materiais de Marketing
                </h3>
                
                <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                    <i class="fas fa-tools" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
                    <p>Seção de materiais em desenvolvimento.</p>
                    <p style="font-size: 12px; margin-top: 8px;">Em breve: banners, imagens e materiais promocionais.</p>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal Detalhes do Afiliado -->
    <div id="affiliateDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-chart-line"></i>
                    Detalhes do Afiliado
                </h3>
                <button class="modal-close" onclick="closeAffiliateDetailsModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div id="affiliate-details-content">
                    <div style="text-align: center; padding: 20px; color: var(--text-muted);">
                        <i class="fas fa-spinner fa-spin"></i>
                        <p>Carregando detalhes...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Criar Afiliado -->
    <div id="createAffiliateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-user-plus"></i>
                    Criar Novo Afiliado
                </h3>
                <button class="modal-close" onclick="closeCreateAffiliateModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="create_affiliate">
                    <input type="hidden" name="user_id" id="createUserId">
                    
                    <div class="form-group">
                        <label class="form-label">Usuário Selecionado</label>
                        <input type="text" id="createUserName" class="form-input" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Percentual de Comissão (%)</label>
                        <input type="number" 
                               name="percentage" 
                               class="form-input" 
                               step="0.1" 
                               min="0" 
                               max="100"
                               value="10.0"
                               required>
                        <small style="color: var(--text-muted); font-size: 11px;">
                            Percentual que o afiliado receberá sobre depósitos dos indicados
                        </small>
                    </div>
                    
                    <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">
                        <button type="button" class="btn btn-secondary" onclick="closeCreateAffiliateModal()">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-handshake"></i>
                            Criar Afiliado
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Editar Comissão -->
    <div id="editCommissionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-percentage"></i>
                    Editar Comissão
                </h3>
                <button class="modal-close" onclick="closeEditCommissionModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_commission">
                    <input type="hidden" name="affiliate_id" id="editAffiliateId">
                    
                    <div class="form-group">
                        <label class="form-label">Novo Percentual de Comissão (%)</label>
                        <input type="number" 
                               name="new_percentage" 
                               id="editCommissionInput" 
                               class="form-input" 
                               step="0.1" 
                               min="0" 
                               max="99"
                               required>
                        <small style="color: var(--text-muted); font-size: 11px;">
                            Percentual que o afiliado receberá sobre depósitos dos indicados
                        </small>
                    </div>
                    
                    <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">
                        <button type="button" class="btn btn-secondary" onclick="closeEditCommissionModal()">
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
                <div id="online-users-list">
                    <div style="text-align: center; padding: 20px; color: var(--text-muted);">
                        <i class="fas fa-spinner fa-spin"></i>
                        <p>Carregando usuários online...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Função para trocar abas
        function switchTab(tabName) {
            // Esconde todas as abas
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            // Remove 'active' dos botões
            document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
            
            // Mostra a aba clicada
            const targetTab = document.getElementById(tabName);
            if (targetTab) {
                targetTab.classList.add('active');
            }
            
            // Marca o botão clicado
            event.target.classList.add('active');
        }

        // Funções do Modal Detalhes do Afiliado
        function showAffiliateDetails(affiliateId) {
            document.getElementById('affiliateDetailsModal').classList.add('show');
            
            // Carregar detalhes via AJAX
            fetch(`get_affiliate_details.php?id=${affiliateId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const affiliate = data.affiliate;
                        const content = `
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px;">
                                <div style="background: var(--bg-card); padding: 16px; border-radius: 8px; text-align: center;">
                                    <div style="font-size: 20px; font-weight: 700; color: var(--primary-green);">${affiliate.total_indicados}</div>
                                    <div style="font-size: 12px; color: var(--text-muted);">Indicados</div>
                                </div>
                                <div style="background: var(--bg-card); padding: 16px; border-radius: 8px; text-align: center;">
                                    <div style="font-size: 20px; font-weight: 700; color: var(--primary-gold);">${affiliate.total_clicks}</div>
                                    <div style="font-size: 12px; color: var(--text-muted);">Cliques</div>
                                </div>
                                <div style="background: var(--bg-card); padding: 16px; border-radius: 8px; text-align: center;">
                                    <div style="font-size: 20px; font-weight: 700; color: var(--info-color);">${parseFloat(affiliate.taxa_conversao).toFixed(1)}%</div>
                                    <div style="font-size: 12px; color: var(--text-muted);">Taxa Conversão</div>
                                </div>
                                <div style="background: var(--bg-card); padding: 16px; border-radius: 8px; text-align: center;">
                                    <div style="font-size: 20px; font-weight: 700; color: var(--success-color);">R$ ${parseFloat(affiliate.volume_gerado).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</div>
                                    <div style="font-size: 12px; color: var(--text-muted);">Volume Gerado</div>
                                </div>
                            </div>
                            
                            <div style="background: var(--bg-card); padding: 16px; border-radius: 8px; margin-bottom: 20px;">
                                <h4 style="color: var(--primary-green); margin-bottom: 12px;">Informações do Afiliado</h4>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 14px;">
                                    <div><strong>Nome:</strong> ${affiliate.nome || 'N/A'}</div>
                                    <div><strong>Email:</strong> ${affiliate.email}</div>
                                    <div><strong>Código:</strong> ${affiliate.codigo_afiliado}</div>
                                    <div><strong>Comissão:</strong> ${parseFloat(affiliate.porcentagem_afiliado).toFixed(1)}%</div>
                                    <div><strong>Cadastro:</strong> ${new Date(affiliate.data_cadastro).toLocaleDateString('pt-BR')}</div>
                                    <div><strong>Aprovação:</strong> ${affiliate.data_aprovacao_afiliado ? new Date(affiliate.data_aprovacao_afiliado).toLocaleDateString('pt-BR') : 'N/A'}</div>
                                </div>
                            </div>
                            
                            <div style="background: var(--bg-card); padding: 16px; border-radius: 8px;">
                                <h4 style="color: var(--primary-green); margin-bottom: 12px;">Explicação da Taxa de Conversão</h4>
                                <div style="font-size: 13px; line-height: 1.6; color: var(--text-muted);">
                                    <p><strong>Taxa de Conversão = (Cliques que Converteram ÷ Total de Cliques) × 100</strong></p>
                                    <p style="margin-top: 8px;">• <strong>Total de Cliques:</strong> ${affiliate.total_clicks} pessoas clicaram no link</p>
                                    <p>• <strong>Cliques Convertidos:</strong> ${affiliate.cliques_convertidos || 0} se cadastraram</p>
                                    <p>• <strong>Taxa:</strong> ${parseFloat(affiliate.taxa_conversao).toFixed(1)}% dos cliques viraram cadastros</p>
                                    <p style="margin-top: 8px; color: var(--warning-color);"><strong>Nota:</strong> A taxa sempre fica entre 0% e 100%. Valores acima de 100% indicavam erro no cálculo anterior.</p>
                                </div>
                            </div>
                        `;
                        
                        document.getElementById('affiliate-details-content').innerHTML = content;
                    } else {
                        document.getElementById('affiliate-details-content').innerHTML = `
                            <div style="text-align: center; padding: 20px; color: var(--error-color);">
                                <i class="fas fa-exclamation-triangle"></i>
                                <p>Erro ao carregar detalhes do afiliado</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    document.getElementById('affiliate-details-content').innerHTML = `
                        <div style="text-align: center; padding: 20px; color: var(--error-color);">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>Erro ao carregar detalhes</p>
                        </div>
                    `;
                });
        }

        function closeAffiliateDetailsModal() {
            document.getElementById('affiliateDetailsModal').classList.remove('show');
        }

        // Funções do Modal Criar Afiliado
        function createAffiliate(userId, userName) {
            document.getElementById('createUserId').value = userId;
            document.getElementById('createUserName').value = userName;
            document.getElementById('createAffiliateModal').classList.add('show');
        }

        function closeCreateAffiliateModal() {
            document.getElementById('createAffiliateModal').classList.remove('show');
        }

        // Funções do Modal Editar Comissão
        function editCommission(affiliateId, currentPercentage) {
            document.getElementById('editAffiliateId').value = affiliateId;
            document.getElementById('editCommissionInput').value = currentPercentage;
            document.getElementById('editCommissionModal').classList.add('show');
        }

        function closeEditCommissionModal() {
            document.getElementById('editCommissionModal').classList.remove('show');
        }

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
                    <div style="display: flex; align-items: center; gap: 12px; padding: 12px; background: var(--bg-card); border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 8px;">
                        <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--primary-green); display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 12px; color: #000;">
                            ${(user.nome || user.email).charAt(0).toUpperCase()}
                        </div>
                        <div style="flex: 1;">
                            <div style="font-weight: 500; color: var(--text-light); font-size: 14px;">${user.nome || 'Usuário'}</div>
                            <div style="color: var(--text-muted); font-size: 12px;">${user.email}</div>
                        </div>
                        <div style="font-size: 11px; color: var(--success-color); font-weight: 500;">Online</div>
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

        // Tecla ESC para fechar modais
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
            setInterval(updateHeaderStats, 30000); // Atualizar a cada 30 segundos
        });
    </script>
</body>
</html>