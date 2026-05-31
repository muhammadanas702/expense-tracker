<?php

if (file_exists(__DIR__ . "/db.local.php")) {
    require_once "db.local.php";
} else {
    require_once "db.live.php";
}