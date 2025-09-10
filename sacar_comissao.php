<?php
session_start();
require 'db.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

$stmt = $pdo->prepare("SELECT comissao FROM usuarios WHERE id = ?");
$stmt->execute([$_SESSION['usuario_id']]);
$usuario = $stmt->fetch();
$comissao = $usuario['comissao'];

$config = $pdo->query("SELECT min_saque_comissao, max_saque_comissao FROM configuracoes LIMIT 1")->fetch();

$min_saque_comissao = floatval($config['min_saque_comissao'] ?? 10);
$max_saque_comissao = floatval($config['max_saque_comissao'] ?? 1000);

?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <title>Saque Comissão</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    body {
      margin: 0;
      font-family: Arial, sans-serif;
      background-color: #0d0d0d;
      color: white;
    }

    .container {
      max-width: 400px;
      margin: 20px auto;
      padding: 20px;
    }

    .title {
      text-align: center;
      font-size: 20px;
      font-weight: bold;
      margin-bottom: 10px;
    }

    .subtitle {
      text-align: center;
      font-size: 17px;
      color: #fff;
      margin-bottom: 5px;
    }

    .input-box {
      background: #151515;
      border: 1px solid #333;
      padding: 5px;
      border-radius: 8px;
      color: white;
      width: 100%;
      font-size: 20px;
      margin-bottom: 10px;
    }

    .quick-buttons {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-bottom: 20px;
    }

    .quick-buttons button {
      flex: 1 1 30%;
      background-color: #1a1a1a;
      border: 1px solid #333;
      color: white;
      padding: 10px;
      font-size: 16px;
      border-radius: 8px;
      cursor: pointer;
      transition: 0.2s;
    }

    .quick-buttons button:hover {
      background-color: #2b2b2b;
    }

    .generate-btn {
      background-color: #059b00;
      color: white;
      border: none;
      padding: 15px;
      font-size: 16px;
      border-radius: 10px;
      width: 100%;
      cursor: pointer;
      font-weight: bold;
    }

    .generate-btn:hover {
      background-color: #059b00;
    }

    .back {
      font-size: 20px;
      cursor: pointer;
      margin-bottom: 10px;
      display: inline-block;
    }

    .tab {
      text-align: center;
      font-size: 14px;
      color: #059b00;
      border-bottom: 2px solid #059b00;
      padding-bottom: 5px;
      margin-bottom: 15px;
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="back" onclick="history.back()">←</div>
    <div class="title">SACAR COMISSÃO</div>
    <div class="tab">PIX</div>

    <form id="formSaqueComissao">
<input 
  type="number" 
  name="valor" 
  id="valor" 
  class="input-box" 
  value="<?= $min_saque_comissao ?>" 
  min="<?= $min_saque_comissao ?>" 
  max="<?= $max_saque_comissao ?>" 
  required
>
<div class="subtitle">Valor mínimo de saque: R$ <?= number_format($min_saque_comissao, 2, ',', '.') ?></div>
<div class="subtitle">Comissão disponível: R$ <?= number_format($comissao, 2, ',', '.') ?></div>



      <div class="quick-buttons">
        <?php foreach ([10, 20, 50, 100, 200, 500] as $v): ?>
          <button type="button" onclick="addValor(<?= $v ?>)">+<?= $v ?></button>
        <?php endforeach; ?>
      </div>

      <input type="text" name="chave_pix" placeholder="Chave Pix (email, CPF ou telefone)" class="input-box" required>

      <button type="submit" class="generate-btn">Solicitar Saque</button>
    </form>

    <div id="mensagem" style="text-align:center; margin-top: 15px;"></div>
  </div>

<script>
function addValor(v) {
  let campo = document.getElementById('valor');
  campo.value = parseInt(campo.value) + v;
}

document.getElementById('formSaqueComissao').addEventListener('submit', function(e) {
  e.preventDefault();

  const formData = new FormData(this);

  fetch('processar_saque_comissao.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    const msgDiv = document.getElementById('mensagem');
    msgDiv.textContent = data.mensagem;
    msgDiv.style.color = data.status === 'sucesso' ? 'lightgreen' : 'red';

    if (data.status === 'sucesso') {
      document.getElementById('valor').value = 10;
      this.chave_pix.value = '';
    }
  })
  .catch(() => {
    const msgDiv = document.getElementById('mensagem');
    msgDiv.textContent = 'Erro ao solicitar saque.';
    msgDiv.style.color = 'red';
  });
});
</script>



</body>
</html>
