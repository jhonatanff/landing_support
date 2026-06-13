<?php
require_once __DIR__ . '/config.php';

session_name(SESSION_NAME);
session_start();

// ── Logout ────────────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: contacts.php');
    exit;
}

// ── Procesar login ────────────────────────────────────────────────────────────
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_login'])) {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    if ($user === ADMIN_USER && $pass === ADMIN_PASS) {
        $_SESSION['admin_auth'] = true;
        $_SESSION['admin_user'] = $user;
        header('Location: contacts.php');
        exit;
    }
    $loginError = 'Usuario o contraseña incorrectos.';
}

$isAuth = !empty($_SESSION['admin_auth']);

// ── Datos del panel (solo si autenticado) ────────────────────────────────────
$search     = trim($_GET['q']        ?? '');
$servicio   = trim($_GET['servicio'] ?? '');
$fecha      = trim($_GET['fecha']    ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$rows       = [];
$totalRows  = 0;
$totalPages = 1;
$pdo        = null;
$stHoy      = 0;
$stSem      = 0;
$editRow    = null;

if ($isAuth) {
    try {
        $dbDir = dirname(DB_PATH);
        if (!is_dir($dbDir)) mkdir($dbDir, 0755, true);

        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec("CREATE TABLE IF NOT EXISTS contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT NOT NULL, empresa TEXT, email TEXT NOT NULL,
            telefono TEXT, servicio TEXT, mensaje TEXT, ip TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME DEFAULT NULL
        )");
        
        // Si la tabla ya existía antes, le agregamos la columna nueva (ignora si ya existe)
        try {
            $pdo->exec("ALTER TABLE contacts ADD COLUMN deleted_at DATETIME DEFAULT NULL");
        } catch (Exception $e) {}

        // ── Eliminar (Soft Delete) ──
        if (isset($_GET['delete'])) {
            $stmt = $pdo->prepare("UPDATE contacts SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([(int)$_GET['delete']]);
            header("Location: contacts.php");
            exit;
        }

        // ── Guardar Edición ──
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_edit'])) {
            $stmt = $pdo->prepare("UPDATE contacts SET nombre=?, empresa=?, email=?, telefono=?, servicio=?, mensaje=? WHERE id=?");
            $stmt->execute([
                trim($_POST['nombre']),
                trim($_POST['empresa']),
                trim($_POST['email']),
                trim($_POST['telefono']),
                trim($_POST['servicio']),
                trim($_POST['mensaje']),
                (int)$_POST['id']
            ]);
            header("Location: contacts.php");
            exit;
        }

        // ── Cargar para Editar ──
        if (isset($_GET['edit'])) {
            $stmt = $pdo->prepare("SELECT * FROM contacts WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([(int)$_GET['edit']]);
            $editRow = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // ── Filtros y paginación ──
        $limit  = 20;
        $offset = ($page - 1) * $limit;
        $where  = ["deleted_at IS NULL"]; // Solo traer los NO eliminados
        $params = [];
        if ($search) {
            $where[]      = "(nombre LIKE :q OR empresa LIKE :q OR email LIKE :q)";
            $params[':q'] = "%{$search}%";
        }
        if ($servicio) {
            $where[]             = "servicio = :servicio";
            $params[':servicio'] = $servicio;
        }
        if ($fecha) {
            $where[]          = "DATE(created_at) = :fecha";
            $params[':fecha'] = $fecha;
        }
        $whereSQL  = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $totalRows = (int)$pdo->prepare("SELECT COUNT(*) FROM contacts {$whereSQL}")
                              ->execute($params) + 0;
        
        $totalPages = max(1, (int)ceil($totalRows / $limit));

        $stmt = $pdo->prepare("SELECT * FROM contacts {$whereSQL} ORDER BY id DESC LIMIT :limit OFFSET :offset");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ── Estadísticas ──
        $hoy   = date('Y-m-d');
        $stHoy = (int)$pdo->query("SELECT COUNT(*) FROM contacts WHERE deleted_at IS NULL AND DATE(created_at)='{$hoy}'")->fetchColumn();
        $stSem = (int)$pdo->query("SELECT COUNT(*) FROM contacts WHERE deleted_at IS NULL AND created_at >= datetime('now','-7 days')")->fetchColumn();

    } catch (Exception $e) {
        $dbError = $e->getMessage();
    }
}

$servicioLabels = [
    'software'  => '💻 Software',
    'hardware'  => '🖥️ Hardware',
    'seguridad' => '🛡️ Ciberseguridad',
    'nube'      => '☁️ Cloud',
    'redes'     => '🌐 Redes',
    'monitoreo' => '📊 Monitoreo',
];
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>NexCore Admin <?= $isAuth ? '— Panel de Leads' : '— Acceso' ?></title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet"/>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#050816;--card:#0a0f2e;--glass:rgba(10,15,46,0.75);
  --border:rgba(0,212,255,.15);--border-hover:rgba(0,212,255,.4);
  --primary:#00d4ff;--secondary:#7c3aed;--accent:#f0abfc;
  --text:#e2e8f0;--muted:#64748b;
  --danger:#ef4444;--success:#10b981;
  --glow-c:0 0 30px rgba(0,212,255,.35);
  --glow-p:0 0 30px rgba(124,58,237,.35);
}
html{height:100%}
body{font-family:'Outfit',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}

/* ─ PARTICLES ─ */
#bg-canvas{position:fixed;inset:0;z-index:0;pointer-events:none;}

/* ─ LOGIN PAGE ─ */
.login-wrap{
  position:relative;z-index:1;
  min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem;
}
.login-box{
  width:100%;max-width:440px;
  background:var(--glass);border:1px solid var(--border);
  border-radius:20px;padding:3rem 2.5rem;
  backdrop-filter:blur(24px);
  box-shadow:var(--glow-c);
  animation:fadeUp .7s ease both;
}
@keyframes fadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}

.login-logo{display:flex;align-items:center;justify-content:center;gap:.7rem;margin-bottom:2rem;}
.logo-icon{
  width:48px;height:48px;border-radius:12px;
  background:linear-gradient(135deg,var(--primary),var(--secondary));
  display:flex;align-items:center;justify-content:center;
  font-size:1.5rem;box-shadow:var(--glow-c);
}
.login-logo span{font-size:1.6rem;font-weight:900;color:var(--primary);}
.login-logo span b{color:var(--text);}

.login-title{text-align:center;font-size:1.3rem;font-weight:700;margin-bottom:.4rem;}
.login-sub{text-align:center;color:var(--muted);font-size:.9rem;margin-bottom:2rem;}

.form-group{display:flex;flex-direction:column;gap:.45rem;margin-bottom:1.2rem;}
.form-group label{font-size:.82rem;font-weight:600;color:var(--muted);letter-spacing:.5px;}
.input-wrap{position:relative;}
.input-wrap .icon{
  position:absolute;left:1rem;top:50%;transform:translateY(-50%);
  font-size:1rem;pointer-events:none;
}
.form-group input,.form-group select,.form-group textarea{
  width:100%;background:rgba(5,8,22,.7);border:1px solid var(--border);
  border-radius:10px;padding:.85rem 1rem;
  font-family:'Outfit',sans-serif;font-size:.95rem;color:var(--text);
  outline:none;transition:border-color .3s,box-shadow .3s;
}
.form-group input.with-icon{padding-left:2.8rem;}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{
  border-color:var(--primary);
  box-shadow:0 0 0 3px rgba(0,212,255,.12);
}

.btn-primary{
  width:100%;padding:1rem;border-radius:50px;
  background:linear-gradient(135deg,var(--primary),var(--secondary));
  color:#fff;border:none;font-family:'Outfit',sans-serif;
  font-weight:700;font-size:1rem;cursor:pointer;
  box-shadow:var(--glow-c);transition:all .3s;
  margin-top:.4rem;text-align:center;text-decoration:none;display:inline-block;
}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 0 45px rgba(0,212,255,.5);}

