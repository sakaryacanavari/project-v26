<?php

require dirname(__DIR__) . '/bootstrap.php';

echo json_encode(\App\System\HealthCheck::report(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
