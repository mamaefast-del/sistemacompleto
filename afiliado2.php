<?php
session_start();
require 'db.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT nome, saldo, codigo_convite, comissao FROM usuarios WHERE id = ?");
$stmt->execute([$_SESSION['usuario_id']]);

$usuario = $stmt->fetch();

$codigo_convite = $usuario['codigo_convite'];
$comissao = $usuario['comissao'];
$stmt = $pdo->query("SELECT valor_comissao FROM configuracoes LIMIT 1");
$config = $stmt->fetch();
$percentual_comissao = intval($config['valor_comissao']);



if (empty($percentual_comissao) || $percentual_comissao == 0) {
    $stmt = $pdo->query("SELECT valor_comissao FROM configuracoes LIMIT 1");
    $config = $stmt->fetch();
    $percentual_comissao = $config['valor_comissao'];
}

$link_convite = "https://linkplataforma.online/?ref=" . $codigo_convite;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE indicado_por = ?");
$stmt->execute([$_SESSION['usuario_id']]);
$total_indicados = $stmt->fetchColumn();


$stmt = $pdo->prepare("
  SELECT 
    u.id, u.nome, u.telefone,
    COALESCE(SUM(tp.valor), 0) AS total_depositado,
    MIN(tp.criado_em) AS data_primeiro_deposito
  FROM usuarios u
  LEFT JOIN transacoes_pix tp 
    ON tp.usuario_id = u.id AND tp.status = 'aprovado'
  WHERE u.indicado_por = ?
  GROUP BY u.id
");
$stmt->execute([$_SESSION['usuario_id']]);
$indicados = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
  SELECT c.valor, c.criado_em, u.nome as nome_indicado 
  FROM comissoes c
  JOIN usuarios u ON c.indicado_id = u.id
  WHERE c.usuario_id = ?
  ORDER BY c.criado_em DESC
");
$stmt->execute([$_SESSION['usuario_id']]);
$historicoComissoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel=stylesheet href=./css/mA1sVw38.css>
</head>
<body>
<meta name="format-detection" content="telephone=no,email=no,address=no">
<div class="tabs">
  <div class="tab active" onclick="openTab('convite')">Link de Convite</div>
  <div class="tab" onclick="openTab('dados')">Meus Dados</div>
</div>

<div id="convite" class="tab-content active">
<div class="box"> 
  <p class="texto-disponivel">
  Saldo Disponível R$<span class="highlight2"><?= number_format($comissao, 2, ',', '.') ?></span>
</p>
<p class="texto-disponivel">
  Comissão sobre os depósitos: <span class="highlight2"><?= intval($percentual_comissao) ?>%</span>
</p>
  <button class="btn2" onclick="window.location.href='sacar_comissao.php'">Realizar Saque</button>
</div>
  <div class="box">
    <p><strong>Link de Afiliado</strong></p>
    <div style="display: flex; gap: 10px; align-items: center;">
  <input id="linkAfiliado" type="text" value="https://linkplataforma.online/index/?code=<?= htmlspecialchars($codigo_convite) ?>" readonly style="flex: 1; padding: 10px; background: #000; color: white; border-radius: 5px; border: none;">
  <button class="btn" onclick="copiarLink()">Copiar</button>
</div>
    <p style="margin-top: 10px;">Indicados Totais: <span class="highlight"><?= $total_indicados ?> Pessoa(s)</span></p>
    <p>Código de Convite: <strong><?= htmlspecialchars($codigo_convite) ?></strong></p>
  </div>
</div>
<div id="dados" class="tab-content">
  <?php if (!empty($indicados)): ?>
    <h3 style="margin-bottom: 10px; text-align: center;">Indicados</h3>
    <div class="box" style="width: 100%;">
      <?php foreach ($indicados as $ind): ?>
        <div style="padding: 10px; border-bottom: 1px solid #333; width: 100%;">
          <strong><?= htmlspecialchars($ind['nome']) ?></strong> 
          <?= htmlspecialchars($ind['telefone']) ?><br>
          <span style="color: #ccc;">Depósitos: </span>
          <span class="highlight">R$ <?= number_format($ind['total_depositado'], 2, ',', '.') ?></span><br>
          <span style="color: #ccc;">Data primeiro depósito: </span>
          <span style="color: #00e676;">
            <?= $ind['data_primeiro_deposito'] ? date('d/m/Y H:i', strtotime($ind['data_primeiro_deposito'])) : 'Não realizado' ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="box">
      <p>Nenhum indicado com depósito ainda.</p>
    </div>
  <?php endif; ?>
</div>
<div style="height: 90px;"></div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<div class="footer">
  <a href="index" >
    <div><i class="fas fa-house"></i><br></div>
  </a>
  <a href="menu">
    <div><i class="fas fa-box"></i><br></div>
  </a>
  <a href="deposito" class="deposito-btn">
    <div><i class="fas fa-credit-card"></i><br></div>
  </a>
  <a href="afiliado" class="active">
    <div><i class="fas fa-user-plus"></i><br></div>
  </a>
  <a href="perfil">
    <div><i class="fas fa-user-group"></i><br></div>
  </a>
</div>

<script>
  function openTab(tabId) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));

    document.querySelector(`.tab[onclick="openTab('${tabId}')"]`).classList.add('active');
    document.getElementById(tabId).classList.add('active');
  }
  function copiarLink() {
  const input = document.getElementById("linkAfiliado");
  input.select();
  input.setSelectionRange(0, 99999);
  document.execCommand("copy");
  alert("Link copiado!");
}
</script>
</body>
</html>
