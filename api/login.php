<?php
/**
 * API de Autenticação
 * Endpoint: /api/login.php
 */

require_once 'config.php';

$metodo = $_SERVER['REQUEST_METHOD'];

if ($metodo === 'POST') {
    // Login
    $dados = obterDadosJSON();
    
    if (empty($dados['email']) || empty($dados['senha'])) {
        responderErro("Email e senha são obrigatórios", 400);
    }
    
    $conn = conectarBD();
    $email = sanitizar($conn, $dados['email']);
    $senha = $dados['senha'];
    
    // Buscar usuário
    $stmt = $conn->prepare("SELECT id, nome, email, senha FROM usuarios WHERE email = ?");
    
    if (!$stmt) {
        $conn->close();
        responderErro("Erro ao preparar consulta", 500);
    }
    
    $stmt->bind_param("s", $email);
    
    if (!$stmt->execute()) {
        $stmt->close();
        $conn->close();
        responderErro("Erro ao executar consulta", 500);
    }
    
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows === 0) {
        $stmt->close();
        $conn->close();
        responderErro("Email ou senha incorretos", 401);
    }
    
    $usuario = $resultado->fetch_assoc();
    $stmt->close();
    
    // Verificar senha
    if (!password_verify($senha, $usuario['senha'])) {
        $conn->close();
        responderErro("Email ou senha incorretos", 401);
    }
    
    // Registrar log de login
    registrarLog($conn, $usuario['id'], 'LOGIN', 'Login realizado com sucesso');
    
    $conn->close();
    
    // Retornar dados do usuário (sem a senha)
    responderSucesso([
        'id' => $usuario['id'],
        'nome' => $usuario['nome'],
        'email' => $usuario['email']
    ], "Login realizado com sucesso");
    
} else if ($metodo === 'GET') {
    // Verificar sessão
    $usuario_id = isset($_GET['usuario_id']) ? intval($_GET['usuario_id']) : 0;
    
    if ($usuario_id === 0) {
        responderErro("Usuário não identificado", 401);
    }
    
    $conn = conectarBD();
    
    $stmt = $conn->prepare("SELECT id, nome, email FROM usuarios WHERE id = ?");
    $stmt->bind_param("i", $usuario_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows === 0) {
        $stmt->close();
        $conn->close();
        responderErro("Usuário não encontrado", 404);
    }
    
    $usuario = $resultado->fetch_assoc();
    $stmt->close();
    $conn->close();
    
    responderSucesso($usuario);
    
} else {
    responderErro("Método não suportado", 405);
}