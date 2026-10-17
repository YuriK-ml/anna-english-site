<?php

// Tutor Auth configuration (EXAMPLE)
// Place a real copy OUTSIDE public_html, e.g.:
//   /anna.korobkovhub.ru/private/tutor_auth_config.php
// and point to it via one of the supported mechanisms:
// - env var TUTOR_PRIVATE_CONFIG
// - or default lookup: DOCUMENT_ROOT . '/../private/tutor_auth_config.php'

// SQLite DB file path (must be outside public_html).
// Example for Beget layout:
// define('TUTOR_DB_PATH', $_SERVER['DOCUMENT_ROOT'] . '/../private/tutor.sqlite');

define('TUTOR_DB_PATH', '/ABS/PATH/TO/private/tutor.sqlite');

// Secret pepper for code lookups (keep private, random 32+ chars).
// Example: base64 of random_bytes(32).
define('TUTOR_CODE_PEPPER', 'REPLACE_WITH_RANDOM_SECRET_PEPPER');

// Admin password hash (store hash only, not the plain password).
// Generate locally (recommended):
//   php -r "echo password_hash('YOUR_ADMIN_PASSWORD', PASSWORD_DEFAULT);"
define('TUTOR_ADMIN_PASSWORD_HASH', 'REPLACE_WITH_PASSWORD_HASH');
