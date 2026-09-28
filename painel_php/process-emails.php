<?php
declare(strict_types=1);
// Compatibility stub: replaces the old worker when updating an existing installation.
// Intentionally does not load configuration, access the database or send messages.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
echo "O envio agendado foi desativado. Os avisos sao enviados ao salvar.\n";
