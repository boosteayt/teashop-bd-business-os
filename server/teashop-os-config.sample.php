<?php
return [
  'dsn' => 'mysql:host=localhost;dbname=YOUR_DATABASE;charset=utf8mb4',
  'user' => 'YOUR_DATABASE_USER',
  'pass' => 'YOUR_DATABASE_PASSWORD',

  // Use a long random value only during the one-time account bootstrap.
  // After users are created, set this to an empty string.
  'setup_token' => 'REPLACE_WITH_A_LONG_RANDOM_SETUP_TOKEN',
];
