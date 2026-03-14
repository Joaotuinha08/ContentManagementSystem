<?php
session_start();
if (isset($_SESSION["user"])) {
    $user = $_SESSION["user"];
    $perfil = $user['perfil'];
} else {
    $user = null;
    $perfil = "convidado";
}

require_once 'db.php';
$conn = db_connect();

// Handle category filter (optional)
$category_filter = isset($_GET['categoria']) ? intval($_GET['categoria']) : null;

// Build base query
$query = "SELECT Conteudo.*, Categoria.nome AS categoria_nome, Utilizadores.nome AS autor_nome
          FROM Conteudo
          JOIN Categoria ON Conteudo.id_categoria = Categoria.id_categoria
          JOIN Utilizadores ON Conteudo.id_autor = Utilizadores.id_utilizador
          WHERE ";

// Visibility logic
if ($perfil === 'convidado') {
    $query .= "Conteudo.visibilidade = 'publico'";
} else {
    $query .= "(Conteudo.visibilidade = 'publico' OR Conteudo.visibilidade = 'privado')";
}

// Category filter
if ($category_filter) {
    $query .= " AND Conteudo.id_categoria = $category_filter";
}

// Order by date (most recent first)
$query .= " ORDER BY Conteudo.data_upload DESC";

$result = $conn->query($query);

// Get all categories for filter dropdown
$cat_result = $conn->query("SELECT id_categoria, nome FROM Categoria");
$categorias = $cat_result ? $cat_result->fetch_all(MYSQLI_ASSOC) : [];

// Get all comments grouped by content
$comentarios_result = $conn->query(
    "SELECT Comentarios.*, Utilizadores.nome AS autor_nome 
     FROM Comentarios 
     JOIN Utilizadores ON Comentarios.id_autor = Utilizadores.id_utilizador
     ORDER BY Comentarios.data_comentario ASC"
);
$comentarios_por_conteudo = [];
if ($comentarios_result) {
    while ($coment = $comentarios_result->fetch_assoc()) {
        $comentarios_por_conteudo[$coment['id_conteudo']][] = $coment;
    }
}

// Get all users (for admin user management)
$users = [];
if ($perfil === 'administrador') {
    $users_result = $conn->query("SELECT id_utilizador, nome, email, perfil FROM Utilizadores ORDER BY nome");
    $users = $users_result ? $users_result->fetch_all(MYSQLI_ASSOC) : [];
}
// Handle profile changes (Admin only)
if (isset($_POST['change_profiles']) && $perfil === 'administrador') {
    if (isset($_POST['user_ids']) && isset($_POST['new_profiles']) && 
        is_array($_POST['user_ids']) && is_array($_POST['new_profiles'])) {
        
        $valid_profiles = ['convidado', 'utilizador', 'simpatizante', 'administrador'];
        $changes_made = false;
        
        for ($i = 0; $i < count($_POST['user_ids']); $i++) {
            $user_id = intval($_POST['user_ids'][$i]);
            $new_profile = $_POST['new_profiles'][$i];
            
            // Validate profile type
            if (in_array($new_profile, $valid_profiles)) {
                $stmt = $conn->prepare("UPDATE Utilizadores SET perfil = ? WHERE id_utilizador = ?");
                $stmt->bind_param("si", $new_profile, $user_id);
                
                if ($stmt->execute()) {
                    $changes_made = true;
                    
                    // Check if the user is currently logged in
                    if ($user && $user['id_utilizador'] == $user_id) {
                        // Update the session if this is the current user
                        $_SESSION['user']['perfil'] = $new_profile;
                    }
                }
                $stmt->close();
            }
        }
        
        
        if ($changes_made) {
            header("Refresh: 0");
            exit();
        }
    }
}

