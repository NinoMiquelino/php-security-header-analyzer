<?php
// security_header_analyzer.php
// -------------------------------------------------------------
// Ferramenta CLI para análise de cabeçalhos de segurança HTTP.
// Agora com suporte aos modos:
//   --json         → Saída em JSON (para CI/CD)
//   --insecure     → Desativa verificação SSL (para testes locais)
//   --headers-all  → Exibe todos os cabeçalhos HTTP recebidos
// -------------------------------------------------------------

// Constantes e Cores ANSI
define('HEADER_REQUIRED', [
    'Content-Security-Policy',
    'Strict-Transport-Security',
    'X-Frame-Options',
    'X-Content-Type-Options',
    'Referrer-Policy'
]);

define('COLOR_GREEN',  "\033[0;32m");
define('COLOR_RED',    "\033[0;31m");
define('COLOR_YELLOW', "\033[0;33m");
define('COLOR_CYAN',   "\033[0;36m");
define('COLOR_RESET',  "\033[0m");

// -------------------------------------------------------------
// Parte 1: Inicialização e Argumentos
// -------------------------------------------------------------
echo COLOR_CYAN . "\n--- Analisador de Cabeçalhos de Segurança HTTP ---" . COLOR_RESET . "\n";

if ($argc < 2) {
    echo COLOR_YELLOW . "Uso: php " . basename(__FILE__) . " <url> [--insecure] [--json] [--headers-all]" . COLOR_RESET . "\n";
    echo "Exemplo: php " . basename(__FILE__) . " https://www.google.com --json\n";
    exit(1);
}

$url          = $argv[1];
$insecure     = in_array('--insecure', $argv, true);
$json_mode    = in_array('--json', $argv, true);
$show_all     = in_array('--headers-all', $argv, true);

if (!$json_mode) {
    echo "Analisando: " . COLOR_CYAN . $url . COLOR_RESET . "\n";
    if ($insecure) echo COLOR_YELLOW . "(Aviso: SSL Verification desativada - modo inseguro)\n" . COLOR_RESET;
    if ($show_all) echo COLOR_CYAN . "(Exibindo todos os cabeçalhos HTTP)\n" . COLOR_RESET;
    echo "\n";
}

// -------------------------------------------------------------
// Função auxiliar para imprimir status de cada cabeçalho
// -------------------------------------------------------------
function print_header_status(string $header, ?string $value): void {
    if ($value === null) {
        printf(COLOR_RED . "  [AUSENTE] " . COLOR_RESET . "%-35s : Recomendado para segurança.\n", $header);
    } else {
        printf(COLOR_GREEN . "  [PRESENTE] " . COLOR_RESET . "%-35s : %s\n", $header, $value);
    }
}

// -------------------------------------------------------------
// Parte 2: Requisição HTTP via cURL
// -------------------------------------------------------------
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => !$insecure,
    CURLOPT_TIMEOUT => 15
]);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    $error = curl_error($ch);
    if ($json_mode) {
        echo json_encode(['error' => $error], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(1);
    }
    echo COLOR_RED . "\nErro cURL: $error" . COLOR_RESET . "\n";
    curl_close($ch);
    exit(1);
}

$header_size   = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$http_code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
$header_string = substr($response, 0, $header_size);
curl_close($ch);

// -------------------------------------------------------------
// Parte 3: Processamento dos Cabeçalhos HTTP
// -------------------------------------------------------------
// Divide a resposta em blocos (caso haja redirecionamentos)
$header_blocks = preg_split("/\r\n\r\n/", trim($header_string));
$last_headers_raw = end($header_blocks);
$header_lines = explode("\r\n", $last_headers_raw);

$all_headers = [];
$security_headers = array_fill_keys(HEADER_REQUIRED, null);

foreach ($header_lines as $line) {
    if (strpos($line, ':') !== false) {
        list($name, $value) = explode(':', $line, 2);
        $name = trim($name);
        $value = trim($value);
        $all_headers[$name] = $value;

        if (in_array($name, HEADER_REQUIRED, true)) {
            $security_headers[$name] = $value;
        }
    }
}

// -------------------------------------------------------------
// Parte 4: Saída JSON (modo CI/CD / integração DevSecOps)
// -------------------------------------------------------------
if ($json_mode) {
    $missing_headers = array_keys(array_filter($security_headers, fn($v) => $v === null));
    $result = [
        'url' => $effective_url,
        'http_code' => $http_code,
        'headers' => [
            'security' => $security_headers,
            'all' => $all_headers
        ],
        'missing' => $missing_headers,
        'missing_count' => count($missing_headers),
        'secure' => count($missing_headers) === 0,
        'timestamp' => date('c')
    ];

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

// -------------------------------------------------------------
// Parte 5: Relatório CLI Tradicional
// -------------------------------------------------------------
echo COLOR_CYAN . "--- Relatório de Conformidade de Segurança ---\n" . COLOR_RESET;

$missing_count = 0;
foreach ($security_headers as $header => $value) {
    print_header_status($header, $value);
    if ($value === null) $missing_count++;
}

echo "\n" . COLOR_CYAN . "--- Sumário ---\n" . COLOR_RESET;
echo "Status HTTP Final: " . COLOR_YELLOW . $http_code . COLOR_RESET . "\n";
echo "URL Final (após redirecionamentos): " . COLOR_CYAN . $effective_url . COLOR_RESET . "\n";

if ($missing_count === 0) {
    echo COLOR_GREEN . "Resultado: Todos os cabeçalhos críticos de segurança foram detectados." . COLOR_RESET . "\n";
} else {
    echo COLOR_YELLOW . "Aviso: " . COLOR_RESET . $missing_count . " cabeçalho(s) de segurança ausente(s)." . "\n";
    echo "Recomendação: Implemente os cabeçalhos ausentes para mitigar riscos como XSS e Clickjacking (DevSecOps).\n";
}

// -------------------------------------------------------------
// Parte 6: Exibir todos os cabeçalhos HTTP (opcional)
// -------------------------------------------------------------
if ($show_all) {
    echo "\n" . COLOR_CYAN . "--- Todos os Cabeçalhos HTTP ---\n" . COLOR_RESET;
    foreach ($all_headers as $name => $value) {
        printf("%-35s : %s\n", $name, $value);
    }
}

echo "\n" . COLOR_CYAN . "Fim da Análise." . COLOR_RESET . "\n";
?>