.login-error{
  display:flex;align-items:center;gap:.6rem;
  background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);
  border-radius:10px;padding:.8rem 1rem;color:#ef4444;
  font-size:.88rem;margin-bottom:1.2rem;animation:fadeUp .3s ease;
}
.back-link{
  display:block;text-align:center;margin-top:1.5rem;
  color:var(--muted);font-size:.85rem;text-decoration:none;transition:.3s;
}
.back-link:hover{color:var(--primary);}

/* ─ PANEL ─ */
.panel-wrap{position:relative;z-index:1;}
header{
  background:rgba(10,15,46,.9);border-bottom:1px solid var(--border);
  padding:1rem 2rem;display:flex;align-items:center;justify-content:space-between;
  backdrop-filter:blur(20px);position:sticky;top:0;z-index:10;
}
.header-left{display:flex;align-items:center;gap:.8rem;}
.header-left .logo-sm{
  width:34px;height:34px;border-radius:9px;
  background:linear-gradient(135deg,var(--primary),var(--secondary));
  display:flex;align-items:center;justify-content:center;font-size:1rem;
  box-shadow:var(--glow-c);
}
header h1{font-size:1.15rem;font-weight:800;color:var(--primary);}
.header-right{display:flex;align-items:center;gap:1rem;}
.admin-badge{
  display:flex;align-items:center;gap:.5rem;
  background:rgba(0,212,255,.08);border:1px solid var(--border);
  border-radius:50px;padding:.35rem .9rem;font-size:.82rem;color:var(--muted);
}
.admin-badge span{color:var(--text);font-weight:600;}
.btn-logout{
  background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);
  color:#ef4444;border-radius:50px;padding:.35rem 1rem;
  font-family:'Outfit',sans-serif;font-size:.82rem;font-weight:600;
  cursor:pointer;text-decoration:none;transition:.3s;
}
.btn-logout:hover{background:rgba(239,68,68,.2);}

