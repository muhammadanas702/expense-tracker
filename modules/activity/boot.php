<?php
/** Common start for Activity pages: it is part of Shared mode, so it reuses the Shared boot (login, user, layout). */
require_once __DIR__ . '/../shared/boot.php';
require_once __DIR__ . '/lib.php';
activity_ensure_schema($conn);
