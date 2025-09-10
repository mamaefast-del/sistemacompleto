<?php
session_start();

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    header('Location: login.php');
    exit;
}

require 'db.php';
require_once 'includes/affiliate_tracker.php';

// Buscar estatísticas para o header
try {
    $pendentes_depositos = $pdo->query("SELECT COUNT(*) FROM transacoes_pix WHERE status = 'pendente'")->fetchColumn();
    $pendentes_saques = $pdo->query("SELECT COUNT(*) FROM saques WHERE status = 'pendente'")->fetchColumn();
    $usuarios_online = $pdo->query("SELECT COUNT(DISTINCT usuario_id) FROM historico_jogos WHERE data_jogo >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetchColumn();
} catch (PDOException $e) {
    $pendentes_depositos = $pendentes_saques = $usuarios_online = 0;
}

// Filtros
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$affiliateFilter = $_GET['affiliate'] ?? '';
$utmSource = $_GET['utm_source'] ?? '';

// Buscar relatório de afiliados
try {
    $whereConditions = [];
    $params = [];
    
    if ($affiliateFilter) {
        $whereConditions[] = "u.codigo_afiliado = ?";
        $params[] = $affiliateFilter;
    }
    
    if ($utmSource) {
        $whereConditions[] = "ac.utm_source = ?";
        $params[] = $utmSource;
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.nome,
            u.email,
            u.codigo_afiliado,
            u.porcentagem_afiliado,
            u.afiliado_ativo,
            
            -- Cliques
            COUNT(DISTINCT ac.id) as total_clicks,
            COUNT(DISTINCT ac.ip_address) as unique_visitors,
            COUNT(DISTINCT CASE WHEN ac.converteu = 1 THEN ac.id END) as converted_clicks,
            
            -- Cadastros
            COUNT(DISTINCT aa.user_id) as total_signups,
            COUNT(DISTINCT CASE WHEN DATE(aa.created_at) BETWEEN ? AND ? THEN aa.user_id END) as signups_period,
            
            -- Depósitos
            COUNT(DISTINCT CASE WHEN t.status = 'aprovado' THEN t.id END) as total_deposits,
            COALESCE(SUM(CASE WHEN t.status = 'aprovado' THEN t.valor ELSE 0 END), 0) as total_deposit_amount,
            COUNT(DISTINCT CASE WHEN t.is_first_deposit = 1 AND t.status = 'aprovado' THEN t.id END) as first_deposits,
            COALESCE(SUM(CASE WHEN t.is_first_deposit = 1 AND t.status = 'aprovado' THEN t.valor ELSE 0 END), 0) as first_deposit_amount,
            
            -- Comissões
            COALESCE(SUM(c.valor_comissao), 0) as total_commission,
            COALESCE(SUM(CASE WHEN c.status = 'pendente' THEN c.valor_comissao ELSE 0 END), 0) as pending_commission,
            COALESCE(SUM(CASE WHEN c.status = 'pago' THEN c.valor_comissao ELSE 0 END), 0) as paid_commission
            
        FROM usuarios u
        LEFT JOIN affiliate_clicks ac ON u.id = ac.affiliate_id
        LEFT JOIN affiliate_attributions aa ON u.id = aa.affiliate_id
        LEFT JOIN transacoes_pix t ON u.id = t.affiliate_id
        LEFT JOIN comissoes c ON u.id = c.afiliado_id
        $whereClause
        AND u.codigo_afiliado IS NOT NULL 
        AND u.codigo_afiliado != ''
        GROUP BY u.id
        ORDER BY total_clicks DESC, total_signups DESC
    ");
    
    $params = array_merge([$dateFrom, $dateTo], $params);
    $stmt->execute($params);
    $affiliateReports = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $affiliateReports = [];
    error_log("Erro ao buscar relatórios: " . $e->getMessage());
}

// Buscar resumo geral
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT ac.id) as total_clicks,
            COUNT(DISTINCT ac.ip_address) as unique_visitors,
            COUNT(DISTINCT aa.user_id) as total_signups,
            COUNT(DISTINCT CASE WHEN t.is_first_deposit = 1 AND t.status = 'aprovado' THEN t.id END) as first_deposits,
            COALESCE(SUM(CASE WHEN t.is_first_deposit = 1 AND t.status = 'aprovado' THEN t.valor ELSE 0 END), 0) as first_deposit_amount,
            COALESCE(SUM(CASE WHEN c.status = 'pendente' THEN c.valor_comissao ELSE 0 END), 0) as pending_commission
        FROM affiliate_clicks ac
        LEFT JOIN affiliate_attributions aa ON ac.affiliate_id = aa.affiliate_id
        LEFT JOIN transacoes_pix t ON aa.user_id = t.usuario_id AND t.is_first_deposit = 1
        LEFT JOIN comissoes c ON ac.affiliate_id = c.afiliado_id
        WHERE DATE(ac.created_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $summary = $stmt->fetch();
} catch (PDOException $e) {
    $summary = [
        'total_clicks' => 0,
        'unique_visitors' => 0,
        'total_signups' => 0,
        'first_deposits' => 0,
        'first_deposit_amount' => 0,
        'pending_commission' => 0
    ];
}

// Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="affiliate_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // BOM para UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Cabeçalhos
    fputcsv($output, [
        'ID', 'Nome', 'Email', 'Código', 'Comissão %', 'Status',
        'Cliques', 'Visitantes Únicos', 'Conversões', 'Taxa Clique-Cadastro %',
        'Cadastros', 'Depósitos', 'Valor Depositado', 'Comissão Total', 'Comissão Pendente'
    ], ';');
    
    // Dados
    foreach ($affiliateReports as $report) {
        $clickConversion = $report['total_clicks'] > 0 ? 
            round(($report['converted_clicks'] / $report['total_clicks']) * 100, 2) : 0;
        
        fputcsv($output, [
            $report['id'],
            $report['nome'],
            $report['email'],
            $report['codigo_afiliado'],
            number_format($report['porcentagem_afiliado'], 2, ',', '.'),
            $report['afiliado_ativo'] ? 'Ativo' : 'Inativo',
            $report['total_clicks'],
            $report['unique_visitors'],
            $report['converted_clicks'],
            $clickConversion,
            $report['total_signups'],
            $report['first_deposits'],
            'R$ ' . number_format($report['first_deposit_amount'], 2, ',', '.'),
            'R$ ' . number_format($report['total_commission'], 2, ',', '.'),
            'R$ ' . number_format($report['pending_commission'], 2, ',', '.')
        ], ';');
    }
    
    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios de Afiliados - Admin</title>
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

        /* Filters */
        .filters {
            background: var(--bg-panel);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 20px;
            display: flex;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-input {
            padding: 8px 12px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-light);
            font-size: 14px;
        }

        .filter-input:focus {
            outline: none;
            border-color: var(--primary-green);
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
            min-width: 1200px;
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

        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                padding: 24px 16px;
            }

            .filters {
                flex-direction: column;
                align-items: stretch;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-chart-line"></i>
                Relatórios de Afiliados
            </h1>
            <div class="page-subtitle">
                Análise detalhada de performance e conversões
            </div>
        </div>

        <!-- Resumo Geral -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= number_format($summary['total_clicks']) ?></div>
                <div class="stat-label">Total de Cliques</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= number_format($summary['unique_visitors']) ?></div>
                <div class="stat-label">Visitantes Únicos</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= number_format($summary['total_signups']) ?></div>
                <div class="stat-label">Cadastros</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= number_format($summary['first_deposits']) ?></div>
                <div class="stat-label">Primeiros Depósitos</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">R$ <?= number_format($summary['first_deposit_amount'], 0, ',', '.') ?></div>
                <div class="stat-label">Volume Depositado</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">R$ <?= number_format($summary['pending_commission'], 2, ',', '.') ?></div>
                <div class="stat-label">Comissão Pendente</div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="filters">
            <form method="GET" style="display: flex; gap: 16px; align-items: center; flex-wrap: wrap;">
                <label style="color: var(--text-light); font-weight: 600;">Período:</label>
                <input type="date" name="date_from" value="<?= $dateFrom ?>" class="filter-input">
                <span style="color: var(--text-muted);">até</span>
                <input type="date" name="date_to" value="<?= $dateTo ?>" class="filter-input">
                
                <input type="text" name="affiliate" placeholder="Código do afiliado" value="<?= htmlspecialchars($affiliateFilter) ?>" class="filter-input">
                <input type="text" name="utm_source" placeholder="UTM Source" value="<?= htmlspecialchars($utmSource) ?>" class="filter-input">
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i>
                    Filtrar
                </button>
                
                <a href="?" class="btn btn-secondary">
                    <i class="fas fa-times"></i>
                    Limpar
                </a>
                
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-secondary">
                    <i class="fas fa-download"></i>
                    Exportar CSV
                </a>
            </form>
        </div>

        <!-- Tabela de Relatórios -->
        <div class="card">
            <h3>
                <i class="fas fa-table"></i>
                Performance por Afiliado
            </h3>
            
            <?php if (empty($affiliateReports)): ?>
                <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                    <i class="fas fa-chart-line" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
                    <p>Nenhum dado encontrado para o período selecionado.</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Afiliado</th>
                                <th>Código</th>
                                <th>Status</th>
                                <th>Cliques</th>
                                <th>Visitantes</th>
                                <th>Conversões</th>
                                <th>Taxa Conv.</th>
                                <th>Cadastros</th>
                                <th>1º Depósitos</th>
                                <th>Volume</th>
                                <th>Comissão</th>
                                <th>Pendente</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($affiliateReports as $report): ?>
                                <?php
                                $clickConversion = $report['total_clicks'] > 0 ? 
                                    round(($report['converted_clicks'] / $report['total_clicks']) * 100, 2) : 0;
                                ?>
                                <tr>
                                    <td style="color: var(--text-light); font-weight: 600;">
                                        <div><?= htmlspecialchars($report['nome']) ?></div>
                                        <div style="font-size: 11px; color: var(--text-muted);">
                                            <?= htmlspecialchars($report['email']) ?>
                                        </div>
                                    </td>
                                    <td style="font-family: monospace; color: var(--primary-gold);">
                                        <?= htmlspecialchars($report['codigo_afiliado']) ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?= $report['afiliado_ativo'] ? 'ativo' : 'inativo' ?>">
                                            <i class="fas fa-<?= $report['afiliado_ativo'] ? 'check-circle' : 'times-circle' ?>"></i>
                                            <?= $report['afiliado_ativo'] ? 'Ativo' : 'Inativo' ?>
                                        </span>
                                    </td>
                                    <td style="color: var(--info-color); font-weight: 700;">
                                        <?= number_format($report['total_clicks']) ?>
                                    </td>
                                    <td style="color: var(--purple-color); font-weight: 700;">
                                        <?= number_format($report['unique_visitors']) ?>
                                    </td>
                                    <td style="color: var(--warning-color); font-weight: 700;">
                                        <?= number_format($report['converted_clicks']) ?>
                                    </td>
                                    <td style="color: var(--success-color); font-weight: 700;">
                                        <?= number_format($clickConversion, 1) ?>%
                                    </td>
                                    <td style="color: var(--primary-green); font-weight: 700;">
                                        <?= number_format($report['total_signups']) ?>
                                    </td>
                                    <td style="color: var(--primary-gold); font-weight: 700;">
                                        <?= number_format($report['first_deposits']) ?>
                                    </td>
                                    <td style="color: var(--success-color); font-weight: 700;">
                                        R$ <?= number_format($report['first_deposit_amount'], 2, ',', '.') ?>
                                    </td>
                                    <td style="color: var(--primary-gold); font-weight: 700;">
                                        R$ <?= number_format($report['total_commission'], 2, ',', '.') ?>
                                    </td>
                                    <td style="color: var(--warning-color); font-weight: 700;">
                                        R$ <?= number_format($report['pending_commission'], 2, ',', '.') ?>
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
        // Auto-submit do formulário quando mudar as datas
        document.querySelectorAll('input[type="date"]').forEach(input => {
            input.addEventListener('change', function() {
                this.form.submit();
            });
        });
    </script>
</body>
</html>