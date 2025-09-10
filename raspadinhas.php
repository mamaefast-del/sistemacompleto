<?php
ob_start();
session_start();
require 'db.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

$idRaspadinha = isset($_GET['id']) ? intval($_GET['id']) : 1;

// Buscar dados da raspadinha
$stmt = $pdo->prepare("SELECT * FROM raspadinhas_config WHERE id = ? AND ativa = 1");
$stmt->execute([$idRaspadinha]);
$raspadinha = $stmt->fetch();

if (!$raspadinha) {
    echo "Raspadinha inválida!";
    exit;
}

// Buscar dados do usuário
$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$_SESSION['usuario_id']]);
$usuario = $stmt->fetch();

// Valor e prêmios da raspadinha
$valor = floatval($raspadinha['valor']);
$premios = json_decode($raspadinha['premios_json'], true);
$custo = $valor;

// Usa o percentual individual, ou o padrão da raspadinha
$chanceGanho = isset($usuario['percentual_ganho']) && $usuario['percentual_ganho'] !== null
    ? floatval($usuario['percentual_ganho'])
    : floatval($raspadinha['chance_ganho']);

$moeda = $raspadinha['moeda'] ?? 'R$';

$saldo = $usuario['saldo'];

// Usa o percentual individual do usuário ou o da raspadinha
$chanceGanho = isset($usuario['percentual_ganho']) && $usuario['percentual_ganho'] !== null
    ? floatval($usuario['percentual_ganho'])
    : floatval($raspadinha['chance_ganho']);

// Moeda da raspadinha (ou padrão 'R$' se não tiver)
$moeda = $raspadinha['moeda'] ?? 'R$';

// Prêmios da raspadinha (campo premios_json)
$premios_json = $raspadinha['premios_json'] ?? '{"R$0": 100}';
$distribuicao_json = json_decode($premios_json, true);

// Converter distribuição para formato numérico
$distribuicao = [];
foreach ($distribuicao_json as $rotulo => $chance) {
$chance = floatval($chance);
if ($chance > 0) {
    $valorNumerico = floatval(str_replace(['R$', ','], ['', '.'], $rotulo));
    $distribuicao[$valorNumerico] = $chance;
}
}


// Função para sortear prêmio baseado na distribuição
function sortearPremioDistribuido($distribuicao) {
    $total = array_sum($distribuicao);
    if ($total <= 0) return 0.00;

    $rand = mt_rand(1, $total);
    $acumulado = 0;

    foreach ($distribuicao as $valor => $chance) {
        $acumulado += $chance;
        if ($rand <= $acumulado) {
            return $valor;
        }
    }
    return 0.00;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($saldo < $custo) {
        $_SESSION['erro_raspadinha'] = "Saldo insuficiente para jogar esta raspadinha.";
        header("Location: raspadinhas.php?id=$idRaspadinha");
        exit;
    }


    $itens = [];
    $ganhou = false;
    $premio = 0.00;

    // Verifica chance de ganhar algo
    if (mt_rand(1, 100) <= $chanceGanho) {
        $ganhou = true;
        $premio = sortearPremioDistribuido($distribuicao);
        $premio_formatado = "R$" . number_format($premio, 2, ',', '');

        // Gera 3 prêmios iguais e o resto diferente
        $posicoesPremio = array_rand(range(0, 8), 3);
        if (!is_array($posicoesPremio)) $posicoesPremio = [$posicoesPremio];

$valores_usados = []; // controle de repetição dos outros prêmios

for ($i = 0; $i < 9; $i++) {
    if (in_array($i, $posicoesPremio)) {
        $itens[$i] = $premio_formatado;
    } else {
        $outros = array_diff(array_keys($distribuicao), [$premio]);

        // Sorteia até encontrar um que ainda não tenha aparecido 2 vezes
        do {
            $valor_outro = $outros[array_rand($outros)] ?? 0.00;
            $qtd_repetida = isset($valores_usados[$valor_outro]) ? $valores_usados[$valor_outro] : 0;
        } while ($qtd_repetida >= 2);

        $valores_usados[$valor_outro] = $qtd_repetida + 1;
        $itens[$i] = "R$" . number_format($valor_outro, 2, ',', '');
    }
}


} else {
    // Perdeu: gerar raspadinha com no máximo 2 valores iguais
    $valores = array_keys($distribuicao);
    $tentativas = 0;

    do {
        $valores_numericos = [];
        $itens = [];

        for ($i = 0; $i < 9; $i++) {
            $valor = $valores[array_rand($valores)];
            $valores_numericos[] = $valor;
            $itens[] = "R$" . number_format($valor, 2, ',', '');
        }

        // Conta quantas vezes cada valor numérico apareceu
        $contagem = array_count_values($valores_numericos);
        $maiorRepeticao = max($contagem);

        $tentativas++;
        if ($tentativas > 30) break; // Segurança para evitar travar

    } while ($maiorRepeticao > 2); // no máximo 2 repetições iguais
}


    // Atualizar saldo
    $novo_saldo = $saldo - $custo + ($ganhou ? $premio : 0);
    $stmt = $pdo->prepare("UPDATE usuarios SET saldo = ? WHERE id = ?");
    $stmt->execute([$novo_saldo, $_SESSION['usuario_id']]);
    // Registrar o jogo no histórico
$stmt = $pdo->prepare("INSERT INTO historico_jogos (usuario_id, raspadinha_id, valor_apostado, valor_premiado) VALUES (?, ?, ?, ?)");
$stmt->execute([
    $_SESSION['usuario_id'],
    $idRaspadinha,
    $custo,
    $ganhou ? $premio : 0.00
]);



    $_SESSION['raspadinha_resultado'] = [
        'itens' => $itens,
        'premio' => "R$" . number_format($premio, 2, ',', ''),
        'ganhou' => $ganhou
    ];

    header("Location: raspadinhas.php?id=$idRaspadinha");
    exit;

} elseif (isset($_SESSION['raspadinha_resultado'])) {
    $itens = $_SESSION['raspadinha_resultado']['itens'];
    $premio = $_SESSION['raspadinha_resultado']['premio'];
    $ganhou = $_SESSION['raspadinha_resultado']['ganhou'];
} else {
    $itens = [];
}

