<?php
require_once 'config.php';
checkLogin();

$pdo = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $password_attuale = $_POST['password_attuale'];
    $password_nuova = $_POST['password_nuova'];
    $password_conferma = $_POST['password_conferma'];
    
    // Verifica password attuale
    $stmt = $pdo->prepare("SELECT password FROM Credenziali WHERE username = ?");
    $stmt->execute([$_SESSION['username']]);
    $password_db = $stmt->fetchColumn();
    
    if ($password_db !== $password_attuale) {
        $error = "Password attuale non corretta";
    } elseif ($password_nuova !== $password_conferma) {
        $error = "Le nuove password non coincidono";
    } elseif (strlen($password_nuova) < 6) {
        $error = "La password deve essere di almeno 6 caratteri";
    } else {
        // Aggiorna password
        $stmt = $pdo->prepare("UPDATE Credenziali SET password = ? WHERE username = ?");
        $stmt->execute([$password_nuova, $_SESSION['username']]);
        
        setFlashMessage("Password cambiata con successo", 'success');
        if ($_SESSION['is_manager']) {
            header("Location: dashboard_manager.php");
        } else {
            header("Location: dashboard_cliente.php");
        }
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cambio Password - Catena Negozi</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
        }
        
        .navbar {
            background-color: <?php echo $_SESSION['is_manager'] ? '#333' : '#2196F3'; ?>;
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .navbar a {
            color: white;
            text-decoration: none;
            padding: 8px 16px;
            background-color: rgba(0, 0, 0, 0.1);
            border-radius: 4px;
        }
        
        .navbar a:hover {
            background-color: rgba(0, 0, 0, 0.2);
        }
        
        .container {
            max-width: 500px;
            margin: 50px auto;
            padding: 0 20px;
        }
        
        .form-container {
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        h2 {
            margin-bottom: 30px;
            text-align: center;
            color: #333;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-weight: bold;
        }
        
        input[type="password"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }
        
        input[type="password"]:focus {
            outline: none;
            border-color: #4CAF50;
        }
        
        button {
            width: 100%;
            padding: 12px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }
        
        button:hover {
            background-color: #45a049;
        }
        
        .error {
            background-color: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .info {
            color: #666;
            font-size: 14px;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>Cambio Password</h1>
        <a href="<?php echo $_SESSION['is_manager'] ? 'dashboard_manager.php' : 'dashboard_cliente.php'; ?>">← Dashboard</a>
    </nav>
    
    <div class="container">
        <div class="form-container">
            <h2>Modifica la tua password</h2>
            
            <?php if (isset($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label for="password_attuale">Password Attuale</label>
                    <input type="password" id="password_attuale" name="password_attuale" required>
                </div>
                
                <div class="form-group">
                    <label for="password_nuova">Nuova Password</label>
                    <input type="password" id="password_nuova" name="password_nuova" required minlength="6">
                    <div class="info">Minimo 6 caratteri</div>
                </div>
                
                <div class="form-group">
                    <label for="password_conferma">Conferma Nuova Password</label>
                    <input type="password" id="password_conferma" name="password_conferma" required>
                </div>
                
                <button type="submit">Cambia Password</button>
            </form>
        </div>
    </div>
</body>
</html>