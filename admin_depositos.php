<?php
session_start();

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    header('Location: login.php');
    exit;
}

require 'db.php';
require 'processar_comissao.php';

// Criar tabela de depósitos se não existir
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS depositos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        valor DECIMAL(10,2) NOT NULL,
        metodo VARCHAR(50) DEFAULT 'PIX',
        status ENUM('pendente', 'aprovado', 'rejeitado') DEFAULT 'pendente',
        comprovante TEXT,
        data_solicitacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        data_processamento TIMESTAMP NULL,
        observacoes TEXT,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
    )");
} catch (PDOException $e) {
    // Tabela já existe ou erro na criação
}

// Buscar depósitos
$status_filter = $_GET['status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$where = '';
$params = [];

if ($status_filter) {
    $where = "WHERE d.status = ?";
    $params = [$status_filter];
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM depositos d $where");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("
        SELECT d.*, u.nome, u.email 
        FROM depositos d 
        LEFT JOIN usuarios u ON d.usuario_id = u.id 
        $where 
        ORDER BY d.data_solicitacao DESC 
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $depositos = $stmt->fetchAll();
} catch (PDOException $e) {
    $depositos = [];
    $total = 0;
}

$total_pages = ceil($total / $limit);

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $deposito_id = intval($_POST['deposito_id'] ?? 0);
    
    try {
        switch ($action) {
            case 'aprovar':
                // Buscar dados do depósito
                $stmt = $pdo->prepare("SELECT * FROM depositos WHERE id = ?");
                $stmt->execute([$deposito_id]);
                $deposito = $stmt->fetch();
                
                if ($deposito && $deposito['status'] === 'pendente') {
                    // Aprovar depósito
                    $stmt = $pdo->prepare("UPDATE depositos SET status = 'aprovado', data_processamento = NOW() WHERE id = ?");
                    $stmt->execute([$deposito_id]);
                    
                    // Adicionar saldo ao usuário
                    $stmt = $pdo->prepare("UPDATE usuarios SET saldo = saldo + ? WHERE id = ?");
                    $stmt->execute([$deposito['valor'], $deposito['usuario_id']]);
                    
                    // Processar comissão de afiliado
                    processarComissaoAfiliado($deposito['usuario_id'], $deposito['valor'], $pdo);
                    
                    $_SESSION['success'] = 'Depósito aprovado e saldo creditado!';
                }
                break;
                
            case 'rejeitar':
                $observacoes = $_POST['observacoes'] ?? '';
                $stmt = $pdo->prepare("UPDATE depositos SET status = 'rejeitado', data_processamento = NOW(), observacoes = ? WHERE id = ?");
                $stmt->execute([$observacoes, $deposito_id]);
                $_SESSION['success'] = 'Depósito rejeitado!';
                break;
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Erro ao processar ação: ' . $e->getMessage();
    }
    
    header('Location: admin_depositos.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Depósitos - Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --bg-dark: #0a0b0f;
            --bg-panel: #111318;
            --bg-card: #1a1d24;
            --primary-gold: #fbce00;
            --primary-green: #00d4aa;
            --text-light: #ffffff;
            --text-muted: #8b949e;
            --border-color: #21262d;
            --success-color: #22c55e;
            --error-color: #ef4444;
            --warning-color: #f59e0b;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-dark);
            color: var(--text-light);
            min-height: 100vh;
        }

        .header {
            background: var(--bg-panel);
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            background: linear-gradient(135deg, var(--primary-gold), var(--primary-green));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .btn-primary { background: var(--primary-green); color: #000; }
        .btn-primary { 
            background: linear-gradient(135deg, var(--primary-gold), var(--primary-green)); 
            color: #000; 
            box-shadow: 0 4px 16px rgba(251, 206, 0, 0.3);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(251, 206, 0, 0.4);
        }
        .btn-secondary { background: var(--bg-card); color: var(--text-light); border: 1px solid var(--border-color); }
        .btn-success { background: var(--success-color); color: white; }
        .btn-danger { background: var(--error-color); color: white; }
        .btn-warning { background: var(--warning-color); color: white; }
        .btn-sm { padding: 4px 8px; font-size: 12px; }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: var(--bg-panel);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            border: 1px solid var(--border-color);
        }

        .stat-value {
            font-size: 24px;
            font-weight: 800;
            color: var(--primary-green);
            margin-bottom: 8px;
        }

        .stat-label {
            color: var(--text-muted);
            font-size: 14px;
        }

        .filters {
            background: var(--bg-panel);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
        }

        .select-input {
            padding: 12px 16px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-light);
        }

        .table-container {
            background: var(--bg-panel);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .table th {
            background: var(--bg-card);
            font-weight: 600;
            color: var(--text-light);
        }

        .table td {
            color: var(--text-muted);
        }

        .table tr:hover {
            background: rgba(0, 212, 170, 0.05);
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-pendente { background: var(--warning-color); color: white; }
        .status-aprovado { background: var(--success-color); color: white; }
        .status-rejeitado { background: var(--error-color); color: white; }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
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

        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal.show { display: flex; }

        .modal-content {
            background: var(--bg-panel);
            padding: 24px;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
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
            border-radius: 8px;
            color: var(--text-light);
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>
            <i class="fas fa-arrow-down"></i>
            Gerenciar Depósitos
        </h1>
        <a href="configuracoes_admin.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i>
            Voltar ao Painel
        </a>
    </div>

    <div class="container">
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

        <div class="stats-grid">
            <?php
            try {
                $pendentes = $pdo->query("SELECT COUNT(*) FROM depositos WHERE status = 'pendente'")->fetchColumn();
                $aprovados = $pdo->query("SELECT COUNT(*) FROM depositos WHERE status = 'aprovado'")->fetchColumn();
                $rejeitados = $pdo->query("SELECT COUNT(*) FROM depositos WHERE status = 'rejeitado'")->fetchColumn();
                $total_valor = $pdo->query("SELECT SUM(valor) FROM depositos WHERE status = 'aprovado'")->fetchColumn() ?: 0;
            } catch (PDOException $e) {
                $pendentes = $aprovados = $rejeitados = $total_valor = 0;
            }
            ?>
            <div class="stat-card">
                <div class="stat-value"><?= $pendentes ?></div>
                <div class="stat-label">Pendentes</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $aprovados ?></div>
                <div class="stat-label">Aprovados</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $rejeitados ?></div>
                <div class="stat-label">Rejeitados</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">R$ <?= number_format($total_valor, 2, ',', '.') ?></div>
                <div class="stat-label">Total Aprovado</div>
            </div>
        </div>

        <div class="filters">
            <form method="GET" style="display: flex; gap: 16px; align-items: center;">
                <select name="status" class="select-input">
                    <option value="">Todos os Status</option>
                    <option value="pendente" <?= $status_filter === 'pendente' ? 'selected' : '' ?>>Pendentes</option>
                    <option value="aprovado" <?= $status_filter === 'aprovado' ? 'selected' : '' ?>>Aprovados</option>
                    <option value="rejeitado" <?= $status_filter === 'rejeitado' ? 'selected' : '' ?>>Rejeitados</option>
                </select>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i>
                    Filtrar
                </button>
            </form>
        </div>

        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Usuário</th>
                        <th>Valor</th>
                        <th>Método</th>
                        <th>Status</th>
                        <th>Data</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($depositos as $deposito): ?>
                        <tr>
                            <td><?= $deposito['id'] ?></td>
                            <td style="color: var(--text-light); font-weight: 600;">
                                <?= htmlspecialchars($deposito['nome'] ?? 'Usuário #' . $deposito['usuario_id']) ?>
                            </td>
                            <td style="color: var(--primary-green); font-weight: 700;">
                                R$ <?= number_format($deposito['valor'], 2, ',', '.') ?>
                            </td>
                            <td><?= $deposito['metodo'] ?></td>
                            <td>
                                <span class="status-badge status-<?= $deposito['status'] ?>">
                                    <?= ucfirst($deposito['status']) ?>
                                </span>
                            </td>
                            <td><?= date('d/m/Y H:i', strtotime($deposito['data_solicitacao'])) ?></td>
                            <td>
                                <?php if ($deposito['status'] === 'pendente'): ?>
                                    <div style="display: flex; gap: 4px;">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="aprovar">
                                            <input type="hidden" name="deposito_id" value="<?= $deposito['id'] ?>">
                                            <button type="submit" 
                                                    class="btn btn-success btn-sm" 
                                                    onclick="return confirm('Aprovar depósito?')">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </form>
                                        
                                        <button type="button" 
                                                class="btn btn-danger btn-sm" 
                                                onclick="rejectDeposit(<?= $deposito['id'] ?>)">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <span style="color: var(--text-muted); font-size: 12px;">
                                        <?= $deposito['status'] === 'aprovado' ? 'Processado' : 'Rejeitado' ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal Rejeitar Depósito -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <h3 style="margin-bottom: 20px; color: var(--text-light);">
                <i class="fas fa-times"></i>
                Rejeitar Depósito
            </h3>
            <form method="POST">
                <input type="hidden" name="action" value="rejeitar">
                <input type="hidden" name="deposito_id" id="rejectDepositId">
                
                <div class="form-group">
                    <label class="form-label">Motivo da Rejeição</label>
                    <textarea name="observacoes" 
                              class="form-input" 
                              rows="4" 
                              placeholder="Descreva o motivo da rejeição..."
                              required></textarea>
                </div>
                
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeRejectModal()">
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-times"></i>
                        Rejeitar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function rejectDeposit(depositId) {
            document.getElementById('rejectDepositId').value = depositId;
            document.getElementById('rejectModal').classList.add('show');
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').classList.remove('show');
        }

        // Fechar modal ao clicar fora
        document.getElementById('rejectModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeRejectModal();
            }
        });
    </script>
</body>
</html>