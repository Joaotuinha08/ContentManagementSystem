<?php
session_start();
header('Content-Type: application/json');

require_once '../db.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    $conn = db_connect();
    
    switch ($method) {
        case 'GET':
            handleGet($conn);
            break;
        case 'POST':
            handlePost($conn);
            break;
        case 'DELETE':
            handleDelete($conn);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}

function handleGet($conn) {
    // Determine user profile for visibility rules
    $perfil = 'convidado';
    if (isset($_SESSION['user'])) {
        $perfil = $_SESSION['user']['perfil'];
    }
    
    // Get query parameters for filtering
    $category_filter = isset($_GET['categoria']) ? intval($_GET['categoria']) : null;
    $visibility_filter = isset($_GET['visibilidade']) ? $_GET['visibilidade'] : null;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : null;
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
    
    // Build the query
    $query = "SELECT 
                Conteudo.id_conteudo,
                Conteudo.titulo,
                Conteudo.descricao,
                Conteudo.caminho_ficheiro,
                Conteudo.tipo,
                Conteudo.visibilidade,
                Conteudo.data_upload,
                Categoria.nome AS categoria_nome,
                Categoria.id_categoria,
                Utilizadores.nome AS autor_nome,
                Utilizadores.id_utilizador AS id_autor
              FROM Conteudo
              JOIN Categoria ON Conteudo.id_categoria = Categoria.id_categoria
              JOIN Utilizadores ON Conteudo.id_autor = Utilizadores.id_utilizador
              WHERE ";
    
    $conditions = [];
    $params = [];
    $types = '';
    
    // Apply visibility rules based on user profile
    if ($perfil === 'convidado') {
        $conditions[] = "Conteudo.visibilidade = 'publico'";
    } else {
        // Authenticated users can see both public and private content
        $conditions[] = "(Conteudo.visibilidade = 'publico' OR Conteudo.visibilidade = 'privado')";
    }
    
    // Add category filter
    if ($category_filter) {
        $conditions[] = "Conteudo.id_categoria = ?";
        $params[] = $category_filter;
        $types .= 'i';
    }
    
    // Add visibility filter (only if user is authenticated)
    if ($visibility_filter && in_array($visibility_filter, ['publico', 'privado']) && $perfil !== 'convidado') {
        $conditions[] = "Conteudo.visibilidade = ?";
        $params[] = $visibility_filter;
        $types .= 's';
    }
    
    // Add WHERE clause
    $query .= implode(" AND ", $conditions);
    
    // Order by date (most recent first)
    $query .= " ORDER BY Conteudo.data_upload DESC";
    
    // Add LIMIT and OFFSET if specified
    if ($limit) {
        $query .= " LIMIT ?";
        $params[] = $limit;
        $types .= 'i';
        
        if ($offset > 0) {
            $query .= " OFFSET ?";
            $params[] = $offset;
            $types .= 'i';
        }
    }
    
    // Prepare and execute the statement
    $stmt = $conn->prepare($query);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $contents = [];
    while ($row = $result->fetch_assoc()) {
        $contents[] = [
            'id' => intval($row['id_conteudo']),
            'titulo' => $row['titulo'],
            'descricao' => $row['descricao'],
            'caminho_ficheiro' => $row['caminho_ficheiro'],
            'tipo' => $row['tipo'],
            'visibilidade' => $row['visibilidade'],
            'data_upload' => $row['data_upload'],
            'categoria' => [
                'id' => intval($row['id_categoria']),
                'nome' => $row['categoria_nome']
            ],
            'autor' => [
                'id' => intval($row['id_autor']),
                'nome' => $row['autor_nome']
            ]
        ];
    }
    
    $response = [
        'success' => true,
        'data' => $contents,
        'user_profile' => $perfil
    ];
    
    http_response_code(200);
    echo json_encode($response);
    
    $stmt->close();
    $conn->close();
}

