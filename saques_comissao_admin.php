<?php
session_start();
require 'db.php';

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
  header('Location: login.php');
  exit;
}

$mensagem = '';

// Processar aprovação/recusa de saques
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    $id = intval($_POST['id']);
    $acao = $_POST['acao'];
    
    if ($acao === 'aprovar') {
        $stmt = $pdo->prepare("UPDATE saques SET status = 'aprovado', data_processamento = NOW() WHERE id = ?");
        if ($stmt->execute([$id])) {
            $mensagem = "Saque de comissão aprovado com sucesso!";
        }
    } elseif ($acao === 'recusar') {
        // Buscar dados do saque para devolver o valor
        $stmt = $pdo->prepare("SELECT * FROM saques WHERE id = ?");
        $stmt->execute([$id]);
        $saque = $stmt->fetch();
        
        if ($saque && $saque['status'] === 'pendente') {
            // Recusar saque
            $stmt = $pdo->prepare("UPDATE saques SET status = 'recusado', data_processamento = NOW() WHERE id = ?");
            $stmt->execute([$id]);
            
            // Devolver valor à comissão do usuário
            $stmt = $pdo->prepare("UPDATE usuarios SET comissao = comissao + ? WHERE id = ?");
            $stmt->execute([$saque['valor'], $saque['usuario_id']]);
            
            $mensagem = "Saque recusado e valor devolvido à comissão do usuário!";
        }
    }
}

