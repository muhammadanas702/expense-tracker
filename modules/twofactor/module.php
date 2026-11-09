<?php
// Two-step sign-in: an e-mailed 6-digit code after the password. Optional for users; Admin > Settings can require it for staff or everyone (default: off).
// Login and Settings > Security only call twofa_* helpers guarded with function_exists(), so this module can be switched off.
return ['id' => 'twofactor', 'mode' => 'p', 'enabled' => true, 'order' => 96, 'nav' => []];
