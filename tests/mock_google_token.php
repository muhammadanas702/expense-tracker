<?php
// Fake Google token endpoint for local end-to-end tests (tests/google_app_handoff_test.py). Never deployed to production use.
header('Content-Type: application/json');
$claims = json_decode((string) @file_get_contents(getenv('MOCK_GOOGLE_CLAIMS') ?: sys_get_temp_dir() . '/mock-google-claims.json'), true) ?: [];
$b = fn($a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');
echo json_encode(['id_token' => $b(['alg' => 'none']) . '.' . $b($claims) . '.sig', 'access_token' => 'x']);