// Buscar saques de comissão
$stmt = $pdo->query("
  SELECT s.*, u.email, u.nome, u.codigo_afiliado
  FROM saques s
  JOIN usuarios u ON u.id = s.usuario_id 
  WHERE s.tipo = 'comissao'
  ORDER BY s.data DESC
");

$saques = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$stmt = $pdo->query("
    SELECT 
        COUNT(*) as total_saques,
        COUNT(CASE WHEN status = 'pendente' THEN 1 END) as pendentes,
        COUNT(CASE WHEN status = 'aprovado' THEN 1 END) as aprovados,
        COUNT(CASE WHEN status = 'recusado' THEN 1 END) as recusados,
        COALESCE(SUM(CASE WHEN status = 'aprovado' THEN valor ELSE 0 END), 0) as valor_aprovado,
        COALESCE(SUM(CASE WHEN status = 'pendente' THEN valor ELSE 0 END), 0) as valor_pendente
    FROM saques 
    WHERE tipo = 'comissao'
");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Saques de Comissão - Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
  <style>
    :root {
      --bg-dark: #0d1117;
      --bg-panel: #161b22;
      --primary-gold: #fbce00;
      --text-light: #f0f6fc;
      --text-muted: #8b949e;
      --radius: 12px;
      --transition: 0.3s ease;
      --border-panel: #21262d;
      --shadow-gold: 0 0 20px rgba(251, 206, 0, 0.3);
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: 'Inter', sans-serif;
      background: linear-gradient(135deg, #0d1117 0%, #161b22 100%);
      color: var(--text-light);
      min-height: 100vh;
      padding-top: 80px;
    }

    /* Header */
    .header {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      height: 80px;
      background: rgba(13, 17, 23, 0.95);
      backdrop-filter: blur(10px);
      border-bottom: 2px solid var(--primary-gold);
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 24px;
      z-index: 1000;
      box-shadow: var(--shadow-gold);
    }

    .logo {
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 20px;
      font-weight: 700;
      color: var(--primary-gold);
      text-decoration: none;
      transition: var(--transition);
    }

    .logo:hover {
      transform: scale(1.05);
      text-shadow: 0 0 10px var(--primary-gold);
    }

    .logo i {
      font-size: 24px;
    }

    .nav-menu {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .nav-item {
      padding: 10px 16px;
      background: var(--bg-panel);
      border: 1px solid var(--border-panel);
      border-radius: var(--radius);
      text-decoration: none;
      color: var(--text-light);
      font-weight: 500;
      transition: var(--transition);
      display: flex;
      align-items: center;
      gap: 8px;
      position: relative;
      overflow: hidden;
    }

    .nav-item:hover {
      background: linear-gradient(135deg, var(--primary-gold), #f4c430);
      color: #000;
      transform: translateY(-2px);
      box-shadow: var(--shadow-gold);
    }

    .nav-item.active {
      background: linear-gradient(135deg, var(--primary-gold), #f4c430);
      color: #000;
      box-shadow: var(--shadow-gold);
    }

    .nav-text {
      display: inline;
    }

    .content {
      padding: 40px;
      max-width: 1400px;
      margin: 0 auto;
    }

    h2 {
      font-size: 28px;
      color: var(--primary-gold);
      margin-bottom: 30px;
      display: flex;
      align-items: center;
      gap: 12px;
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
      color: #22c55e;
    }

    .message.error {
      background: rgba(239, 68, 68, 0.15);
      border: 1px solid rgba(239, 68, 68, 0.3);
      color: #ef4444;
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

    /* Cards */
    .card {
      background: var(--bg-panel);
      border: 1px solid var(--border-panel);
      border-radius: var(--radius);
      padding: 24px;
      margin-bottom: 24px;
      transition: var(--transition);
    }

    .card:hover {
      border-color: var(--primary-gold);
      box-shadow: var(--shadow-gold);
    }

    /* Stats Cards */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }

    .stat-card {
      background: var(--bg-panel);
      border: 1px solid var(--border-panel);
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
      background: linear-gradient(90deg, transparent, var(--primary-gold), transparent);
      transition: var(--transition);
    }

    .stat-card:hover {
      border-color: var(--primary-gold);
      transform: translateY(-2px);
      box-shadow: var(--shadow-gold);
    }

    .stat-card:hover::before {
      left: 100%;
    }

    .stat-value {
      font-size: 24px;
      font-weight: 800;
      color: var(--primary-gold);
      margin-bottom: 4px;
    }

    .stat-label {
      font-size: 12px;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    /* Tables */
    table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0 8px;
      background: transparent;
    }

    thead tr th {
      padding: 14px;
      color: var(--primary-gold);
      font-weight: 700;
      text-align: left;
      background: var(--bg-panel);
      border: 1px solid var(--border-panel);
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
      background: rgba(251, 206, 0, 0.05);
      transform: translateY(-1px);
    }

    tbody tr td {
      padding: 14px;
      color: var(--text-light);
      vertical-align: middle;
      border: 1px solid var(--border-panel);
      border-top: none;
      font-size: 13px;
    }

    tbody tr td:first-child {
      border-radius: var(--radius) 0 0 var(--radius);
      border-left: 1px solid var(--border-panel);
    }

    tbody tr td:last-child {
      border-radius: 0 var(--radius) var(--radius) 0;
      border-right: 1px solid var(--border-panel);
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
      background: linear-gradient(135deg, var(--primary-gold), #f4c430);
      color: #000;
      box-shadow: 0 2px 8px rgba(251, 206, 0, 0.3);
    }

    .btn-primary:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(251, 206, 0, 0.4);
    }

    .btn-success {
      background: linear-gradient(135deg, #22c55e, #16a34a);
      color: white;
    }

    .btn-danger {
      background: linear-gradient(135deg, #ef4444, #dc2626);
      color: white;
    }

    /* Status badges */
    .status-aprovado {
      background: rgba(34, 197, 94, 0.15);
      color: #22c55e;
      padding: 4px 8px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }

    .status-pendente {
      background: rgba(251, 206, 0, 0.15);
      color: var(--primary-gold);
      padding: 4px 8px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }

    .status-recusado {
      background: rgba(239, 68, 68, 0.15);
      color: #ef4444;
      padding: 4px 8px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }

    .codigo-afiliado {
      background: var(--bg-dark);
      padding: 2px 6px;
      border-radius: 4px;
      font-family: monospace;
      font-weight: 700;
      color: var(--primary-gold);
      border: 1px solid var(--border-panel);
      font-size: 11px;
    }

    /* Responsive */
    @media (max-width: 768px) {
      .header {
        padding: 0 16px;
      }

      .nav-text {
        display: none;
      }

      .nav-item {
        padding: 10px;
      }

      .content {
        padding: 20px;
      }

      .stats-grid {
        grid-template-columns: repeat(2, 1fr);
      }

      table {
        font-size: 11px;
      }

      .btn {
        padding: 6px 10px;
        font-size: 11px;
      }
    }
  </style>
</head>
<body>
  <!-- Header -->
  <div class="header">
    <a href="painel_admin.php" class="logo">
      <i class="fas fa-crown"></i>
      <span>Admin Panel</span>
    </a>
    
    <nav class="nav-menu">
      <a href="painel_admin.php" class="nav-item">
        <i class="fas fa-tachometer-alt"></i>
        <span class="nav-text">Dashboard</span>
      </a>
      <a href="configuracoes_admin.php" class="nav-item">
        <i class="fas fa-cog"></i>
        <span class="nav-text">Configurações</span>
      </a>
      <a href="admin_usuarios.php" class="nav-item">
        <i class="fas fa-users"></i>
        <span class="nav-text">Usuários</span>
      </a>
      <a href="premios_admin.php" class="nav-item">
        <i class="fas fa-gift"></i>
        <span class="nav-text">Prêmios</span>
      </a>
      <a href="admin_saques.php" class="nav-item">
        <i class="fas fa-money-bill-wave"></i>
        <span class="nav-text">Saques</span>
      </a>
      <a href="saques_comissao_admin.php" class="nav-item active">
        <i class="fas fa-percentage"></i>
        <span class="nav-text">Comissões</span>
      </a>
      <a href="gateways_admin.php" class="nav-item">
        <i class="fas fa-credit-card"></i>
        <span class="nav-text">Gateways</span>
      </a>
      <a href="pix_admin.php" class="nav-item">
        <i class="fas fa-exchange-alt"></i>
        <span class="nav-text">Transações</span>
      </a>
      <a href="admin_afiliados.php" class="nav-item">
        <i class="fas fa-handshake"></i>
        <span class="nav-text">Afiliados</span>
      </a>
    </nav>
  </div>

  <div class="content">
    <h2>
      <i class="fas fa-percentage"></i>
      Saques de Comissão
    </h2>

    <?php if ($mensagem): ?>
      <div class="message success">
        <i class="fas fa-check-circle"></i>
        <?= htmlspecialchars($mensagem) ?>
      </div>
    <?php endif; ?>

    <!-- Estatísticas -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-value"><?= $stats['total_saques'] ?></div>
        <div class="stat-label">Total de Saques</div>
      </div>
      
      <div class="stat-card">
        <div class="stat-value" style="color: #f59e0b;"><?= $stats['pendentes'] ?></div>
        <div class="stat-label">Pendentes</div>
      </div>
      
      <div class="stat-card">
        <div class="stat-value" style="color: #22c55e;"><?= $stats['aprovados'] ?></div>
        <div class="stat-label">Aprovados</div>
      </div>
      
      <div class="stat-card">
        <div class="stat-value" style="color: #ef4444;"><?= $stats['recusados'] ?></div>
        <div class="stat-label">Recusados</div>
      </div>
      
      <div class="stat-card">
        <div class="stat-value">R$ <?= number_format($stats['valor_aprovado'], 2, ',', '.') ?></div>
        <div class="stat-label">Valor Aprovado</div>
      </div>
      
      <div class="stat-card">
        <div class="stat-value">R$ <?= number_format($stats['valor_pendente'], 2, ',', '.') ?></div>
        <div class="stat-label">Valor Pendente</div>
      </div>
    </div>

    <!-- Tabela de Saques -->
    <div class="card">
      <h3 style="margin-bottom: 20px; color: var(--primary-gold); display: flex; align-items: center; gap: 8px;">
        <i class="fas fa-table"></i> Lista de Saques de Comissão
      </h3>
      
      <?php if (empty($saques)): ?>
        <div style="text-align: center; padding: 40px; color: var(--text-muted);">
          <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
          <p>Nenhum saque de comissão registrado.</p>
        </div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Afiliado</th>
              <th>Código</th>
              <th>Valor</th>
              <th>Chave Pix</th>
              <th>Data</th>
              <th>Status</th>
              <th>Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($saques as $saque): ?>
              <tr>
                <td><?= $saque['id'] ?></td>
                <td>
                  <div style="color: var(--text-light); font-weight: 600;">
                    <?= htmlspecialchars($saque['nome'] ?: 'Usuário') ?>
                  </div>
                  <div style="font-size: 11px; color: var(--text-muted);">
                    <?= htmlspecialchars($saque['email']) ?>
                  </div>
                </td>
                <td>
                  <?php if (!empty($saque['codigo_afiliado'])): ?>
                    <span class="codigo-afiliado">
                      <?= htmlspecialchars($saque['codigo_afiliado']) ?>
                    </span>
                  <?php else: ?>
                    <span style="color: var(--text-muted);">N/A</span>
                  <?php endif; ?>
                </td>
                <td style="color: var(--primary-gold); font-weight: 700;">
                  R$ <?= number_format($saque['valor'], 2, ',', '.') ?>
                </td>
                <td>
                  <div style="color: var(--text-light);">
                    <?= htmlspecialchars($saque['chave_pix']) ?>
                  </div>
                  <?php if ($saque['tipo_chave']): ?>
                    <div style="font-size: 11px; color: var(--text-muted);">
                      Tipo: <?= ucfirst($saque['tipo_chave']) ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td><?= date('d/m/Y H:i', strtotime($saque['data'])) ?></td>
                <td>
                  <?php
                    $status = $saque['status'];
                    $icon = $status === 'aprovado' ? 'check-circle' : ($status === 'pendente' ? 'clock' : 'times-circle');
                  ?>
                  <span class="status-<?= $status ?>">
                    <i class="fas fa-<?= $icon ?>"></i>
                    <?= ucfirst($status) ?>
                  </span>
                </td>
                <td>
                  <?php if ($saque['status'] === 'pendente'): ?>
                    <form action="" method="POST" style="display:inline;">
                      <input type="hidden" name="id" value="<?= $saque['id'] ?>">
                      <button type="submit" name="acao" value="aprovar" class="btn btn-success" onclick="return confirm('Deseja aprovar este saque de comissão?')">
                        <i class="fas fa-check"></i>
                        Aprovar
                      </button>
                    </form>
                    <form action="" method="POST" style="display:inline;">
                      <input type="hidden" name="id" value="<?= $saque['id'] ?>">
                      <button type="submit" name="acao" value="recusar" class="btn btn-danger" onclick="return confirm('Deseja recusar este saque? O valor será devolvido à comissão do usuário.')">
                        <i class="fas fa-times"></i>
                        Recusar
                      </button>
                    </form>
                  <?php else: ?>
                    <span style="color: var(--text-muted); font-style: italic;">
                      <?= ucfirst($saque['status']) ?>
                    </span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>