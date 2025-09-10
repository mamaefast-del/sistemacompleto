<?php
session_start();
require 'db.php';

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    header('Location: login.php');
    exit;
}

$id = intval($_POST['id'] ?? 0);
$acao = $_POST['acao'] ?? '';

if ($id <= 0 || !in_array($acao, ['aprovar', 'recusar'])) {
    exit("Requisição inválida.");
}

// Buscar saque
$stmt = $pdo->prepare("SELECT * FROM saques WHERE id = ?");
$stmt->execute([$id]);
$saque = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$saque || $saque['status'] !== 'pendente') {
    exit("Saque inválido ou já processado.");
}

// Função de log
function logDetalhado($titulo, $conteudo) {
    $hora = date('[Y-m-d H:i:s]');
    $texto = "$hora $titulo\n";
    if (is_array($conteudo) || is_object($conteudo)) {
        $texto .= json_encode($conteudo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    } else {
        $texto .= $conteudo . "\n\n";
    }
    file_put_contents("log_saque.txt", $texto, FILE_APPEND);
}

if ($acao === 'aprovar') {
    // Buscar gateway EXPFY Pay ativo
    $stmt = $pdo->prepare("SELECT client_id, client_secret, callback_url FROM gateways WHERE nome='expfypay' AND ativo=1 LIMIT 1");
    $stmt->execute();
    $gateway = $stmt->fetch();

    if (!$gateway) {
        echo "Gateway EXPFY Pay não configurado.";
        exit;
    }

    $publicKey = $gateway['client_id'];
    $secretKey = $gateway['client_secret'];
    $callbackUrl = str_replace('/webhook-pix.php', '/webhook_saque.php', $gateway['callback_url']);

    // Detectar tipo de chave automaticamente
    $tipo_chave = 'EMAIL'; // padrão
    if (filter_var($saque['chave_pix'], FILTER_VALIDATE_EMAIL)) {
        $tipo_chave = 'EMAIL';
    } elseif (preg_match('/^\d{11}$/', preg_replace('/\D/', '', $saque['chave_pix']))) {
        $tipo_chave = 'CPF';
    } elseif (preg_match('/^\d{14}$/', preg_replace('/\D/', '', $saque['chave_pix']))) {
        $tipo_chave = 'CNPJ';
    } elseif (preg_match('/^\d{10,11}$/', preg_replace('/\D/', '', $saque['chave_pix']))) {
        $tipo_chave = 'PHONE';
    }

    $external_id = md5(uniqid("saque_admin_{$id}_", true));

    // Monta payload de saque
    $payload = [
        'amount' => floatval($saque['valor']),
        'pix_key' => $saque['chave_pix'],
        'pix_key_type' => $tipo_chave,
        'external_id' => $external_id,
        'description' => "Saque aprovado",
        'callback_url' => $callbackUrl
    ];

    logDetalhado("Payload EXPFY Pay", json_encode($payload, JSON_PRETTY_PRINT));

    // Enviar para EXPFY Pay
    $ch = curl_init('https://pro.expfypay.com/api/v1/withdrawls');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'X-Public-Key: ' . $publicKey,
            'X-Secret-Key: ' . $secretKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    logDetalhado("Resposta API EXPFY Pay Saque (HTTP $httpCode)", $response);

    if ($error) {
        logDetalhado("Erro cURL", $error);
    }

    $res = json_decode($response, true);

    if ($httpCode === 200 || $httpCode === 201) {
        // Atualiza o saque como aprovado
        $stmt = $pdo->prepare("UPDATE saques SET status = 'aprovado', external_id = ?, aprovado_em = NOW() WHERE id = ?");
        $stmt->execute([$external_id, $id]);

        // Registra no log de admin
        $stmt = $pdo->prepare("INSERT INTO log_admin (acao, detalhes, data) VALUES (?, ?, NOW())");
        $stmt->execute(['Aprovar Saque', "Saque ID: $id aprovado via EXPFY Pay."]);

        header('Content-Type: text/plain');
        echo "sucesso";
        exit;
    } else {
        // Falha: devolve saldo
        $pdo->prepare("UPDATE usuarios SET saldo = saldo + ? WHERE id = ?")->execute([$saque['valor'], $saque['usuario_id']]);
        $stmt = $pdo->prepare("UPDATE saques SET status = 'erro_api', aprovado_em = NOW() WHERE id = ?");
        $stmt->execute([$id]);
        exit("Erro ao enviar saque: " . ($res['message'] ?? 'Erro desconhecido'));
    }
}

if ($acao === 'recusar') {
    // Devolve o saldo
    $pdo->prepare("UPDATE usuarios SET saldo = saldo + ? WHERE id = ?")->execute([$saque['valor'], $saque['usuario_id']]);
    
    // Atualiza status para recusado
    $stmt = $pdo->prepare("UPDATE saques SET status = 'recusado', recusado_em = NOW() WHERE id = ?");
    $stmt->execute([$id]);

    // Registra no log de admin
    $stmt = $pdo->prepare("INSERT INTO log_admin (acao, detalhes, data) VALUES (?, ?, NOW())");
    $stmt->execute(['Recusar Saque', "Saque ID: $id recusado. Valor devolvido: R$ {$saque['valor']}"]);

    header('Content-Type: text/plain');
    echo "sucesso";
    exit;
}
?>
