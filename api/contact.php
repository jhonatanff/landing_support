<?php
require_once __DIR__ . '/config.php';

// ─── CORS Headers ───
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: ' . CORS_ORIGIN);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

// ─── Input & Validación ───
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$nombre   = trim($input['nombre']   ?? '');
$empresa  = trim($input['empresa']  ?? '');
$email    = trim($input['email']    ?? '');
$telefono = trim($input['telefono'] ?? '');
$servicio = trim($input['servicio'] ?? '');
$mensaje  = trim($input['mensaje']  ?? '');

if (empty($nombre) || empty($email)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Nombre y email son obligatorios']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Email inválido']);
    exit;
}

// ─── Base de Datos SQLite ───
try {
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Crear tabla si no existe
    $pdo->exec("CREATE TABLE IF NOT EXISTS contacts (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        nombre    TEXT NOT NULL,
        empresa   TEXT,
        email     TEXT NOT NULL,
        telefono  TEXT,
        servicio  TEXT,
        mensaje   TEXT,
        ip        TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        deleted_at DATETIME DEFAULT NULL
    )");

    // Insertar registro
    $stmt = $pdo->prepare("INSERT INTO contacts 
        (nombre, empresa, email, telefono, servicio, mensaje, ip) 
        VALUES (:nombre, :empresa, :email, :telefono, :servicio, :mensaje, :ip)");

    $stmt->execute([
        ':nombre'   => $nombre,
        ':empresa'  => $empresa,
        ':email'    => $email,
        ':telefono' => $telefono,
        ':servicio' => $servicio,
        ':mensaje'  => $mensaje,
        ':ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    $insertedId = $pdo->lastInsertId();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al guardar: ' . $e->getMessage()]);
    exit;
}

// ─── Notificación WhatsApp (Ultramsg) ───
$waStatus = 'disabled';

if (WA_ENABLED && ULTRAMSG_INSTANCE !== 'instance12345' && ULTRAMSG_TOKEN !== 'tu_token_aqui') {
    $servicioLabels = [
        'software'  => '💻 Mantenimiento de Software',
        'hardware'  => '🖥️ Mantenimiento de Hardware',
        'seguridad' => '🛡️ Ciberseguridad',
        'nube'      => '☁️ Gestión en la Nube',
        'redes'     => '🌐 Redes & Conectividad',
        'monitoreo' => '📊 Monitoreo & Analítica',
    ];
    $servicioLabel = $servicioLabels[$servicio] ?? $servicio;

    $now = (new DateTime('now', new DateTimeZone('America/Mexico_City')))->format('d/m/Y H:i');

    $text = "🚀 *NUEVO LEAD - NexCore Tech*\n\n"
          . "👤 *Nombre:* {$nombre}\n"
          . "🏢 *Empresa:* " . ($empresa ?: 'No indicada') . "\n"
          . "📧 *Email:* {$email}\n"
          . "📱 *Teléfono:* " . ($telefono ?: 'No indicado') . "\n"
          . "💬 *Chat directo:* https://wa.me/" . preg_replace('/[^0-9]/', '', $telefono) . "\n"
          . "⚙️ *Servicio:* {$servicioLabel}\n"
          . "💬 *Mensaje:* " . (mb_strlen($mensaje) > 150 ? mb_substr($mensaje, 0, 150) . '...' : ($mensaje ?: 'Sin mensaje')) . "\n"
          . "🕐 *Fecha:* {$now}\n"
          . "🆔 *Lead #:* {$insertedId}";

    // Mantener el número destino intacto (Ultramsg puede necesitar el +)
    $toPhone = WA_DESTINATION;

    $url = "https://api.ultramsg.com/" . ULTRAMSG_INSTANCE . "/messages/chat";
    $postData = http_build_query([
        'token' => ULTRAMSG_TOKEN,
        'to'    => $toPhone,
        'body'  => $text
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    
    $response   = curl_exec($ch);
    $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError  = curl_error($ch);
    curl_close($ch);

    $waStatus = ($httpCode === 200 && empty($curlError)) ? 'sent' : 'error';
}

// ─── Respuesta ───
echo json_encode([
    'ok'        => true,
    'id'        => (int) $insertedId,
    'whatsapp'  => $waStatus,
    'message'   => '¡Gracias! Tu solicitud fue recibida. Te contactamos pronto.',
]);
