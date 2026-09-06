<?php ob_start(); require dirname(__DIR__) . '/fragments/health.php'; $content = (string) ob_get_clean(); require dirname(__DIR__) . '/layout.php';
