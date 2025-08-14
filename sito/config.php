<?php

function loadEnv($path = __DIR__) {
    $filePath = rtrim($path, '/') . '/.env';

    if (!file_exists($filePath)) {
        return false;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        return false;
    }

    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }

        list($key, $value) = explode('=', $line, 2);

        $key = trim($key);
        $value = trim($value);

        if (preg_match('/^"(.+)"$/', $value, $matches)) {
            $value = $matches[1];
        } elseif (preg_match("/^'(.+)'$/", $value, $matches)) {
            $value = $matches[1];
        }

        putenv(sprintf('%s=%s', $key, $value));
        $_ENV[$key] = $value;
    }

    return true;
}

loadEnv(__DIR__);

function getDBConnection() {
    try {
        $dsn = "pgsql:host=" . $_ENV["DB_HOST"] . ";dbname=" . $_ENV["DB_NAME"];
        $pdo = new PDO($dsn, $_ENV["DB_USER"], $_ENV["DB_PASS"]);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Imposta lo schema
        $schema = $_ENV["DB_SCHEMA"];
        
        // \p{L} -> Qualsiasi lettera in qualsiasi lingua
        // \p{N} -> Qualsiasi numero
        // Il flag 'u' alla fine è per l'encoding UTF-8
        if (!preg_match('/^[\p{L}_][\p{L}\p{N}_]*$/u', $schema)) {
            throw new Exception("Nome schema non valido: " . htmlspecialchars($schema));
        }
    
        $pdo->exec('SET search_path TO "' . $schema . '"');
        
        return $pdo;
    } catch (PDOException $e) {
        die("Errore connessione: " . $e->getMessage());
    } catch (Exception $e) {
        die("Errore configurazione: " . $e->getMessage());
    }
}

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

function checkLogin() {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header("Location: index.php");
        exit();
    }
}

function checkManager() {
    checkLogin();
    if (!isset($_SESSION['is_manager']) || $_SESSION['is_manager'] !== true) {
        header("Location: dashboard_cliente.php");
        exit();
    }
}

function formatPrice($price) {
    return number_format($price, 2, ',', '.') . ' €';
}

function setFlashMessage($message, $type = 'info') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
}

function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';
        
        unset($_SESSION['flash_message']);
        unset($_SESSION['flash_type']);
        
        return ['message' => $message, 'type' => $type];
    }
    return null;
}

// Funzione per hash password (per future implementazioni)
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// Funzione per verificare password (per future implementazioni)
function verifyPassword($password, $hash) {
    // Per ora confronto diretto, ma pronta per hash
    return $password === $hash;
}
?>