// Handle user deletion (Admin only)
if (isset($_POST['delete_user']) && $perfil === 'administrador') {
    $user_id_to_delete = intval($_POST['user_id_to_delete']);
    
    // Check if the user to delete is not an admin and not the current user
    $check_stmt = $conn->prepare("SELECT perfil FROM Utilizadores WHERE id_utilizador = ?");
    $check_stmt->bind_param("i", $user_id_to_delete);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $user_to_delete = $check_result->fetch_assoc();
        
        if ($user_to_delete['perfil'] !== 'administrador' && $user_id_to_delete != $user['id_utilizador']) {
            // First delete user's comments
            $delete_comments = $conn->prepare("DELETE FROM Comentarios WHERE id_autor = ?");
            $delete_comments->bind_param("i", $user_id_to_delete);
            $delete_comments->execute();
            $delete_comments->close();
            
            // Then delete user's content
            $delete_content = $conn->prepare("DELETE FROM Conteudo WHERE id_autor = ?");
            $delete_content->bind_param("i", $user_id_to_delete);
            $delete_content->execute();
            $delete_content->close();
            
            // Finally delete the user
            $delete_user_stmt = $conn->prepare("DELETE FROM Utilizadores WHERE id_utilizador = ?");
            $delete_user_stmt->bind_param("i", $user_id_to_delete);
            
            if ($delete_user_stmt->execute()) {
                header("Refresh: 0");
                exit();
            }
            $delete_user_stmt->close();
        }
    }
    $check_stmt->close();
}

// Handle content deletion
if (isset($_POST['delete_content']) && $user) {
    $content_id = intval($_POST['content_id']);
    
    // Check if user owns the content or is admin
    $check_stmt = $conn->prepare("SELECT id_autor FROM Conteudo WHERE id_conteudo = ?");
    $check_stmt->bind_param("i", $content_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $content = $check_result->fetch_assoc();
        
        if ($content['id_autor'] == $user['id_utilizador'] || $perfil === 'administrador') {
            // First delete all comments for this content
            $delete_comments = $conn->prepare("DELETE FROM Comentarios WHERE id_conteudo = ?");
            $delete_comments->bind_param("i", $content_id);
            $delete_comments->execute();
            $delete_comments->close();
            
            // Then delete the content
            $delete_content_stmt = $conn->prepare("DELETE FROM Conteudo WHERE id_conteudo = ?");
            $delete_content_stmt->bind_param("i", $content_id);
            
            if ($delete_content_stmt->execute()) {
                header("Refresh: 0");
                exit();
            }
            $delete_content_stmt->close();
        }
    }
    $check_stmt->close();
}

// Handle category creation
if (isset($_POST['criar_categoria']) && in_array($perfil, ['simpatizante', 'administrador'])) {
    $nome_categoria = trim($_POST['nome_categoria']);
    $tipo_categoria = ($perfil === 'administrador') ? $_POST['tipo_categoria'] : 'secundaria';
    $id_criador = $user['id_utilizador'];

    // Validate type
    if ($perfil === 'simpatizante' && $tipo_categoria !== 'secundaria') {
        echo "<p style='color:red'>Simpatizantes só podem criar categorias secundárias.</p>";
    } else {
        $stmt = $conn->prepare("INSERT INTO Categoria (nome, tipo, id_criador) VALUES (?, ?, ?)");
        $stmt->bind_param("ssi", $nome_categoria, $tipo_categoria, $id_criador);
        if ($stmt->execute()) {
            header("Refresh: 0");
        } else {
            echo "<p style='color:red'>Erro ao criar categoria.</p>";
        }
        $stmt->close();
    }
}

// Handle content upload
if (isset($_POST['upload'])) {
    $titulo = $_POST['titulo'];
    $descricao = $_POST['descricao'];
    $id_categoria = intval($_POST['categoria']);
    $id_autor = $user['id_utilizador'];
    $data_upload = date('Y-m-d H:i:s');

    // Determine visibility
    if (in_array($perfil, ['simpatizante', 'administrador'])) {
        $visibilidade = $_POST['visibilidade'];
    } else {
        $visibilidade = 'publico';
    }

    // Handle file upload
    if (isset($_FILES['ficheiro']) && $_FILES['ficheiro']['error'] == 0) {
        $upload_dir = 'uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $filename = basename($_FILES['ficheiro']['name']);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','mp4','mp3','wav','ogg','webm'];
        if (!in_array($ext, $allowed)) {
            echo "<p style='color:red'>Tipo de ficheiro não permitido.</p>";
        } else {
            $unique_name = uniqid() . '_' . $filename;
            $target_path = $upload_dir . $unique_name;
            if (move_uploaded_file($_FILES['ficheiro']['tmp_name'], $target_path)) {
                // Determine type
                if (in_array($ext, ['jpg','jpeg','png','gif'])) {
                    $tipo = 'imagem';
                } elseif (in_array($ext, ['mp4','webm'])) {
                    $tipo = 'video';
                } else {
                    $tipo = 'audio';
                }
                // Insert into DB
                $stmt = $conn->prepare("INSERT INTO Conteudo (titulo, descricao, caminho_ficheiro, tipo, visibilidade, data_upload, id_autor, id_categoria) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssssii", $titulo, $descricao, $target_path, $tipo, $visibilidade, $data_upload, $id_autor, $id_categoria);
                if ($stmt->execute()) {
                    header("Refresh: 0");
            exit();
                } else {
                    echo "<p style='color:red'>Erro ao inserir na base de dados.</p>";
                }
                $stmt->close();
            } else {
                echo "<p style='color:red'>Erro ao guardar o ficheiro.</p>";
            }
        }
    } else {
        echo "<p style='color:red'>Erro no upload do ficheiro.</p>";
    }
}

