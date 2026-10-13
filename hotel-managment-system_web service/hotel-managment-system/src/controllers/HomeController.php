<?php
// src/controllers/HomeController.php
require_once __DIR__ . '/../bootstrap.php';

class HomeController {
    public function index() {
        // Redirect to the login page
        header('Location: pages/login.html');
        exit;
    }
}