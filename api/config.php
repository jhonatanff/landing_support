<?php
/**
 * NexCore Tech — Configuración del Backend
 * ─────────────────────────────────────────
 * 1. Regístra tu número en CallMeBot:
 *    - Agrega el contacto de WhatsApp: +34 644 62 93 41 (CallMeBot)
 *    - Envíale el mensaje: "I allow callmebot to send me messages"
 *    - Recibirás tu API KEY por WhatsApp en segundos.
 *
 * 2. Rellena los campos de abajo con tus datos reales.
 */

// ── Notificaciones de WhatsApp (vía Ultramsg) ──────────────────────────────
// 1. Ve a https://ultramsg.com y crea una cuenta gratuita.
// 2. Escanea el código QR con el WhatsApp que va a ENVIAR los mensajes.
// 3. Copia aquí tu Instance ID y tu Token.
define('ULTRAMSG_INSTANCE', 'instance180613'); 
define('ULTRAMSG_TOKEN',    'q1nnuukewzcj5hqc'); 
define('WA_DESTINATION',    '+573226590659'); // Tu número destino (el que RECIBE el aviso)
define('WA_ENABLED',        true);           // Activado

define('DB_PATH', __DIR__ . '/db/contacts.db');
define('CORS_ORIGIN', '*');              

// ── Credenciales del panel admin ──────────────────────────────────────────────
define('ADMIN_USER', 'admin');           
define('ADMIN_PASS', 'nexcore2026');     
define('SESSION_NAME', 'nexcore_admin');
//  dominio//PvE9ZkjYKaJFs