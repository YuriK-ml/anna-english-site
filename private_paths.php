<?php
declare(strict_types=1);

/**
 * Shared private storage for Anna English AI.
 * Supports the old Beget layout and the new KorobkovHub layout on Fornex.
 */
function anna_private_dir(): string {
    $env = trim((string)getenv('ANNA_PRIVATE_DIR'));
    if ($env !== '') return rtrim($env, '/\\');

    // Legacy: private is next to the Anna document root.
    $legacy = dirname(__DIR__) . '/private';
    if (is_dir($legacy)) return $legacy;

    // Fornex: /home/k143221/private, two levels above public_html/anna.
    $shared = dirname(__DIR__, 2) . '/private';
    if (is_dir($shared)) return $shared;

    // Local fallback; the directory is created only when needed.
    return $legacy;
}
