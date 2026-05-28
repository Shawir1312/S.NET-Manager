<?php
define('IN_APP',true);
require_once __DIR__.'/../includes/config.php';
session_destroy();
header('Location: /portal/login.php');
exit;
