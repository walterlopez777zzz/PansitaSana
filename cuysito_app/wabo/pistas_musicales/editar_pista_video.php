<?php
// La lógica PHP de esta página se ejecuta ANTES de enviar cualquier HTML
require_once __DIR__ . '/../../api/session_security.php';
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) { header('Location: login.php'); exit; }
require_once __DIR__ . '/../core/db_connect.php';

function getYouTubeIdFromUrl($url) {
    $pattern = '/(?:https?:\/\/)?(?:www\.)?(?:(?:youtu\.be\/)|(?:youtube\.com\/(?:(?:watch\?v=)|(?:embed\/)|(?:v\/))))([a-zA-Z0-9_-]{11})/';
    preg_match($pattern, $url, $matches);
    return (isset($matches[1])) ? $matches[1] : $url;
}

if (!isset($_GET['track_name']) || !isset($_GET['artwork'])) { header('Location: gestionar_pistas.php'); exit; }

$track_name = $_GET['track_name'];
$artwork_url = $_GET['artwork'];
$video_data = ['youtube_id' => '', 'start_time' => 0];
$is_new_entry = true;

$stmt = $conn->prepare("SELECT youtube_id, start_time FROM pistas_musicales WHERE nombre_pista = ?");
$stmt->bind_param("s", $track_name);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $video_data = $result->fetch_assoc();
    $is_new_entry = false;
}
$stmt->close();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $youtube_id = getYouTubeIdFromUrl(trim($_POST['youtube_id']));
    $start_time = (int)$_POST['start_time'];
    if ($is_new_entry) {
        $stmt_insert = $conn->prepare("INSERT INTO pistas_musicales (nombre_pista, youtube_id, start_time) VALUES (?, ?, ?)");
        $stmt_insert->bind_param("ssi", $track_name, $youtube_id, $start_time);
        $stmt_insert->execute();
        $stmt_insert->close();
    } else {
        $stmt_update = $conn->prepare("UPDATE pistas_musicales SET youtube_id = ?, start_time = ? WHERE nombre_pista = ?");
        $stmt_update->bind_param("sis", $youtube_id, $start_time, $track_name);
        $stmt_update->execute();
        $stmt_update->close();
    }
    $conn->close();
    header('Location: gestionar_pistas.php');
    exit;
}
// Incluimos la cabecera ahora que tenemos los datos listos para el formulario
require_once 'admin_header.php';
?>

<div class="page-header">
    <h2><?php echo $is_new_entry ? 'Añadir Video a' : 'Editando Video de'; ?>: <?php echo htmlspecialchars($track_name); ?></h2>
</div>

<div style="display: flex; align-items: center; gap: 1.5rem; margin-bottom: 2rem; background-color: var(--color-card); padding: 1rem; border-radius: 8px;">
    <img src="<?php echo htmlspecialchars($artwork_url); ?>" alt="Artwork" style="width: 100px; height: 100px; border-radius: 8px;">
    <h3><?php echo htmlspecialchars($track_name); ?></h3>
</div>

<form method="post">
    <label for="youtube_id">
        ID o Enlace completo de YouTube
        <input type="text" id="youtube_id" name="youtube_id" value="<?php echo htmlspecialchars($video_data['youtube_id']); ?>" placeholder="Pega el ID o la URL completa aquí" required>
    </label>
    <label for="start_time">
        Segundo de Inicio (opcional)
        <input type="number" id="start_time" name="start_time" value="<?php echo $video_data['start_time']; ?>" placeholder="0">
    </label>
    <button type="submit">Guardar Video</button>
    <a href="gestionar_pistas.php" role="button" class="secondary" style="margin-left: 1rem;">Cancelar</a>
</form>

<?php include 'admin_footer.php'; ?>