// Atualiza rollover acumulado para o usuário
$stmt = $pdo->prepare("SELECT * FROM rollover WHERE usuario_id = ? AND finalizado = 0 ORDER BY criado_em DESC LIMIT 1");
$stmt->execute([$_SESSION['usuario_id']]);
$rollover = $stmt->fetch();

if ($rollover) {
    $novo_acumulado = $rollover['valor_acumulado'] + $custo; // adiciona o valor da aposta (custo)
    $finalizado = 0;
    if ($novo_acumulado >= $rollover['valor_necessario']) {
        $finalizado = 1;
        // Aqui você pode adicionar lógica para liberar saldo bloqueado, se necessário
    }
    $stmt = $pdo->prepare("UPDATE rollover SET valor_acumulado = ?, finalizado = ? WHERE id = ?");
    $stmt->execute([$novo_acumulado, $finalizado, $rollover['id']]);
}


$json = file_get_contents(__DIR__ . '/images/imagens_raspadinha.json');
$imagens = json_decode($json, true);

$imgBannerGame = $imagens['banner_game'] ?? 'banner-game.png';
$imgRaspeAqui = $imagens['raspe_aqui'] ?? 'RASPE-AQUI.png';

$dadosJson = file_exists('imagens_menu.json') ? json_decode(file_get_contents('imagens_menu.json'), true) : [];
$logo = isset($dadosJson['logo']) ? $dadosJson['logo'] : 'logo.png';

?>