function handlePost($conn) {
    // Check if user is logged in
    if (!isset($_SESSION['user'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        return;
    }
    
    $user = $_SESSION['user'];
    $perfil = $user['perfil'];
    
    // Convidados cannot post content
    if ($perfil === 'convidado') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Guests cannot post content']);
        return;
    }
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Check required fields (id_autor is now taken from session)
    if (!isset($input['titulo']) || !isset($input['descricao']) || !isset($input['id_categoria'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing required fields: titulo, descricao, id_categoria']);
        return;
    }
    
    $titulo = trim($input['titulo']);
    $descricao = trim($input['descricao']);
    $id_categoria = intval($input['id_categoria']);
    $id_autor = $user['id_utilizador']; // Get from session, not from input
    $caminho_ficheiro = isset($input['caminho_ficheiro']) ? $input['caminho_ficheiro'] : '';
    $tipo = isset($input['tipo']) ? $input['tipo'] : 'imagem';
    $data_upload = date('Y-m-d H:i:s');
    
    // Determine visibility based on user profile
    if (in_array($perfil, ['simpatizante', 'administrador'])) {
        $visibilidade = isset($input['visibilidade']) ? $input['visibilidade'] : 'publico';
    } else {
        // Regular users can only post public content
        $visibilidade = 'publico';
    }
    
    // Validate fields
    if (empty($titulo) || empty($descricao)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Title and description cannot be empty']);
        return;
    }
    
    if (!in_array($tipo, ['imagem', 'video', 'audio'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid tipo. Must be: imagem, video, audio']);
        return;
    }
    
    if (!in_array($visibilidade, ['publico', 'privado'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid visibilidade. Must be: publico, privado']);
        return;
    }
    
    // Check if category exists
    $cat_check = $conn->prepare("SELECT id_categoria FROM Categoria WHERE id_categoria = ?");
    $cat_check->bind_param("i", $id_categoria);
    $cat_check->execute();
    if ($cat_check->get_result()->num_rows == 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Category not found']);
        return;
    }
    $cat_check->close();
    
    // Insert content
    $stmt = $conn->prepare("INSERT INTO Conteudo (titulo, descricao, caminho_ficheiro, tipo, visibilidade, data_upload, id_autor, id_categoria) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssii", $titulo, $descricao, $caminho_ficheiro, $tipo, $visibilidade, $data_upload, $id_autor, $id_categoria);
    
    if ($stmt->execute()) {
        $new_id = $conn->insert_id;
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Content created successfully',
            'id' => $new_id,
            'author_id' => $id_autor,
            'visibility' => $visibilidade
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to create content']);
    }
    
    $stmt->close();
    $conn->close();
}

function handleDelete($conn) {
    // Check if user is logged in
    if (!isset($_SESSION['user'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        return;
    }
    
    $user = $_SESSION['user'];
    $perfil = $user['perfil'];
    $user_id = $user['id_utilizador'];
    
    // Convidados cannot delete content
    if ($perfil === 'convidado') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Guests cannot delete content']);
        return;
    }
    
    // Get content ID from query parameter
    $content_id = isset($_GET['id']) ? intval($_GET['id']) : null;
    
    if (!$content_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing content id parameter']);
        return;
    }
    
    // Check if content exists
    $check_stmt = $conn->prepare("SELECT id_conteudo, id_autor, titulo FROM Conteudo WHERE id_conteudo = ?");
    $check_stmt->bind_param("i", $content_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows == 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Content not found']);
        return;
    }
    
    $content = $result->fetch_assoc();
    $check_stmt->close();
    
    // Permission check: Users can only delete their own content, but admins can delete any content
    if ($perfil !== 'administrador' && $content['id_autor'] != $user_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You can only delete your own content']);
        return;
    }
    
    // Delete associated comments first
    $delete_comments = $conn->prepare("DELETE FROM Comentarios WHERE id_conteudo = ?");
    $delete_comments->bind_param("i", $content_id);
    $delete_comments->execute();
    $delete_comments->close();
    
    // Delete the content
    $delete_stmt = $conn->prepare("DELETE FROM Conteudo WHERE id_conteudo = ?");
    $delete_stmt->bind_param("i", $content_id);
    
    if ($delete_stmt->execute()) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Content deleted successfully',
            'deleted_by' => $perfil,
            'content_title' => $content['titulo']
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to delete content']);
    }
    
    $delete_stmt->close();
    $conn->close();
}
?>