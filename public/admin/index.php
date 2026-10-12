<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

try {
    (new Placard\Admin\App())->run();
} catch (Throwable $e) {
    error_log('admin: ' . $e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "Something went wrong. Check the server error log.\n";
}
