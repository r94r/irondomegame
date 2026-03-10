<?php
// Copy this file to config.php and fill in your values.
// config.php is gitignored — never commit real credentials.

define('DB_HOST', 'localhost');
define('DB_NAME', 'YOUR_DB_NAME');    // e.g. r94r_irondome
define('DB_USER', 'YOUR_DB_USER');    // e.g. r94r_ironuser
define('DB_PASS', 'YOUR_DB_PASS');    // your db password
define('TOKEN_SECRET', 'CHANGE_THIS_TO_A_RANDOM_32_CHAR_STRING'); // openssl rand -hex 16
define('STATS_PASS',   'CHANGE_THIS_TO_A_PRIVATE_PASSWORD');       // password for /stats.php