.container{padding:2rem;}

/* Stats */
.stats{display:flex;gap:1rem;margin-bottom:2rem;flex-wrap:wrap;}
.stat-box{
  background:var(--glass);border:1px solid var(--border);border-radius:14px;
  padding:1.2rem 1.8rem;flex:1;min-width:140px;backdrop-filter:blur(20px);
  transition:.3s;
}
.stat-box:hover{border-color:var(--border-hover);}
.stat-box .s-label{font-size:.75rem;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:.4rem;}
.stat-box .s-value{font-size:2.2rem;font-weight:900;background:linear-gradient(135deg,var(--primary),var(--accent));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;line-height:1;}

/* Filters */
.filters{display:flex;gap:.8rem;margin-bottom:1.5rem;flex-wrap:wrap;align-items:center;}
.filters input,.filters select{
  background:var(--glass);border:1px solid var(--border);border-radius:10px;
  padding:.6rem 1rem;color:var(--text);font-family:'Outfit',sans-serif;font-size:.88rem;
  outline:none;transition:.3s;backdrop-filter:blur(10px);
}
.filters input:focus,.filters select:focus{border-color:var(--primary);}
.filters input[type="search"]{flex:2;min-width:200px;}
.filters input[type="date"]{min-width:140px; color-scheme: dark;}
.filters select{min-width:170px;}
.btn-filter{
  background:linear-gradient(135deg,var(--primary),var(--secondary));
  color:#fff;border:none;border-radius:10px;padding:.6rem 1.4rem;
  font-family:'Outfit',sans-serif;font-weight:700;font-size:.88rem;cursor:pointer;transition:.3s;
}
.btn-filter:hover{box-shadow:var(--glow-c);}
.btn-clear{
  padding:.6rem 1rem;border-radius:10px;border:1px solid var(--border);
  color:var(--muted);text-decoration:none;font-size:.85rem;transition:.3s;
}
.btn-clear:hover{border-color:var(--primary);color:var(--primary);}

