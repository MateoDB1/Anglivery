<?php
/**
 * Arquivo de Configuração da API
 * Define conexão com banco de dados e funções auxiliares
 */

// Iniciar buffer e desligar output
if (ob_get_level()) ob_end_clean();
ob_start();

// Desligar qualquer output automático
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Headers FIRST, antes de qualquer output
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Se for requisição OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// Configurações do banco de dados
define('DB_HOST', 'localhost:3306');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'anglivery');

/**
 * Conecta ao banco de dados MySQL
 */
function conectarBD() {
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        // Limpar qualquer output
        ob_clean();
        $error = [
            'success' => false,
            'message' => 'Erro de conexão com o banco de dados',
            'data' => null
        ];
        echo json_encode($error, JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    $conn->set_charset("utf8mb4");
    return $conn;
}

/**
 * Obtém dados JSON da requisição
 */
function obterDadosJSON() {
    $input = file_get_contents('php://input');
    if (empty($input)) {
        return [];
    }
    
    $dados = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        ob_clean();
        $error = [
            'success' => false,
            'message' => 'JSON inválido: ' . json_last_error_msg(),
            'data' => null
        ];
        echo json_encode($error, JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    return $dados;
}

/**
 * Sanitiza string para prevenir SQL Injection
 */
function sanitizar($conn, $str) {
    return $conn->real_escape_string(trim($str));
}

/**
 * Responde com sucesso em JSON
 */
function responderSucesso($data = null, $mensagem = "Operação realizada com sucesso") {
    ob_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => $mensagem,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * Responde com erro em JSON
 */
function responderErro($mensagem, $codigo = 400) {
    ob_clean();
    http_response_code($codigo);
    echo json_encode([
        'success' => false,
        'message' => $mensagem,
        'data' => null
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * Valida formato de email
 */
function validarEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Registra log de atividade
 */
function registrarLog($conn, $usuario_id, $acao, $detalhes = null) {
    try {
        $stmt = $conn->prepare("INSERT INTO logs (usuario_id, acao, detalhes) VALUES (?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("iss", $usuario_id, $acao, $detalhes);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Erro ao registrar log: " . $e->getMessage());
    }
}