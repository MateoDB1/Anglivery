<?php
/**
 * API de Gerenciamento do PDV com Sessões - CORRIGIDO
 * Endpoint: /api/pdv.php
 * 
 * Correção: valor_inicial agora está sendo salvo corretamente
 */

require_once 'config.php';

$conn = conectarBD();
$metodo = $_SERVER['REQUEST_METHOD'];

$usuario_id = isset($_GET['usuario_id']) ? intval($_GET['usuario_id']) : 0;

if ($usuario_id === 0) {
    responderErro("Usuário não identificado", 401);
}

switch ($metodo) {
    case 'GET':
        // Verificar se existe sessão ativa
        $stmtSessao = $conn->prepare("
            SELECT 
                ps.id as sessao_id,
                ps.funcionario_id,
                ps.valor_inicial,
                ps.valor_total,
                ps.status,
                ps.data_abertura,
                f.nome as funcionario_nome
            FROM pdv_sessoes ps
            LEFT JOIN funcionarios f ON ps.funcionario_id = f.id
            WHERE ps.usuario_id = ? AND ps.status = 'aberto'
            ORDER BY ps.data_abertura DESC
            LIMIT 1
        ");
        $stmtSessao->bind_param("i", $usuario_id);
        $stmtSessao->execute();
        $resultSessao = $stmtSessao->get_result();
        
        if ($resultSessao->num_rows > 0) {
            // PDV está aberto
            $sessao = $resultSessao->fetch_assoc();
            $stmtSessao->close();
            
            // Buscar vendas APENAS desta sessão (desde a abertura)
            $dataAbertura = $sessao['data_abertura'];
            
            $stmtVendas = $conn->prepare("
                SELECT 
                    COUNT(DISTINCT v.id) as total_vendas,
                    COALESCE(SUM(v.valor_total), 0) as valor_total_vendas
                FROM vendas v
                WHERE v.usuario_id = ? 
                AND v.funcionario_id = ?
                AND v.data_venda >= ?
            ");
            $stmtVendas->bind_param("iis", $usuario_id, $sessao['funcionario_id'], $dataAbertura);
            $stmtVendas->execute();
            $resultVendas = $stmtVendas->get_result();
            $vendas = $resultVendas->fetch_assoc();
            $stmtVendas->close();
            
            $conn->close();
            
            responderSucesso([
                'pdv_aberto' => true,
                'sessao_id' => $sessao['sessao_id'],
                'funcionario_id' => $sessao['funcionario_id'],
                'funcionario_nome' => $sessao['funcionario_nome'],
                'valor_inicial' => floatval($sessao['valor_inicial']),
                'data_abertura' => $sessao['data_abertura'],
                'quantidade_vendas_dia' => intval($vendas['total_vendas']),
                'quantidade_valor_dia' => floatval($vendas['valor_total_vendas'])
            ]);
        } else {
            // PDV está fechado - buscar APENAS última sessão fechada
            $stmtSessao->close();
            
            $stmtUltima = $conn->prepare("
                SELECT 
                    ps.id as sessao_id,
                    ps.funcionario_id,
                    ps.valor_inicial,
                    ps.valor_total,
                    ps.data_abertura,
                    ps.data_fechamento,
                    f.nome as funcionario_nome
                FROM pdv_sessoes ps
                LEFT JOIN funcionarios f ON ps.funcionario_id = f.id
                WHERE ps.usuario_id = ? AND ps.status = 'fechado'
                ORDER BY ps.data_fechamento DESC
                LIMIT 1
            ");
            $stmtUltima->bind_param("i", $usuario_id);
            $stmtUltima->execute();
            $resultUltima = $stmtUltima->get_result();
            
            if ($resultUltima->num_rows > 0) {
                $ultimaSessao = $resultUltima->fetch_assoc();
                
                // Calcular vendas APENAS desta sessão
                $stmtVendasSessao = $conn->prepare("
                    SELECT COALESCE(SUM(v.valor_total), 0) as valor_vendas
                    FROM vendas v
                    WHERE v.usuario_id = ? 
                    AND v.funcionario_id = ?
                    AND v.data_venda >= ?
                    AND v.data_venda <= ?
                ");
                $stmtVendasSessao->bind_param("iiss", 
                    $usuario_id, 
                    $ultimaSessao['funcionario_id'],
                    $ultimaSessao['data_abertura'],
                    $ultimaSessao['data_fechamento']
                );
                $stmtVendasSessao->execute();
                $resultVendasSessao = $stmtVendasSessao->get_result();
                $valorVendas = $resultVendasSessao->fetch_assoc()['valor_vendas'];
                $stmtVendasSessao->close();
                
                $stmtUltima->close();
                $conn->close();
                
                responderSucesso([
                    'pdv_aberto' => false,
                    'funcionario_nome' => $ultimaSessao['funcionario_nome'],
                    'valor_inicial' => floatval($ultimaSessao['valor_inicial']),
                    'quantidade_valor_dia' => floatval($valorVendas),
                    'valor_total' => floatval($ultimaSessao['valor_total']),
                    'data_fechamento' => $ultimaSessao['data_fechamento']
                ]);
            } else {
                $stmtUltima->close();
                $conn->close();
                
                responderSucesso([
                    'pdv_aberto' => false,
                    'funcionario_nome' => null,
                    'valor_inicial' => 0,
                    'quantidade_valor_dia' => 0,
                    'valor_total' => 0,
                    'data_fechamento' => null
                ]);
            }
        }
        break;
        
    case 'POST':
        // Abrir PDV - CORREÇÃO AQUI
        $dados = obterDadosJSON();
        
        if (empty($dados['funcionario_id'])) {
            responderErro("Funcionário é obrigatório para abrir o PDV");
        }
        
        // CORREÇÃO: Verificar se valor_inicial existe e converter corretamente
        if (!isset($dados['valor_inicial'])) {
            $dados['valor_inicial'] = 0;
        }
        
        $funcionario_id = intval($dados['funcionario_id']);
        $valor_inicial = floatval($dados['valor_inicial']);
        
        // Log para debug
        error_log("=== ABERTURA PDV ===");
        error_log("Funcionário ID: " . $funcionario_id);
        error_log("Valor Inicial recebido: " . $valor_inicial);
        error_log("Dados brutos: " . json_encode($dados));
        
        // Verificar se já existe sessão aberta
        $stmtCheck = $conn->prepare("
            SELECT id FROM pdv_sessoes 
            WHERE usuario_id = ? AND status = 'aberto'
        ");
        $stmtCheck->bind_param("i", $usuario_id);
        $stmtCheck->execute();
        
        if ($stmtCheck->get_result()->num_rows > 0) {
            $stmtCheck->close();
            $conn->close();
            responderErro("Já existe uma sessão de PDV aberta");
        }
        $stmtCheck->close();
        
        // Validar funcionário
        $stmtFunc = $conn->prepare("
            SELECT nome FROM funcionarios 
            WHERE id = ? AND usuario_id = ? AND ativo = 1
        ");
        $stmtFunc->bind_param("ii", $funcionario_id, $usuario_id);
        $stmtFunc->execute();
        $resultFunc = $stmtFunc->get_result();
        
        if ($resultFunc->num_rows === 0) {
            $stmtFunc->close();
            $conn->close();
            responderErro("Funcionário não encontrado");
        }
        
        $funcionario_nome = $resultFunc->fetch_assoc()['nome'];
        $stmtFunc->close();
        
        // CORREÇÃO: Usar prepared statement com bind_param correto
        $stmt = $conn->prepare("
            INSERT INTO pdv_sessoes (usuario_id, funcionario_id, valor_inicial, status)
            VALUES (?, ?, ?, 'aberto')
        ");
        
        if (!$stmt) {
            error_log("Erro ao preparar statement: " . $conn->error);
            $conn->close();
            responderErro("Erro ao preparar inserção: " . $conn->error, 500);
        }
        
        // IMPORTANTE: 'd' para DECIMAL/DOUBLE
        $stmt->bind_param("iid", $usuario_id, $funcionario_id, $valor_inicial);
        
        error_log("Executando INSERT com valores:");
        error_log("- usuario_id: " . $usuario_id);
        error_log("- funcionario_id: " . $funcionario_id);
        error_log("- valor_inicial: " . $valor_inicial);
        
        if ($stmt->execute()) {
            $sessao_id = $stmt->insert_id;
            
            // Verificar se foi salvo corretamente
            $stmtVerifica = $conn->prepare("SELECT valor_inicial FROM pdv_sessoes WHERE id = ?");
            $stmtVerifica->bind_param("i", $sessao_id);
            $stmtVerifica->execute();
            $verificaResult = $stmtVerifica->get_result();
            $valorSalvo = $verificaResult->fetch_assoc()['valor_inicial'];
            $stmtVerifica->close();
            
            error_log("Sessão criada com ID: " . $sessao_id);
            error_log("Valor salvo no banco: " . $valorSalvo);
            
            if (abs($valorSalvo - $valor_inicial) > 0.01) {
                error_log("AVISO: Valor salvo ($valorSalvo) difere do valor enviado ($valor_inicial)");
            }
            
            registrarLog($conn, $usuario_id, 'PDV_ABERTO', "Funcionário: $funcionario_nome - Valor Inicial: R$ $valor_inicial");
            $stmt->close();
            $conn->close();
            
            responderSucesso([
                'sessao_id' => $sessao_id,
                'funcionario_id' => $funcionario_id,
                'funcionario_nome' => $funcionario_nome,
                'valor_inicial' => $valor_inicial
            ], "PDV aberto com sucesso");
        } else {
            $erro = $stmt->error;
            error_log("Erro ao executar INSERT: " . $erro);
            $stmt->close();
            $conn->close();
            responderErro("Erro ao abrir PDV: " . $erro, 500);
        }
        break;
        
    case 'PUT':
        // Fechar PDV
        $dados = obterDadosJSON();
        
        $stmtSessao = $conn->prepare("
            SELECT id, funcionario_id, valor_inicial, data_abertura
            FROM pdv_sessoes 
            WHERE usuario_id = ? AND status = 'aberto'
            ORDER BY data_abertura DESC
            LIMIT 1
        ");
        $stmtSessao->bind_param("i", $usuario_id);
        $stmtSessao->execute();
        $resultSessao = $stmtSessao->get_result();
        
        if ($resultSessao->num_rows === 0) {
            $stmtSessao->close();
            $conn->close();
            responderErro("Não há sessão de PDV aberta");
        }
        
        $sessao = $resultSessao->fetch_assoc();
        $sessao_id = $sessao['id'];
        $stmtSessao->close();
        
        // Calcular total de vendas APENAS desta sessão
        $dataAbertura = $sessao['data_abertura'];
        
        $stmtTotal = $conn->prepare("
            SELECT COALESCE(SUM(valor_total), 0) as total_vendas
            FROM vendas
            WHERE usuario_id = ? 
            AND funcionario_id = ?
            AND data_venda >= ?
        ");
        $stmtTotal->bind_param("iis", $usuario_id, $sessao['funcionario_id'], $dataAbertura);
        $stmtTotal->execute();
        $resultTotal = $stmtTotal->get_result();
        $totalVendas = $resultTotal->fetch_assoc()['total_vendas'];
        $stmtTotal->close();
        
        $valor_inicial_sessao = floatval($sessao['valor_inicial']);
        $valor_total = $valor_inicial_sessao + floatval($totalVendas);
        
        error_log("=== FECHAMENTO PDV ===");
        error_log("Valor Inicial: " . $valor_inicial_sessao);
        error_log("Total Vendas: " . $totalVendas);
        error_log("Valor Total: " . $valor_total);
        
        $stmt = $conn->prepare("
            UPDATE pdv_sessoes 
            SET status = 'fechado', 
                data_fechamento = NOW(),
                valor_total = ?
            WHERE id = ? AND usuario_id = ?
        ");
        $stmt->bind_param("dii", $valor_total, $sessao_id, $usuario_id);
        
        if ($stmt->execute()) {
            registrarLog($conn, $usuario_id, 'PDV_FECHADO', "Valor Total: R$ $valor_total - Vendas: R$ $totalVendas");
            $stmt->close();
            $conn->close();
            
            responderSucesso([
                'valor_total' => $valor_total,
                'valor_vendas' => floatval($totalVendas),
                'valor_inicial' => $valor_inicial_sessao
            ], "PDV fechado com sucesso");
        } else {
            $erro = $stmt->error;
            error_log("Erro ao fechar PDV: " . $erro);
            $stmt->close();
            $conn->close();
            responderErro("Erro ao fechar PDV: " . $erro, 500);
        }
        break;
        
    default:
        responderErro("Método não suportado", 405);
}
?>