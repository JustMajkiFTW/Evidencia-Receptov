<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

logout_user();
header('Location: login.php');
exit;
