<?php declare(strict_types=1);

/**
 * Three-route mock backend used by the fiber-await smoke test.
 * Sleeps for 100ms then responds with a JSON envelope identifying
 * which "service" served the call. Boot three of these on
 * different ports to simulate independent upstreams:
 *
 *   php -S 127.0.0.1:8101 bench/fiber/backend.php &
 *   php -S 127.0.0.1:8102 bench/fiber/backend.php &
 *   php -S 127.0.0.1:8103 bench/fiber/backend.php &
 *
 * Each sleep is wall-clock cost the client must overlap if it's
 * going to win. Sequential: ~300ms total; parallel: ~100ms total.
 */

$port = (int) ($_SERVER['SERVER_PORT'] ?? 0);
usleep(100_000);   // 100ms
header('Content-Type: application/json');
echo json_encode(['port' => $port, 'path' => $_SERVER['REQUEST_URI'] ?? '/']);
