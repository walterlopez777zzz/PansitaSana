<?php
// /wabo/login.php (Versión Final Corregida y Mejorada)

// 1. SEGURIDAD DE SESIÓN (Reemplaza a session_start)
// Sube un nivel (..) para encontrar la carpeta 'api'
require_once __DIR__ . '/../api/session_security.php';

// 2. Lógica PHP (sin cambios)
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header('Location: dashboard.php');
    exit;
}
// ... resto del código ...
require_once __DIR__ . '/core/db_connect.php';
$error_message = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($conn)) {
        $username = $_POST['username'];
        $password = $_POST['password'];
        $stmt = $conn->prepare("SELECT id, password_hash, failed_login_attempts, last_failed_attempt FROM admin_users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->bind_result($id, $password_hash, $failed_attempts, $last_attempt);
            $stmt->fetch();
            if ($failed_attempts >= 5) {
                $last_attempt_time = strtotime($last_attempt);
                $one_hour_ago = strtotime('-1 hour');
                if ($last_attempt_time > $one_hour_ago) {
                    $error_message = "Demasiados intentos fallidos. Su cuenta está bloqueada temporalmente por 1 hora.";
                } else {
                    $reset_stmt = $conn->prepare("UPDATE admin_users SET failed_login_attempts = 0, last_failed_attempt = NULL WHERE username = ?");
                    $reset_stmt->bind_param("s", $username);
                    $reset_stmt->execute();
                    $reset_stmt->close();
                }
            }
            if (empty($error_message)) {
                if (password_verify($password, $password_hash)) {
                    $update_stmt = $conn->prepare("UPDATE admin_users SET failed_login_attempts = 0, last_failed_attempt = NULL WHERE username = ?");
                    $update_stmt->bind_param("s", $username);
                    $update_stmt->execute();
                    $update_stmt->close();
                    session_regenerate_id(true);
                    $_SESSION['loggedin'] = true;
                    $_SESSION['username'] = $username;
                    $_SESSION['wabo_user_id'] = $id;
                    header('Location: dashboard.php');
                    exit;
                } else {
                    $update_stmt = $conn->prepare("UPDATE admin_users SET failed_login_attempts = failed_login_attempts + 1, last_failed_attempt = NOW() WHERE username = ?");
                    $update_stmt->bind_param("s", $username);
                    $update_stmt->execute();
                    $update_stmt->close();
                    $error_message = "Usuario o contraseña incorrectos.";
                }
            }
        } else {
            $error_message = "Usuario o contraseña incorrectos.";
        }
        $stmt->close();
        $conn->close();
    } else {
        $error_message = "Error: No se pudo conectar a la base de datos.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Banano Store - Admin Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #F59E0B; --primary-hover: #D97706; --dark-bg: #111827;
            --dark-card: #1F2937; --light-text: #F9FAFB; --secondary-text-dark: #9CA3AF;
            --light-bg: #F3F4F6; --light-card: #FFFFFF; --dark-text: #111827; --secondary-text-light: #6B7280;
        }
        body { transition: background-color 0.3s ease, color 0.3s ease; }
        .input-icon { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); transition: color 0.3s; }
        .password-toggle { position: absolute; right: 1rem; top: 50%; transform: translateY(-50%); cursor: pointer; transition: color 0.3s; }
        
        /* Tema Claro */
        body[data-theme="light"] { background-color: var(--light-bg); color: var(--dark-text); }
        body[data-theme="light"] .login-card { background-color: var(--light-card); }
        body[data-theme="light"] .input-field { background-color: #F9FAFB; border-color: #D1D5DB; color: var(--dark-text); }
        body[data-theme="light"] .input-field:focus { border-color: var(--primary); box-shadow: 0 0 0 1px var(--primary); }
        body[data-theme="light"] .input-icon, body[data-theme="light"] .password-toggle { color: #9CA3AF; }
        body[data-theme="light"] .text-secondary { color: var(--secondary-text-light); }
        body[data-theme="light"] #theme-toggle:hover { background-color: #E5E7EB; }

        /* Tema Oscuro */
        body[data-theme="dark"] { background-color: var(--dark-bg); color: var(--light-text); }
        body[data-theme="dark"] .login-card { background-color: var(--dark-card); }
        body[data-theme="dark"] .input-field { background-color: #374151; border-color: #4B5563; color: var(--light-text); }
        body[data-theme="dark"] .input-field:focus { border-color: var(--primary); box-shadow: 0 0 0 1px var(--primary); }
        body[data-theme="dark"] .input-icon, body[data-theme="dark"] .password-toggle { color: #9CA3AF; }
        body[data-theme="dark"] .text-secondary { color: var(--secondary-text-dark); }
        body[data-theme="dark"] #theme-toggle:hover { background-color: #374151; }

        /* ✨ Mejora: Animación sutil de "flote" */
        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-8px); }
            100% { transform: translateY(0px); }
        }
        .animated-float { animation: float 6s ease-in-out infinite; }
    </style>
</head>
<body data-theme="dark" class="flex items-center justify-center min-h-screen p-4">
    <div class="login-card w-full max-w-sm p-8 rounded-2xl shadow-2xl shadow-black/20 animated-float">
        <!-- Header -->
        <div class="flex justify-between items-start mb-8">
            <div>
                <h1 class="text-3xl font-bold" style="color: var(--primary);">Banano Store</h1>
                <p class="text-sm text-secondary">Admin Panel</p>
            </div>
            <button id="theme-toggle" class="p-2 rounded-full transition-colors duration-300">
                <i class="fas fa-moon text-yellow-400 text-lg" id="theme-icon"></i>
            </button>
        </div>

        <!-- Formulario -->
        <div>
            <h2 class="text-2xl font-bold mb-2">Bienvenido</h2>
            <p class="text-secondary mb-6">Ingresa tus credenciales para continuar.</p>

            <!-- Mensaje de Error (Controlado por PHP) -->
            <div id="error-message" class="bg-red-500/10 border border-red-500/30 text-red-400 px-4 py-3 mb-4 rounded-lg <?php echo empty($error_message) ? 'hidden' : ''; ?>">
                <p class="text-sm font-semibold"><?php echo htmlspecialchars($error_message); ?></p>
            </div>

            <form method="post" action="login.php" class="space-y-6">
                <!-- Campo de Usuario (Corregido) -->
                <div>
                    <label for="username" class="block text-sm font-medium mb-2 text-secondary">Usuario</label>
                    <div class="relative">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text" id="username" name="username" class="input-field w-full pl-12 pr-4 py-3 border rounded-lg focus:outline-none transition duration-300" placeholder="tu-usuario" required>
                    </div>
                </div>

                <!-- Campo de Contraseña (Corregido) -->
                <div>
                    <label for="password" class="block text-sm font-medium mb-2 text-secondary">Contraseña</label>
                    <div class="relative">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" id="password" name="password" class="input-field w-full pl-12 pr-12 py-3 border rounded-lg focus:outline-none transition duration-300" placeholder="••••••••" required>
                        <i class="fas fa-eye password-toggle" id="toggle-password"></i>
                    </div>
                </div>

                <!-- Botón de Enviar (Mejorado) -->
                <button type="submit" class="w-full text-white font-bold py-3 px-4 rounded-lg transition-all duration-300 transform active:scale-95" style="background-color: var(--primary);">
                    Iniciar Sesión
                </button>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const themeToggle = document.getElementById('theme-toggle');
            const themeIcon = document.getElementById('theme-icon');
            const body = document.body;

            function applyTheme(theme) {
                if (theme === 'light') {
                    body.setAttribute('data-theme', 'light');
                    themeIcon.classList.replace('fa-moon', 'fa-sun');
                    themeIcon.style.color = '#F59E0B';
                } else {
                    body.setAttribute('data-theme', 'dark');
                    themeIcon.classList.replace('fa-sun', 'fa-moon');
                    themeIcon.style.color = '#FBBF24';
                }
            }
            applyTheme(localStorage.getItem('theme') || 'dark');

            themeToggle.addEventListener('click', () => {
                const newTheme = body.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                applyTheme(newTheme);
                localStorage.setItem('theme', newTheme);
            });

            const passwordInput = document.getElementById('password');
            const togglePassword = document.getElementById('toggle-password');
            if(togglePassword) {
                togglePassword.addEventListener('click', () => {
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);
                    togglePassword.classList.toggle('fa-eye');
                    togglePassword.classList.toggle('fa-eye-slash');
                });
            }

            const inputs = document.querySelectorAll('.input-field');
            inputs.forEach(input => {
                const icon = input.parentElement.querySelector('.input-icon');
                if (icon) {
                    input.addEventListener('focus', () => icon.style.color = 'var(--primary)');
                    input.addEventListener('blur', () => icon.style.color = '');
                }
            });
        });
    </script>
</body>
</html>