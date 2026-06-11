<?php
// Fuzzing script for siakad index.php

require_once 'index.php';

// --- Mocks & Helpers ---

function setup_session(array $user_data = null): void {
    $_SESSION = [
        'csrf' => bin2hex(random_bytes(16)),
        'trace_id' => bin2hex(random_bytes(8)),
    ];
    if ($user_data) {
        $_SESSION['user'] = $user_data;
    }
}

function mock_request(string $method, array $get = [], array $post = []): void {
    $_SERVER['REQUEST_METHOD'] = strtoupper($method);
    $_GET = $get;
    $_POST = $post;
    if ($method === 'POST') {
        $_POST['csrf'] = $_SESSION['csrf'];
    }
}

function get_functions_from_file(string $file): array {
    $content = file_get_contents($file);
    preg_match_all('/function\s+(handle_\w+)/', $content, $matches);
    return $matches[1];
}

function fuzz_string(int $length = 10): string {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789/..\\\'"`<>&!@#$%^&*()_+-=[]{}|;:,.<>?';
    $str = '';
    for ($i = 0; $i < $length; $i++) {
        $str .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $str;
}

function fuzz_integer(int $min = -1000, int $max = 1000): int {
    return random_int($min, $max);
}

// --- Fuzzing Logic ---

$handlers = get_functions_from_file('index.php');
$roles = ['admin', 'dosen', 'mahasiswa', null];

foreach ($handlers as $handler) {
    echo "Fuzzing: $handler\n";
    
    foreach ($roles as $role) {
        $user_data = $role ? ['role' => $role, 'linked_id' => 1, 'name' => 'Fuzz User'] : null;
        setup_session($user_data);

        for ($i = 0; $i < 10; $i++) { // 10 iterations per handler/role
            $get_params = ['page' => str_replace('handle_', '', $handler)];
            $post_params = [];

            // Generate random params
            for ($j = 0; $j < 5; $j++) {
                $get_params[fuzz_string(5)] = fuzz_string(20);
                $post_params[fuzz_string(5)] = fuzz_string(20);
                $get_params['id'] = fuzz_integer();
                $post_params['id'] = fuzz_integer();
            }

            // GET request
            mock_request('GET', $get_params);
            try {
                ob_start();
                $handler();
                ob_end_clean();
            } catch (Throwable $e) {
                if (strpos($e->getMessage(), 'Cannot modify header information') === false) {
                     echo "  - GET with role " . ($role ?? 'none') . ": " . get_class($e) . " -> " . $e->getMessage() . "\n";
                }
            }

            // POST request
            mock_request('POST', $get_params, $post_params);
             try {
                ob_start();
                $handler();
                ob_end_clean();
            } catch (Throwable $e) {
                 if (strpos($e->getMessage(), 'Cannot modify header information') === false) {
                    echo "  - POST with role " . ($role ?? 'none') . ": " . get_class($e) . " -> " . $e->getMessage() . "\n";
                }
            }
        }
    }
}

echo "Fuzzing complete.\n";
