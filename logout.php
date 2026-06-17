<?php
require_once __DIR__ . '/includes/app/auth.php';
hub_logout();
hub_flash('success', 'You have been signed out.');
hub_redirect('index.php');
