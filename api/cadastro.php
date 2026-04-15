<?php
/**
 * API de Cadastro de Usuários
 * Endpoint: /api/cadastro.php
 */

require_once 'config.php';

$metodo = $_SERVER['REQUEST_METHOD'];

if ($metodo === 'POST') {
    // Cadastrar novo usuário
    $dados = obterDadosJSON();
    
    // Validar campos obrigatórios
    if (empty($dados['nome']) || empty($dados['email']) || empty($dados['senha'])) {
        responderErro("Todos os campos são obrigatórios", 400);
    }
    
    $nome = trim($dados['nome']);
    $email = trim($dados['email']);
    $senha = $dados['senha'];
    
    // Validar email
    if (!validarEmail($email)) {
        responderErro("Email inválido", 400);
    }
    
    // Validar comprimento da senha
    if (strlen($senha) < 6) {
        responderErro("A senha deve ter pelo menos 6 caracteres", 400);
    }
    
    $conn = conectarBD();
    $email = sanitizar($conn, $email);
    $nome = sanitizar($conn, $nome);
    
    // Verificar se o email já existe
    $stmtCheck = $conn->prepare("SELECT id FROM usuarios WHERE email = ?");
    $stmtCheck->bind_param("s", $email);
    $stmtCheck->execute();
    
    if ($stmtCheck->get_result()->num_rows > 0) {
        $stmtCheck->close();
        $conn->close();
        responderErro("Este email já está cadastrado", 409);
    }
    $stmtCheck->close();
    
    // Hash da senha
    $senhaHash = password_hash($senha, PASSWORD_BCRYPT);
    
    // Inserir usuário
    $stmt = $conn->prepare("INSERT INTO usuarios (nome, email, senha) VALUES (?, ?, ?)");
    
    if (!$stmt) {
        $conn->close();
        responderErro("Erro ao preparar consulta", 500);
    }
    
    $stmt->bind_param("sss", $nome, $email, $senhaHash);
    
    if ($stmt->execute()) {
        $usuario_id = $stmt->insert_id;
        
        // Registrar log
        registrarLog($conn, $usuario_id, 'CADASTRO', 'Novo usuário cadastrado');
        
        $stmt->close();
        $conn->close();
        
        responderSucesso([
            'id' => $usuario_id,
            'nome' => $nome,
            'email' => $email
        ], "Cadastro realizado com sucesso");
    } else {
        $erro = $stmt->error;
        $stmt->close();
        $conn->close();
        responderErro("Erro ao cadastrar usuário: " . $erro, 500);
    }
    
} else if ($metodo === 'GET') {
    // Verificar disponibilidade de email
    if (!isset($_GET['email']) || empty($_GET['email'])) {
        responderErro("Email não informado", 400);
    }
    
    $conn = conectarBD();
    $email = sanitizar($conn, $_GET['email']);
    
    if (!validarEmail($email)) {
        $conn->close();
        responderErro("Email inválido", 400);
    }
    
    $stmt = $conn->prepare("SELECT id FROM usuarios WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    $disponivel = $resultado->num_rows === 0;
    
    $stmt->close();
    $conn->close();
    
    responderSucesso([
        'email' => $email,
        'disponivel' => $disponivel
    ]);
    
} else {
    responderErro("Método não suportado", 405);
}