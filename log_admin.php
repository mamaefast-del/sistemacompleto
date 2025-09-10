<?php
session_start();
require 'db.php';

define('MAX_TENTATIVAS', 5);
define('BLOQUEIO_MINUTOS', 5);

if (isset($_SESSION['tentativas']) && $_SESSION['tentativas'] >= MAX_TENTATIVAS) {
    $ultimaTentativa = $_SESSION['ultima_tentativa'] ?? time();
    $tempoRestante = ($ultimaTentativa + BLOQUEIO_MINUTOS * 60) - time();

    if ($tempoRestante > 0) {
        $erro = "Muitas tentativas falhas. Tente novamente em " . ceil($tempoRestante / 60) . " minuto(s).";
    } else {
        $_SESSION['tentativas'] = 0;
        unset($_SESSION['ultima_tentativa']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($erro)) {
    $email = trim($_POST['email']);
    $senha = $_POST['senha'];

    $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = ?");
    $stmt->execute([$email]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($senha, $admin['senha'])) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['admin_id'] = $admin['id'];

        unset($_SESSION['tentativas'], $_SESSION['ultima_tentativa']);

        header("Location: painel_admin.php");
        exit;
    } else {
        $_SESSION['tentativas'] = ($_SESSION['tentativas'] ?? 0) + 1;
        $_SESSION['ultima_tentativa'] = time();

        $erro = "Credenciais inválidas.";
    }
}
?>


<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Login Admin</title>
 <style>
  /* Reset básico */
  * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
  }

  body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    display: flex;
    justify-content: center;
    align-items: center;
    height: 100vh;
    background: #121212; /* fundo escuro */
    color: #fff;
  }

  form {
    background: #1f1f1f; /* cinza escuro */
    padding: 30px 25px;
    border-radius: 12px;
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.5);
    width: 100%;
    max-width: 400px;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
  }

  form:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 25px rgba(0, 0, 0, 0.7);
  }

  h2 {
    text-align: center;
    margin-bottom: 25px;
    color: #00ff7f; /* verde neon */
    font-size: 24px;
    letter-spacing: 1px;
  }

  input {
    width: 100%;
    padding: 12px 15px;
    margin-bottom: 15px;
    border-radius: 6px;
    border: 1px solid #333;
    background: #2a2a2a;
    color: #fff;
    font-size: 14px;
    transition: border 0.3s ease, background 0.3s ease;
  }

  input:focus {
    border-color: #00ff7f;
    background: #333;
    outline: none;
  }

  button {
    width: 100%;
    padding: 12px;
    background: linear-gradient(90deg, #00ff7f, #00cc66);
    border: none;
    border-radius: 6px;
    color: #121212;
    font-weight: bold;
    cursor: pointer;
    transition: background 0.3s ease, transform 0.2s ease;
  }

  button:hover {
    background: linear-gradient(90deg, #00cc66, #00ff7f);
    transform: translateY(-2px);
  }

  .erro {
    color: #ff4c4c;
    margin-bottom: 15px;
    text-align: center;
    font-weight: bold;
  }

  /* Responsivo */
  @media (max-width: 480px) {
    form {
      padding: 25px 20px;
    }

    h2 {
      font-size: 20px;
    }

    input, button {
      padding: 10px;
      font-size: 14px;
    }
  }
</style>
</head>
<body>
  <form method="POST">
    <h2>Login Admin</h2>
    <?php if (isset($erro)): ?>
      <div class="erro"><?= $erro ?></div>
    <?php endif; ?>
    <input type="email" name="email" placeholder="E-mail" required>
    <input type="password" name="senha" placeholder="Senha" required>
    <button type="submit">Entrar</button>
  </form>
</body>
</html>
