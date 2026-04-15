<?php
/**
 * API de Gerenciamento de Vendas - Corrigida
 * Endpoint: /api/vendas.php
 * 
 * MODIFICAÇÃO: Linha 49 alterada para contar vendas sem agrupar
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
        // Listar vendas com filtros opcionais
        $where = ["v.usuario_id = ?"];
        $params = [$usuario_id];
        $types = "i";
        
        // Filtro por data inicial
        if (isset($_GET['data_inicial'])) {
            $where[] = "DATE(v.data_venda) >= ?";
            $params[] = $_GET['data_inicial'];
            $types .= "s";
        }
        
        // Filtro por data final
        if (isset($_GET['data_final'])) {
            $where[] = "DATE(v.data_venda) <= ?";
            $params[] = $_GET['data_final'];
            $types .= "s";
        }
        
        // Filtro por cliente
        if (isset($_GET['cliente_id']) && $_GET['cliente_id'] != '') {
            $where[] = "v.cliente_id = ?";
            $params[] = intval($_GET['cliente_id']);
            $types .= "i";
        }
        
        // Filtro por funcionário
        if (isset($_GET['funcionario_id']) && $_GET['funcionario_id'] != '') {
            $where[] = "v.funcionario_id = ?";
            $params[] = intval($_GET['funcionario_id']);
            $types .= "i";
        }
        
        $whereClause = implode(" AND ", $where);
        
        // Query melhorada - CADA VENDA É UM CLIENTE ATENDIDO
        $sql = "
            SELECT 
                v.id,
                DATE_FORMAT(v.data_venda, '%d/%m/%Y %H:%i') as data,
                COALESCE(c.nome, '-') as cliente,
                COALESCE(f.nome, 'Funcionário não identificado') as funcionario,
                v.valor_total as valor,
                v.metodo_pagamento as metodo,
                COALESCE(v.troco, 0) as troco,
                GROUP_CONCAT(CONCAT(p.nome, ' (', iv.quantidade, 'x)') SEPARATOR ', ') as produtos,
                SUM(iv.quantidade) as quantidade_total
            FROM vendas v
            LEFT JOIN clientes c ON v.cliente_id = c.id
            LEFT JOIN funcionarios f ON v.funcionario_id = f.id
            LEFT JOIN itens_venda iv ON v.id = iv.venda_id
            LEFT JOIN produtos p ON iv.produto_id = p.id
            WHERE $whereClause
            GROUP BY v.id, v.data_venda, c.nome, f.nome, v.valor_total, v.metodo_pagamento, v.troco
            ORDER BY v.data_venda DESC
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $resultado = $stmt->get_result();
        
        $vendas = [];
        while ($row = $resultado->fetch_assoc()) {
            $vendas[] = $row;
        }
        
        $stmt->close();
        $conn->close();
        responderSucesso($vendas);
        break;
        
    case 'POST':
        // Registrar venda - PERMITINDO ESTOQUE NEGATIVO
        $dados = obterDadosJSON();
        
        if (empty($dados['itens']) || !is_array($dados['itens']) || count($dados['itens']) === 0) {
            responderErro("Nenhum item na venda");
        }
        
        if (empty($dados['metodo_pagamento'])) {
            responderErro("Método de pagamento é obrigatório");
        }
        
        $cliente_id = isset($dados['cliente_id']) ? intval($dados['cliente_id']) : null;
        $funcionario_id = isset($dados['funcionario_id']) ? intval($dados['funcionario_id']) : null;
        $metodo_pagamento = sanitizar($conn, $dados['metodo_pagamento']);
        $valor_pago = isset($dados['valor_pago']) ? floatval($dados['valor_pago']) : null;
        $troco = isset($dados['troco']) ? floatval($dados['troco']) : 0;
        
        // Calcular total
        $valor_total = 0;
        foreach ($dados['itens'] as $item) {
            $valor_total += floatval($item['valor_unitario']) * floatval($item['quantidade']);
        }
        
        // Iniciar transação
        $conn->begin_transaction();
        
        try {
            // Inserir venda principal
            $stmtVenda = $conn->prepare("
                INSERT INTO vendas 
                (usuario_id, cliente_id, funcionario_id, metodo_pagamento, valor_total, valor_pago, troco) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtVenda->bind_param("iiisddd", 
                $usuario_id, $cliente_id, $funcionario_id, 
                $metodo_pagamento, $valor_total, $valor_pago, $troco
            );
            
            if (!$stmtVenda->execute()) {
                throw new Exception("Erro ao registrar venda");
            }
            
            $venda_id = $stmtVenda->insert_id;
            $stmtVenda->close();
            
            // Inserir itens e atualizar estoque (PERMITINDO NEGATIVO)
            foreach ($dados['itens'] as $item) {
                $produto_id = intval($item['produto_id']);
                $quantidade = floatval($item['quantidade']);
                $valor_unitario = floatval($item['valor_unitario']);
                $subtotal = $valor_unitario * $quantidade;
                
                // Buscar estoque atual
                $stmtEstoque = $conn->prepare("
                    SELECT quantidade_em_estoque FROM produtos 
                    WHERE id = ? AND usuario_id = ?
                ");
                $stmtEstoque->bind_param("ii", $produto_id, $usuario_id);
                $stmtEstoque->execute();
                $resultEstoque = $stmtEstoque->get_result();
                
                if ($resultEstoque->num_rows === 0) {
                    throw new Exception("Produto não encontrado");
                }
                
                $estoque_atual = $resultEstoque->fetch_assoc()['quantidade_em_estoque'];
                $stmtEstoque->close();
                
                // Inserir item da venda
                $stmtItem = $conn->prepare("
                    INSERT INTO itens_venda (venda_id, produto_id, quantidade, valor_unitario, subtotal)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmtItem->bind_param("iiddd", $venda_id, $produto_id, $quantidade, $valor_unitario, $subtotal);
                
                if (!$stmtItem->execute()) {
                    throw new Exception("Erro ao registrar item da venda");
                }
                $stmtItem->close();
                
                // Atualizar estoque (pode ficar negativo)
                $novo_estoque = $estoque_atual - $quantidade;
                $stmtUpdate = $conn->prepare("
                    UPDATE produtos 
                    SET quantidade_em_estoque = ? 
                    WHERE id = ? AND usuario_id = ?
                ");
                $stmtUpdate->bind_param("dii", $novo_estoque, $produto_id, $usuario_id);
                
                if (!$stmtUpdate->execute()) {
                    throw new Exception("Erro ao atualizar estoque");
                }
                $stmtUpdate->close();
            }
            
            // Registrar log
            registrarLog($conn, $usuario_id, 'VENDA_REALIZADA', "Venda ID: $venda_id - Valor: R$ " . number_format($valor_total, 2, ',', '.'));
            
            // Commit da transação
            $conn->commit();
            $conn->close();
            responderSucesso(['venda_id' => $venda_id, 'valor_total' => $valor_total], "Venda registrada com sucesso");
            
        } catch (Exception $e) {
            $conn->rollback();
            $conn->close();
            responderErro($e->getMessage(), 500);
        }
        break;
        
    default:
        responderErro("Método não suportado", 405);
}
?>