<!doctype html>
<html lang=pt-br>
<head>
<meta charset=UTF-8>
<title>Raspadinha</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel=stylesheet>
<style>body,header{background:#0e1015}.btn-comprar,.btn-jogar{font-size:16px;font-weight:700}.saldo-container,footer{display:flex;background:#121419}#prizes,.raspadinha-box,.saldo-container,footer{background:#121419}#prizes,footer a{color:#fff;font-size:15px}#prizes,*{padding:0}.footer a,.rodape-info a,footer a{text-decoration:none}*{box-sizing:border-box;margin:0}body{color:#fff;font-family:Poppins,sans-serif;padding-bottom:80px}header{padding:10px;text-align:center;position:sticky;top:0;z-index:100}.saldo-container{justify-content:space-between;align-items:center;padding:10px;margin:10px;border-radius:8px}.raspadinha-box{margin:10px;border-radius:8px;padding:0px;text-align:center}.btn-comprar{background:#00e880;color:#0e1015;padding:12px 18px;border:none;border-radius:6px;margin-top:10px;width:100%}.btn-jogar{background:#00c26e;padding:10px;border-radius:6px;margin:10px;width:calc(100% - 20px);border:none}#prizes,canvas{position:absolute;top:0;left:0;border-radius:8px}.footer,footer{bottom:0;border-top:1px solid #222;z-index:9999}.banner{width:100%;margin-top:20px;padding:0 10px}.info-topo,.item{padding:10px;text-align:center}.banner img{width:100%;border-radius:10px}footer{position:fixed;width:100%;justify-content:space-around;padding:10px 0}footer a{text-align:center}#scratch-card{position:relative;width:100%;max-width:320px;height:320px;margin:10px auto}canvas{z-index:2}#prizes{display:grid;grid-template-columns:repeat(3,1fr);gap:5px;z-index:1;width:100%;height:100%}.info-topo{background-color:#121419;border-radius:10px;margin:10px}.item.ganhador{border:2px solid gold;box-shadow:0 0 2px gold;font-weight:700}.item{background:#2a2d36;border-radius:5px;position:relative}.img-premio{width:36px;margin-top:6px;display:inline-block;filter:drop-shadow(0 0 2px #000)}.footer{position:fixed;width:100%;background:#121419;display:flex;justify-content:space-around;padding:12px 0}.footer a{color:#aaa;font-size:12px;text-align:center;display:flex;flex-direction:column;align-items:center;gap:3px}.footer a.active{color:#00ff14}@media (max-width:480px){.cta-bloco h3,.logo{font-size:16px}.btn-depositar,.footer a,.saldo{font-size:15px}.btn-depositar{padding:5px 10px}.cta-bloco p,.ganhador,.rodape-info h4{font-size:13px}.btn-jogar{font-size:14px;padding:10px 95px}.rodape-info{font-size:12px}}.rodape-info{background:#0e1015;padding:20px;font-size:13px;color:#aaa}.rodape-logo{font-family:Orbitron,sans-serif;font-size:20px;margin-bottom:10px;color:#fff}.rodape-info h4{color:#fff;margin:15px 0 5px}.rodape-info ul{list-style:none;padding:0;margin:0}.rodape-info ul li{margin-bottom:5px}.rodape-info a{color:#aaa;font-size:13px}.rodape-info a:hover{text-decoration:underline}.lista-premios{background:#0e1015;margin-top:20px;padding:15px;border-radius:10px;color:#fff}.lista-premios h4{margin-bottom:10px;color:#00e880;text-align:center}.grid-premios{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}.premio-item{background:#2a2d36;border-radius:8px;padding:12px;text-align:center;box-shadow:0 0 3px rgba(0,0,0,.5);font-size:15px}.premio-item strong{color:#00e880;font-size:16px}.premio-item small{color:#ccc;font-size:12px}</style>
</head>
<body>
<script src=https://cdn.jsdelivr.net/npm/canvas-confetti@1.5.1/dist/confetti.browser.min.js></script>
<header>
<img src="images/<?= $logo ?>?v=<?= time() ?>" alt=Logo style=height:40px>
</header>
<div class=saldo-container>
<span><strong>Seu Saldo:</strong></span>
<span style=color:#fff;font-weight:700>R$ <?= number_format($saldo, 2, ',', '.') ?></span>
</div>

</div>
<?php if (!isset($_SESSION['raspadinha_resultado'])): ?>
<div class=raspadinha-box>
<?php if (isset($_SESSION['erro_raspadinha'])): ?>
<div style=color:red;background:#300;padding:10px;border-radius:5px;margin-top:10px>
<?= $_SESSION['erro_raspadinha'] ?>
</div>
<?php unset($_SESSION['erro_raspadinha']); endif; ?>
<img src="images/<?= $imgRaspeAqui ?>?v=<?= filemtime("images/$imgRaspeAqui") ?>" alt="Raspe Aqui" style=width:100%;border-radius:8px;max-width:300px>
<form method=post action="raspadinhas.php?id=<?= $idRaspadinha ?>">
<button class=btn-comprar>Comprar e Raspar (<?= $moeda ?><?= number_format($custo, 2, ',', '.') ?>)</button>
</form>
</div>
<?php else: ?>
<div class=raspadinha-box>
<h4 id=resultado style=display:none></h4>
<div id=resultado-data data-text="<?= $ganhou ? 'Parabéns! Você ganhou ' . $premio : 'Não foi dessa vez! Mas quem sabe a sorte esta na proxima' ?>"></div>
<script>window.addEventListener("load",(function(){}))</script>
<div id=scratch-card>
<canvas id=scratchCanvas width=320 height=330></canvas>
<div id=prizes>
<?php
$itens_marcados = [];
if ($ganhou) {
    $valor_vencedor = $premio;
    $contador = 0;
    foreach ($itens as $i => $valor) {
        if ($valor === $valor_vencedor && $contador < 3) {
            $itens_marcados[$i] = true;
            $contador++;
        }
    }
}
?>
<?php foreach ($itens as $i => $item): 
    preg_match('/([\d,]+)/', $item, $matches);
    $valorLimpo = isset($matches[1]) ? str_replace(',', '.', $matches[1]) : '0.00';
    $valorNumero = floatval($valorLimpo);
    $valorInteiro = intval($valorNumero);
    $caminhoImagem = "images/premios/{$valorInteiro}.png";
?>
<div class="item <?= isset($itens_marcados[$i]) ? 'ganhador' : '' ?>">
<?php
  $valorSemCentavos = intval(floatval(str_replace(['R$', ','], ['', '.'], $item)));
  echo "R$ " . $valorSemCentavos;
?><br>
<img src="<?= $caminhoImagem ?>" alt=Prêmio style=width:50px;margin-top:5px>
</div>
<?php endforeach; ?>
</div>
</div>
<form method=post>
<button class=btn-comprar>Jogar Novamente (R$ <?= number_format($custo, 2, ',', '.') ?>)</button>
</form>
</div>
<?php endif; ?>
<div class=lista-premios>
<h4>Prêmios possíveis:</h4>
<div class=grid-premios>
<?php foreach ($distribuicao as $valorPremio => $chance): 
      $valorInteiro = intval($valorPremio); // Correto: aqui sim é o valor do prêmio
      $imagemPremio = "images/premios/{$valorInteiro}.png";
?>
<div class="premio-item">
  <img src="<?= $imagemPremio ?>" alt="Prêmio" class="img-premio">
  <div class="valor-premio">
    R$ <?= number_format($valorPremio, 2, ',', '.') ?>
  </div>
</div>
<?php endforeach; ?>
</div>
</div>
<div style="background-color:#0e1015;padding:30px 20px;text-align:left;color:#ccc;font-family:Arial,sans-serif">
<div style="max-width:600px;margin:0 auto">
</div>
</div>
<div class=rodape-info>
<div class=logo>
<img src="images/<?= $logo ?>?v=<?= time() ?>" alt=Logo style=height:50px>
</div>
<p>Raspa Mil é a maior e melhor plataforma de raspadinhas do Brasil</p>
<h4>Links Rápidos</h4>
<ul>
<li><a href=index>Início</a></li>
<li><a href=menu>Raspadinhas</a></li>
<li><a href=deposito>Depositar</a></li>
</ul>
<h4>Contato</h4>
<p>contato@raspamil.com</p>
<h4>Suporte</h4>
<p>Horário de atendimento:<br>Segunda a Sexta, 9h às 18h</p>
</div>
<link rel=stylesheet href=https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css>
<div class=footer>
<a href=index>
<div><i class="fas fa-house"></i><br>Início</div>
</a>
<a href=menu>
<div><i class="fas fa-dice"></i><br>Raspadinhas</div>
</a>
<a href=deposito>
<div><i class="fas fa-money-bill-wave"></i><br>Depositar</div>
</a>
<a href=afiliado>
<div><i class="fas fa-user-plus"></i><br>Indique</div>
</a>
<a href=perfil>
<div><i class="fas fa-user"></i><br>Perfil</div>
</a>
</div>
<script>document.getElementById("btnJogar").addEventListener("click",(function(){fetch("raspadinhas.php?id=<?= $idRaspadinha ?>",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"}}).then((e=>e.text())).then((e=>{e.includes("Saldo insuficiente")?document.getElementById("mensagemErro").textContent=e:location.href="raspadinhas.php?id=<?= $idRaspadinha ?>"})).catch((()=>{document.getElementById("mensagemErro").textContent="Erro ao processar. Tente novamente."}))}))</script>
<script src="js/Ax1nMuE4.js?v=<?= time() ?>"></script>
<?php
unset($_SESSION["raspadinha_resultado"]);
?>
</body>
</html>