<?php
require __DIR__ . '/../inc/bootstrap.php';
unset($_SESSION['partner_id']);
session_regenerate_id(true);
redirect('/partner/login.php');
