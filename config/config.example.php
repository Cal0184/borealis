<?php
// Copy to config/config.php or run the web installer (it writes this file for you).
return [
    'app' => [
        'name' => 'Borealis',
        // Public URL without trailing slash, e.g. https://borealis.example.com
        'url' => 'http://localhost:8080',
        // true when the web server rewrites unknown paths to public/index.php (.htaccess included)
        'pretty_urls' => true,
        'debug' => false,
        'timezone' => 'America/Santiago',
        'allow_registration' => false,
        // 1.4.1: true lets any signed-in person create workspaces. Off: only the installation's administration.
        'allow_workspace_creation' => false,
        // 1.16.4 (optional): only if Borealis is behind a proxy or CDN. Its address(es), or ['*'] when the
        // server can only be reached through one proxy. Then the sign-in limits use each person's real IP.
        // 'trusted_proxies' => ['10.0.0.5'],
    ],
    'db' => [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'borealis',
        'username' => 'borealis',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'uploads' => [
        'max_bytes' => 10 * 1024 * 1024,
        'allowed_extensions' => ['png','jpg','jpeg','gif','webp','pdf','txt','md','csv','doc','docx','xls','xlsx','ppt','pptx','odt','ods','odp','zip','json'],
    ],
    // 1.6 (optional): Google Calendar and Google Tasks. Create a «Web application» OAuth client in
    // Google Cloud (README › Google Calendar y Google Tasks). Leave it out to keep Google disabled.
    // 1.9: the same client also sends tasks to Google Calendar (no new permission). Exact steps:
    // docs/CONFIGURACION-1.9.md › Google.
    // 'google' => [
    //     'client_id' => '',
    //     'client_secret' => '',
    // ],
    // 1.8 (optional): mobile notifications. Keys are created by themselves in storage/vapid.json;
    // 'subject' is a contact for the push services (mailto: or https:).
    // 'push' => ['subject' => 'mailto:soporte@tu-dominio.cl'],
    // 1.15 (optional): the internal notification engine keeps reminders and phone notices going
    // without cron and without anybody having Borealis open (1.16: PHP-FPM, LiteSpeed or Apache).
    // It stops by itself while a real cron job runs. Defaults shown; nothing to set normally.
    // 'cron' => [
    //     'heartbeat' => true,            // false turns the engine off
    //     'heartbeat_seconds' => 60,      // time between beats (1–300)
    //     'heartbeat_url' => '',          // only if the server reaches itself by another address
    //     'web_fallback' => true,         // false: visits and /cron/ping do not start the job
    // ],
    // 1.16.3 (optional): «Correo al soporte» for the whole installation. Administración › Ajustes wins per workspace.
    // 'support' => ['email' => 'soporte@example.cl'],
    // 1.9 (optional): e-mail notices. Leave it out and Borealis sends no e-mail (and says so).
    // Use a real mailbox of your domain (HestiaCP › Mail). Port 587 = STARTTLS, 465 = SSL.
    // The password can also go in the BOREALIS_SMTP_PASSWORD environment variable instead of here.
    // 'mail' => [
    //     'transport' => 'smtp',              // 'smtp' | 'sendmail' (PHP mail(), the server's Exim)
    //     'host' => 'mail.tu-dominio.cl',
    //     'port' => 587,
    //     'encryption' => 'tls',              // 'tls' (STARTTLS) | 'ssl' | 'none' (only localhost)
    //     'username' => 'borealis@tu-dominio.cl',
    //     'password' => '',
    //     'from_address' => 'borealis@tu-dominio.cl',
    //     'from_name' => 'Borealis',
    // ],
    'session' => [
        'name' => 'borealis_session',
        'idle_minutes' => 240,
    ],
];
