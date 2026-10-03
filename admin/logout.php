<?php
require_once __DIR__ . '/../cookies.php';

get_admin_session();
session_unset();
session_destroy();

header("Location: login.php");
exit;
