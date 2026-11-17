<?php
// Reports now live on the Dashboard.
header('Location: dashboard.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '') . '#report');
exit;
