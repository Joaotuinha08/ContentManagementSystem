<?php
    session_start();
    require_once 'db.php';
    $conn = db_connect();

    if ($_SERVER["REQUEST_METHOD"] == "POST") {

        $captcha = $_POST['g-recaptcha-response'];

        if (!$captcha) {
            $msg = "Erro: Confirme que não é um robô.";
        } else {
            $secret = '6LdSVVQrAAAAAImSqXWTWJGann_ZjJHVHTb3RMca';
            $remoteIp = $_SERVER['REMOTE_ADDR'];

            $verify = file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret=$secret&response=$captcha&remoteip=$remoteIp");
            $captcha_success = json_decode($verify);

            if (!$captcha_success->success) {
                $msg = "Erro: Falha na verificação do reCAPTCHA.";
            } else {
                $email = $_POST["email"];
                $nome = $_POST["nome"];
                $password = $_POST["password"];
                $perfil = "utilizador";

                if (!empty($email) && !empty($nome) && !empty($password)) {
                    $stmt = $conn->prepare("INSERT INTO Utilizadores (nome, email, password, perfil) VALUES (?, ?, ?, ?)");
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt->bind_param("ssss", $nome, $email, $hashed_password, $perfil);

                    if ($stmt->execute()) {
                        $_SESSION["user"] = ["nome" => $nome, "email" => $email, "perfil" => $perfil];
                        header("Location: app.php");
                        exit();
                    } else {
                        $msg = "Erro: " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $msg = "Por favor, preencha todos os campos.";
                }
            }
        }
    }
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Gestão de Conteúdos</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(120deg, #3498db, #8e44ad);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 14px 28px rgba(0,0,0,0.25), 0 10px 10px rgba(0,0,0,0.22);
            padding: 40px;
            width: 100%;
            max-width: 400px;
        }
        
        h1 {
            text-align: center;
            color: #1C1B1F;
            margin-bottom: 30px;
            font-weight: 600;
        }
        
        .input-group {
            position: relative;
            margin-bottom: 25px;
        }
        
        .input-group input {
            background: #F3EDF7;
            border: none;
            border-radius: 4px;
            color: #1C1B1F;
            font-size: 16px;
            padding: 15px 15px 15px 45px;
            width: 100%;
            transition: all 0.3s ease;
        }
        
        .input-group input:focus {
            box-shadow: 0 0 5px rgba(103, 80, 164, 0.5);
            outline: none;
        }
        
        .input-group i {
            color: #79747E;
            font-size: 18px;
            position: absolute;
            left: 15px;
            top: 15px;
        }
        
        button {
            background: #B69DF8;
            border: none;
            border-radius: 100px;
            color: #1C1B1F;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            margin-top: 10px;
            padding: 15px;
            text-transform: uppercase;
            width: 100%;
            position: relative;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
        }
        
        button:hover {
            background: #9A82EA;
            box-shadow: 0 3px 6px rgba(0,0,0,0.16), 0 3px 6px rgba(0,0,0,0.23);
            transform: translateY(-2px);
        }
        
        button:active {
            transform: translateY(1px);
            box-shadow: 0 1px 3px rgba(0,0,0,0.12);
        }
        
        .links {
            display: flex;
            justify-content: center;
            margin-top: 25px;
        }
        
        .links a {
            color: #6750A4;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .links a:hover {
            color: #7F57C2;
            text-decoration: underline;
        }
        
        .error-msg {
            background-color: #F9DEDC;
            border-radius: 4px;
            color: #B3261E;
            margin-top: 20px;
            padding: 10px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Criar Conta</h1>
        
        <form method="POST">
            <div class="input-group">
                <i class="fas fa-envelope"></i>
                <input type="email" name="email" placeholder="Email" required>
            </div>
            
            <div class="input-group">
                <i class="fas fa-user"></i>
                <input type="text" name="nome" placeholder="Nome" required>
            </div>
            
            <div class="input-group">
                <i class="fas fa-lock"></i>
                <input type="password" name="password" placeholder="Senha" required>
            </div>
            <div class="g-recaptcha" data-sitekey="6LdSVVQrAAAAAGw4sTi_BgHWf9JKbBdL25iiQvTU"></div>
            <br>
            
            <button id="registarBtn" type="submit">Registar</button>
        </form>
        
        <div class="links">
            <a href="login.php">Já tem conta? Faça login</a>
        </div>
        
        <?php if (isset($msg)): ?>
            <div class="error-msg">
                <?= $msg ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>