// Handle comment submission
if (isset($_POST['comentar']) && $perfil !== 'convidado') {
    $id_conteudo = intval($_POST['id_conteudo']);
    $texto = trim($_POST['texto_comentario']);
    $id_autor = $user['id_utilizador'];
    $data_comentario = date('Y-m-d H:i:s');

    if ($texto !== '') {
        $stmt = $conn->prepare("INSERT INTO Comentarios (id_conteudo, id_autor, texto, data_comentario) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiss", $id_conteudo, $id_autor, $texto, $data_comentario);
        if ($stmt->execute()) {
            header("Refresh: 0");
        } else {
            echo "<p style='color:red'>Erro ao inserir comentário.</p>";
        }
        $stmt->close();
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Gestão de Conteúdos</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Base styles */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: #f5f5f5;
            color: #333;
            min-height: 100vh;
        }
        
        /* Navbar styles */
        .navbar {
            background: linear-gradient(120deg, #3498db, #8e44ad);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .navbar-brand {
            font-size: 1.4rem;
            font-weight: 600;
        }
        
        .navbar-actions {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        /* Button styles */
        .btn {
            background: #B69DF8;
            border: none;
            border-radius: 100px;
            color: #1C1B1F;
            cursor: pointer;
            font-weight: 600;
            padding: 8px 16px;
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 1px 3px rgba(0,0,0,0.12);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .btn:hover {
            background: #9A82EA;
            box-shadow: 0 3px 6px rgba(0,0,0,0.16);
            transform: translateY(-2px);
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid white;
            color: white;
        }
        
        .btn-outline:hover {
            background: rgba(255,255,255,0.2);
        }
        
        /* Container */
        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 25px 20px;
        }
        
        /* Feed styles */
        .feed-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .feed-title {
            font-size: 1.6rem;
            color: #333;
        }
        
        .filter-form {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            background-color: white;
        }
        
        /* Card styles */
        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 25px;
            padding: 20px;
            overflow: hidden;
        }
        
        .card-header {
            margin-bottom: 15px;
        }
        
        .card-title {
            font-size: 1.4rem;
            margin-bottom: 5px;
        }
        
        .card-meta {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .card-desc {
            margin-bottom: 15px;
            line-height: 1.5;
        }
        
        .card-media {
            margin: 15px 0;
            text-align: center;
        }
        
        .card-media img {
            max-width: 100%;
            max-height: 400px;
            border-radius: 4px;
        }
        
        .card-media video {
            max-width: 100%;
            max-height: 400px;
        }
        
        .card-media audio {
            width: 100%;
        }
        
        /* Comments section */
        .comments {
            margin-top: 20px;
            border-top: 1px solid #eee;
            padding-top: 15px;
        }
        
        .comments-list {
            list-style: none;
            margin-bottom: 15px;
        }
        
        .comment-item {
            padding: 10px 15px;
            background: #f9f9f9;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        
        .comment-author {
            font-weight: bold;
        }
        
        .comment-date {
            font-size: 12px;
            color: #777;
        }
        
        .comment-form {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        
        .comment-form textarea {
            flex-grow: 1;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            resize: vertical;
            min-height: 60px;
        }
        
        /* Modal styles */
        .modal-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 20px;
        }
        
        .modal {
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal.modal-large {
            max-width: 600px;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
        }
        
        .modal-title {
            font-size: 1.2rem;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 1.4rem;
            cursor: pointer;
            color: #666;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }
        
        .form-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 15px;
        }
        
        textarea.form-input {
            min-height: 100px;
            resize: vertical;
        }

        button[onclick*="confirmDelete"]:hover,
        button[onclick*="confirmDeleteContent"]:hover,
        button[onclick*="confirmDeleteComment"]:hover {
            background: rgba(231, 76, 60, 0.1) !important;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .navbar {
                flex-direction: column;
                padding: 15px;
                gap: 15px;
            }
            
            .navbar-actions {
                width: 100%;
                justify-content: center;
            }
            
            .comment-form {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="navbar-brand">Sistema de Gestão de Conteúdos</div>
        <div class="navbar-actions">
            <?php if (in_array($perfil, ['utilizador', 'simpatizante', 'administrador'])): ?>
                <button class="btn" onclick="openModal('postModal')">
                    <i class="fas fa-plus"></i> Novo Conteúdo
                </button>
            <?php endif; ?>
            
            <?php if (in_array($perfil, ['simpatizante', 'administrador'])): ?>
                <button class="btn" onclick="openModal('categoryModal')">
                    <i class="fas fa-folder-plus"></i> Nova Categoria
                </button>
            <?php endif; ?>

            <?php if ($perfil === 'administrador'): ?>
                <button class="btn" onclick="openModal('usersModal')">
                    <i class="fas fa-users-cog"></i> Gerir Utilizadores
                </button>
            <?php endif; ?>
            
            <?php if ($user): ?>
                <a href="logout.php" class="btn btn-outline">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            <?php else: ?>
                <a href="login.php" class="btn btn-outline">
                    <i class="fas fa-sign-in-alt"></i> Login
                </a>
            <?php endif; ?>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container">
        <!-- Feed Header with Filters -->
        <div class="feed-header">
            <h2 class="feed-title">Feed de Conteúdos</h2>
            
            <form method="get" action="" class="filter-form">
                <select name="categoria" id="categoria">
                    <option value="">Todas as Categorias</option>
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?= $cat['id_categoria'] ?>" <?= ($category_filter == $cat['id_categoria']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn">Filtrar</button>
            </form>
        </div>
        
        <!-- Content Feed -->
        <?php if ($result && $result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): ?>
                <div class="card">
                    <div class="card-header">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                            <div style="flex-grow: 1;">
                                <h3 class="card-title"><?= htmlspecialchars($row['titulo']) ?></h3>
                                <div class="card-meta">
                                    <span><i class="fas fa-folder"></i> <?= htmlspecialchars($row['categoria_nome']) ?></span> | 
                                    <span><i class="fas fa-file"></i> <?= htmlspecialchars($row['tipo']) ?></span> | 
                                    <span><i class="fas fa-eye"></i> <?= htmlspecialchars($row['visibilidade']) ?></span>
                                    <br>
                                    <span><i class="fas fa-user"></i> <?= htmlspecialchars($row['autor_nome']) ?></span> | 
                                    <span><i class="fas fa-calendar"></i> <?= date('d/m/Y H:i', strtotime($row['data_upload'])) ?></span>
                                </div>
                            </div>
                            
                            <?php if ($user && ($row['id_autor'] == $user['id_utilizador'] || $perfil === 'administrador')): ?>
                                <button type="button" onclick="confirmDeleteContent(<?= $row['id_conteudo'] ?>, '<?= htmlspecialchars($row['titulo']) ?>')" 
                                        style="background: transparent; color: #e74c3c; border: 1px solid #e74c3c; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 11px; margin-left: 10px; transition: all 0.3s ease;">
                                    <i class="fas fa-trash"></i> Eliminar
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="card-desc">
                        <?= nl2br(htmlspecialchars($row['descricao'])) ?>
                    </div>
                    
                    <div class="card-media">
                        <?php if ($row['tipo'] === 'imagem'): ?>
                            <img src="<?= htmlspecialchars($row['caminho_ficheiro']) ?>" alt="imagem">
                        <?php elseif ($row['tipo'] === 'video'): ?>
                            <video controls>
                                <source src="<?= htmlspecialchars($row['caminho_ficheiro']) ?>">
                            </video>
                        <?php elseif ($row['tipo'] === 'audio'): ?>
                            <audio controls>
                                <source src="<?= htmlspecialchars($row['caminho_ficheiro']) ?>">
                            </audio>
                        <?php endif; ?>
                    </div>
                    
                    <div class="comments">
                        <?php
                            $post_id = $row['id_conteudo'];
                            $has_comments = isset($comentarios_por_conteudo[$post_id]);
                        ?>
                        
                        <h4>
                            <i class="fas fa-comments"></i> 
                            Comentários <?= $has_comments ? '(' . count($comentarios_por_conteudo[$post_id]) . ')' : '' ?>
                        </h4>
                        
                        <?php if ($has_comments): ?>
                            <ul class="comments-list">
                            <?php foreach ($comentarios_por_conteudo[$post_id] as $coment): ?>
                                <li class="comment-item">
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                        <div style="flex-grow: 1;">
                                            <div class="comment-author"><?= htmlspecialchars($coment['autor_nome']) ?></div>
                                            <div><?= nl2br(htmlspecialchars($coment['texto'])) ?></div>
                                            <div class="comment-date"><?= date('d/m/Y H:i', strtotime($coment['data_comentario'])) ?></div>
                                        </div>
                                        
                                        <?php if ($user && ($coment['id_autor'] == $user['id_utilizador'] || $perfil === 'administrador')): ?>
                                            <button type="button" onclick="confirmDeleteComment(<?= $coment['id_comentario'] ?>)" 
                                                    style="background: transparent; color: #e74c3c; border: 1px solid #e74c3c; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 11px; margin-left: 10px; transition: all 0.3s ease;">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p>Sem comentários</p>
                        <?php endif; ?>
                        
                        <?php if ($perfil !== 'convidado'): ?>
                            <form method="post" class="comment-form">
                                <input type="hidden" name="id_conteudo" value="<?= $post_id ?>">
                                <textarea name="texto_comentario" placeholder="Escreva um comentário..." required></textarea>
                                <button type="submit" name="comentar" class="btn">Comentar</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="card">
                <p style="text-align: center; padding: 20px;">Sem conteúdos para mostrar.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- User Management Modal (Admin only) -->
    <?php if ($perfil === 'administrador'): ?>
        <div class="modal-bg" id="usersModalBg">
            <div class="modal modal-large">
                <div class="modal-header">
                    <h3 class="modal-title">Gestão de Utilizadores</h3>
                    <button class="close-modal" onclick="closeModal('usersModal')">&times;</button>
                </div>
                <div class="modal-body">
                    <?php if (!empty($users)): ?>
                        <form method="post">
                            <table style="width: 100%; border-collapse: collapse; margin-bottom: 15px;">
                                <thead>
                                    <tr style="background: #f5f5f5; text-align: left;">
                                        <th style="padding: 10px;">Nome</th>
                                        <th style="padding: 10px;">Email</th>
                                        <th style="padding: 10px;">Perfil</th>
                                        <th style="padding: 10px;">Novo Perfil</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $u): ?>
                                    <tr style="border-bottom: 1px solid #eee;">
                                        <td style="padding: 10px;"><?= htmlspecialchars($u['nome']) ?></td>
                                        <td style="padding: 10px;"><?= htmlspecialchars($u['email']) ?></td>
                                        <td style="padding: 10px;"><?= htmlspecialchars($u['perfil']) ?></td>
                                        <td style="padding: 10px;">
                                            <?php if ($u['perfil'] !== 'administrador'): ?>
                                                <input type="hidden" name="user_ids[]" value="<?= $u['id_utilizador'] ?>">
                                                <select name="new_profiles[]" style="width: 100%; padding: 5px;">
                                                    <option value="convidado" <?= $u['perfil'] === 'convidado' ? 'selected' : '' ?>>Convidado</option>
                                                    <option value="utilizador" <?= $u['perfil'] === 'utilizador' ? 'selected' : '' ?>>Utilizador</option>
                                                    <option value="simpatizante" <?= $u['perfil'] === 'simpatizante' ? 'selected' : '' ?>>Simpatizante</option>
                                                    <option value="administrador">Administrador</option>
                                                </select>
                                            <?php else: ?>
                                                <span style="color: #888;">Admin</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 10px;">
                                            <?php if ($u['perfil'] !== 'administrador' && $u['id_utilizador'] != $user['id_utilizador']): ?>
                                                <button type="button" onclick="confirmDelete(<?= $u['id_utilizador'] ?>, '<?= htmlspecialchars($u['nome']) ?>')" 
                                                        style="background: transparent; color: #e74c3c; border: 1px solid #e74c3c; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 12px; transition: all 0.3s ease;">
                                                    <i class="fas fa-trash"></i> Eliminar
                                                </button>
                                            <?php else: ?>
                                                <span style="color: #888;">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <button type="submit" name="change_profiles" class="btn" style="width: 100%; margin-top: 15px;">Aplicar Alterações</button>
                        </form>
                        <!-- Hidden forms for deletion -->
                        <form method="post" id="deleteUserForm" style="display: none;">
                            <input type="hidden" name="user_id_to_delete" id="userIdToDelete">
                            <input type="hidden" name="delete_user" value="1">
                        </form>
                        <form method="post" id="deleteContentForm" style="display: none;">
                            <input type="hidden" name="content_id" id="contentIdToDelete">
                            <input type="hidden" name="delete_content" value="1">
                        </form>
                        
                        <form method="post" id="deleteCommentForm" style="display: none;">
                            <input type="hidden" name="comment_id" id="commentIdToDelete">
                            <input type="hidden" name="delete_comment" value="1">
                        </form>
                    <?php else: ?>
                        <p>Não foram encontrados utilizadores.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Category Creation Modal -->
    <?php if (in_array($perfil, ['simpatizante', 'administrador'])): ?>
    <div class="modal-bg" id="categoryModalBg">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Nova Categoria</h3>
                <button class="close-modal" onclick="closeModal('categoryModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="post" action="">
                    <div class="form-group">
                        <label for="nome_categoria">Nome da Categoria:</label>
                        <input type="text" name="nome_categoria" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="tipo_categoria">Tipo:</label>
                        <?php if ($perfil === 'administrador'): ?>
                            <select name="tipo_categoria" class="form-input">
                                <option value="principal">Principal</option>
                                <option value="secundaria">Secundária</option>
                            </select>
                        <?php else: ?>
                            <input type="text" name="tipo_categoria" value="secundaria" readonly class="form-input">
                        <?php endif; ?>
                    </div>
                    
                    <button type="submit" name="criar_categoria" class="btn" style="width: 100%;">Criar Categoria</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Post Content Modal -->
    <?php if (in_array($perfil, ['utilizador', 'simpatizante', 'administrador'])): ?>
    <div class="modal-bg" id="postModalBg">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Novo Conteúdo</h3>
                <button class="close-modal" onclick="closeModal('postModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="post" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="titulo">Título:</label>
                        <input type="text" name="titulo" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label for="descricao">Descrição:</label>
                        <textarea name="descricao" class="form-input" required></textarea>
                    </div>

                    <div class="form-group">
                        <label for="ficheiro">Ficheiro:</label>
                        <input type="file" name="ficheiro" accept="image/*,video/*,audio/*" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label for="categoria">Categoria:</label>
                        <select name="categoria" class="form-input" required>
                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?= $cat['id_categoria'] ?>"><?= htmlspecialchars($cat['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if (in_array($perfil, ['simpatizante', 'administrador'])): ?>
                        <div class="form-group">
                            <label for="visibilidade">Visibilidade:</label>
                            <select name="visibilidade" class="form-input">
                                <option value="publico">Público</option>
                                <option value="privado">Privado</option>
                            </select>
                        </div>
                    <?php endif; ?>

                    <button type="submit" name="upload" class="btn" style="width: 100%;">Publicar</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        function openModal(id) {
            document.getElementById(id + 'Bg').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal(id) {
            document.getElementById(id + 'Bg').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        // Close modal if clicking outside the modal content
        window.onclick = function(event) {
            if (event.target.className === 'modal-bg') {
                event.target.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        }

        // Confirm user deletion
        function confirmDelete(userId, userName) {
            if (confirm('Tem a certeza que deseja eliminar o utilizador "' + userName + '"?\n\nEsta ação irá também eliminar todos os conteúdos e comentários deste utilizador e não pode ser desfeita.')) {
                document.getElementById('userIdToDelete').value = userId;
                document.getElementById('deleteUserForm').submit();
            }
        }

        // Confirm content deletion
        function confirmDeleteContent(contentId, contentTitle) {
            if (confirm('Tem a certeza que deseja eliminar o conteúdo "' + contentTitle + '"?\n\nEsta ação irá também eliminar todos os comentários deste conteúdo e não pode ser desfeita.')) {
                document.getElementById('contentIdToDelete').value = contentId;
                document.getElementById('deleteContentForm').submit();
            }
        }

        // Confirm comment deletion
        function confirmDeleteComment(commentId) {
            if (confirm('Tem a certeza que deseja eliminar este comentário?\n\nEsta ação não pode ser desfeita.')) {
                document.getElementById('commentIdToDelete').value = commentId;
                document.getElementById('deleteCommentForm').submit();
            }
        }
    </script>
</body>
</html>