/* Table */
.table-wrap{overflow-x:auto;border-radius:14px;border:1px solid var(--border);backdrop-filter:blur(10px);}
table{width:100%;border-collapse:collapse;background:var(--glass);}
thead{background:rgba(0,212,255,.05);}
th{
  padding:1rem 1.2rem;text-align:left;font-size:.72rem;font-weight:700;
  color:var(--primary);letter-spacing:1.5px;text-transform:uppercase;
  border-bottom:1px solid var(--border);white-space:nowrap;
}
td{padding:.85rem 1.2rem;font-size:.87rem;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:rgba(0,212,255,.03);}
.badge{
  display:inline-block;padding:.22rem .7rem;border-radius:50px;font-size:.72rem;font-weight:700;
  background:rgba(0,212,255,.1);border:1px solid rgba(0,212,255,.25);color:var(--primary);
  white-space:nowrap;
}
.email-link{color:var(--primary);text-decoration:none;}
.email-link:hover{text-decoration:underline;}
.msg-cell{max-width:200px;font-size:.82rem;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.date-cell{font-family:'JetBrains Mono',monospace;font-size:.76rem;color:var(--muted);white-space:nowrap;}
.id-cell{font-family:'JetBrains Mono',monospace;color:var(--muted);font-size:.8rem;}
.name-cell strong{display:block;}
.name-cell small{color:var(--muted);font-size:.78rem;}

.actions-cell{display:flex;gap:.5rem;}
.btn-icon{
  background:rgba(255,255,255,.05);border:1px solid var(--border);
  color:var(--text);border-radius:8px;padding:.4rem .6rem;
  font-size:.85rem;cursor:pointer;text-decoration:none;transition:.3s;
}
.btn-icon:hover{background:rgba(255,255,255,.1);border-color:var(--primary);}
.btn-icon.del:hover{background:rgba(239,68,68,.2);border-color:#ef4444;color:#ef4444;}

/* Empty */
.empty{text-align:center;padding:4rem;color:var(--muted);}
.empty .icon{font-size:3rem;margin-bottom:1rem;}

/* Pagination */
.pagination{display:flex;gap:.4rem;justify-content:center;margin-top:1.5rem;flex-wrap:wrap;}
.pagination a{
  padding:.45rem .9rem;border-radius:8px;background:var(--glass);border:1px solid var(--border);
  color:var(--muted);text-decoration:none;font-size:.85rem;transition:.3s;
}
.pagination a:hover,.pagination a.active{border-color:var(--primary);color:var(--primary);background:rgba(0,212,255,.06);}

/* Error */
.error-box{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:12px;padding:1rem 1.4rem;color:#ef4444;margin-bottom:1.5rem;font-size:.88rem;}

/* Edit Form */
.edit-wrap{
  background:var(--glass);border:1px solid var(--border);border-radius:14px;
  padding:2rem;margin-bottom:2rem;box-shadow:var(--glow-p);animation:fadeUp .4s ease;
}
.edit-wrap h2{margin-bottom:1.5rem;font-size:1.3rem;color:var(--primary);}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
@media(max-width:768px){.grid-2{grid-template-columns:1fr;}}
</style>
</head>
<body>

<canvas id="bg-canvas"></canvas>

<?php if (!$isAuth): ?>
<!-- ═══════════════════════════════ LOGIN ═══════════════════════════════ -->
<div class="login-wrap">
  <div class="login-box">
    <div class="login-logo">
      <div class="logo-icon">⚡</div>
      <span>Nex<b>Core</b></span>
    </div>
    <h1 class="login-title">Acceso Administrativo</h1>
    <p class="login-sub">Panel de gestión de leads y contactos</p>

    <?php if ($loginError): ?>
    <div class="login-error">⚠️ <?= htmlspecialchars($loginError) ?></div>
    <?php endif; ?>

    <form method="POST" action="contacts.php" autocomplete="off">
      <input type="hidden" name="_login" value="1"/>

      <div class="form-group">
        <label for="username">Usuario</label>
        <div class="input-wrap">
          <span class="icon">👤</span>
          <input type="text" id="username" name="username" class="with-icon" placeholder="Ingresa tu usuario" required autofocus/>
        </div>
      </div>

      <div class="form-group">
        <label for="password">Contraseña</label>
        <div class="input-wrap">
          <span class="icon">🔒</span>
          <input type="password" id="password" name="password" class="with-icon" placeholder="Ingresa tu contraseña" required/>
        </div>
      </div>

      <button type="submit" class="btn-primary">Ingresar al panel →</button>
    </form>

    <a href="../index.html" class="back-link">← Volver a la landing</a>
  </div>
</div>

<?php else: ?>
<!-- ═══════════════════════════════ PANEL ═══════════════════════════════ -->
<div class="panel-wrap">
  <header>
    <div class="header-left">
      <div class="logo-sm">⚡</div>
      <h1>NexCore — Panel de Leads</h1>
    </div>
    <div class="header-right">
      <div class="admin-badge">🔐 <span><?= htmlspecialchars($_SESSION['admin_user'] ?? 'admin') ?></span></div>
      <a href="?logout=1" class="btn-logout">Cerrar sesión</a>
    </div>
  </header>

  <div class="container">

    <?php if (isset($dbError)): ?>
    <div class="error-box">⚠️ Error de base de datos: <?= htmlspecialchars($dbError) ?></div>
    <?php endif; ?>

    <?php if ($editRow): ?>
    <!-- Formulario de Edición -->
    <div class="edit-wrap">
      <h2>✏️ Editar Lead #<?= $editRow['id'] ?></h2>
      <form method="POST" action="contacts.php">
        <input type="hidden" name="_edit" value="1"/>
        <input type="hidden" name="id" value="<?= $editRow['id'] ?>"/>
        
        <div class="grid-2">
          <div class="form-group">
            <label>Nombre completo</label>
            <input type="text" name="nombre" value="<?= htmlspecialchars($editRow['nombre']) ?>" required/>
          </div>
          <div class="form-group">
            <label>Empresa</label>
            <input type="text" name="empresa" value="<?= htmlspecialchars($editRow['empresa']??'') ?>"/>
          </div>
          <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" value="<?= htmlspecialchars($editRow['email']) ?>" required/>
          </div>
          <div class="form-group">
            <label>Teléfono</label>
            <input type="text" name="telefono" value="<?= htmlspecialchars($editRow['telefono']??'') ?>"/>
          </div>
          <div class="form-group">
            <label>Servicio de interés</label>
            <select name="servicio">
              <?php foreach($servicioLabels as $val => $label): ?>
              <option value="<?= $val ?>" <?= $editRow['servicio']===$val ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        
        <div class="form-group" style="margin-top:1rem;">
          <label>Mensaje</label>
          <textarea name="mensaje" rows="3"><?= htmlspecialchars($editRow['mensaje']??'') ?></textarea>
        </div>

        <div style="display:flex; gap:1rem;">
          <button type="submit" class="btn-primary" style="flex:1;">Guardar Cambios</button>
          <a href="contacts.php" class="btn-clear" style="margin-top:.4rem; text-align:center; padding:1rem; border-radius:50px;">Cancelar</a>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats">
      <div class="stat-box"><div class="s-label">Total Leads</div><div class="s-value"><?= $totalRows ?></div></div>
      <div class="stat-box"><div class="s-label">Hoy</div><div class="s-value"><?= $stHoy ?></div></div>
      <div class="stat-box"><div class="s-label">Últimos 7 días</div><div class="s-value"><?= $stSem ?></div></div>
    </div>

    <!-- Filtros -->
    <form class="filters" method="GET" action="contacts.php">
      <input type="search" name="q" placeholder="🔍 Buscar nombre, email, empresa..." value="<?= htmlspecialchars($search) ?>"/>
      <select name="servicio">
        <option value="">Todos los servicios</option>
        <?php foreach($servicioLabels as $val => $label): ?>
        <option value="<?= $val ?>" <?= $servicio === $val ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
      <input type="date" name="fecha" value="<?= htmlspecialchars($fecha) ?>" title="Filtrar por fecha"/>
      <button type="submit" class="btn-filter">Filtrar</button>
      <?php if ($search || $servicio || $fecha): ?>
      <a href="contacts.php" class="btn-clear">✕ Limpiar</a>
      <?php endif; ?>
    </form>

    <!-- Tabla -->
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>#</th><th>Nombre / Empresa</th><th>Email</th>
            <th>Teléfono</th><th>Servicio</th><th>Mensaje</th><th>Fecha</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
          <tr><td colspan="8">
            <div class="empty">
              <div class="icon">📭</div>
              <?= ($search || $servicio || $fecha) ? 'No hay resultados para esta búsqueda.' : 'Aún no hay leads. Cuando alguien llene el formulario aparecerá aquí.' ?>
            </div>
          </td></tr>
          <?php else: foreach($rows as $row): ?>
          <tr>
            <td class="id-cell">#<?= $row['id'] ?></td>
            <td class="name-cell">
              <strong><?= htmlspecialchars($row['nombre']) ?></strong>
              <?php if ($row['empresa']): ?><small><?= htmlspecialchars($row['empresa']) ?></small><?php endif; ?>
            </td>
            <td><a class="email-link" href="mailto:<?= htmlspecialchars($row['email']) ?>"><?= htmlspecialchars($row['email']) ?></a></td>
            <td><?= htmlspecialchars($row['telefono'] ?: '—') ?></td>
            <td><span class="badge"><?= htmlspecialchars($servicioLabels[$row['servicio']] ?? ($row['servicio'] ?: '—')) ?></span></td>
            <td class="msg-cell" title="<?= htmlspecialchars($row['mensaje']) ?>"><?= htmlspecialchars($row['mensaje'] ?: '—') ?></td>
            <td class="date-cell"><?= htmlspecialchars(substr($row['created_at'], 0, 16)) ?></td>
            <td>
              <div class="actions-cell">
                <a href="?edit=<?= $row['id'] ?>" class="btn-icon" title="Editar">✏️</a>
                <a href="?delete=<?= $row['id'] ?>" class="btn-icon del" title="Eliminar" onclick="return confirm('¿Estás seguro de eliminar este lead?');">🗑️</a>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Paginación -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
      <a href="?page=<?= $i ?>&q=<?= urlencode($search) ?>&servicio=<?= urlencode($servicio) ?>&fecha=<?= urlencode($fecha) ?>"
         class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>

  </div><!-- /container -->
</div><!-- /panel-wrap -->
<?php endif; ?>

<script>
// Partículas de fondo
const canvas = document.getElementById('bg-canvas');
const ctx    = canvas.getContext('2d');
function resize(){ canvas.width = innerWidth; canvas.height = innerHeight; }
resize(); window.addEventListener('resize', resize);

const particles = Array.from({length: 80}, () => ({
  x: Math.random() * innerWidth,
  y: Math.random() * innerHeight,
  size: Math.random() * 1.2 + 0.3,
  sx: (Math.random() - 0.5) * 0.35,
  sy: (Math.random() - 0.5) * 0.35,
  c: Math.random() > 0.5 ? '0,212,255' : '124,58,237',
  o: Math.random() * 0.4 + 0.1,
}));

function animate(){
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  particles.forEach(p => {
    p.x += p.sx; p.y += p.sy;
    if (p.x < 0 || p.x > canvas.width)  p.sx *= -1;
    if (p.y < 0 || p.y > canvas.height) p.sy *= -1;
    ctx.beginPath();
    ctx.arc(p.x, p.y, p.size, 0, Math.PI*2);
    ctx.fillStyle = `rgba(${p.c},${p.o})`;
    ctx.fill();
  });
  requestAnimationFrame(animate);
}
animate();

<?php if (!$isAuth): ?>
// Shake en error de login
<?php if ($loginError): ?>
const box = document.querySelector('.login-box');
box.style.animation = 'shake .4s ease';
const s = document.createElement('style');
s.textContent = '@keyframes shake{0%,100%{transform:translateX(0)}20%,60%{transform:translateX(-8px)}40%,80%{transform:translateX(8px)}}';
document.head.appendChild(s);
<?php endif; ?>
<?php endif; ?>
</script>
</body>
</html>
