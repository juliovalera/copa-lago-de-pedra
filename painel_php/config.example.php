<?php
declare(strict_types=1);

// Copie para config.php. Nunca publique o arquivo preenchido.
return [
    'database' => __DIR__ . '/data/copa.sqlite',
    'backup_directory' => __DIR__ . '/data/backups',
    'timezone' => 'America/Sao_Paulo',
    'admin_password' => getenv('COPA_ADMIN_PASSWORD') ?: '',
    'base_url' => getenv('COPA_BASE_URL') ?: 'http://localhost:8080',
    // Em branco: não envia cópias ao organizador. Avisos aos jogadores são independentes.
    'notification_email' => getenv('COPA_NOTIFY_EMAIL') ?: '',
    'smtp' => [
        'host' => getenv('COPA_SMTP_HOST') ?: '',
        'port' => (int) (getenv('COPA_SMTP_PORT') ?: 587),
        'encryption' => getenv('COPA_SMTP_ENCRYPTION') ?: 'tls',
        'username' => getenv('COPA_SMTP_USERNAME') ?: '',
        'password' => getenv('COPA_SMTP_PASSWORD') ?: '',
        'from_email' => getenv('COPA_SMTP_FROM_EMAIL') ?: '',
        'from_name' => getenv('COPA_SMTP_FROM_NAME') ?: 'Copa Lago de Pedra',
    ],
];
