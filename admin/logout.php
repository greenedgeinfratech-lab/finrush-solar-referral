<?php
require __DIR__ . '/../inc/bootstrap.php';
unset($_SESSION['user_id']);
session_regenerate_id(true);
redirect('/admin/login.php');
