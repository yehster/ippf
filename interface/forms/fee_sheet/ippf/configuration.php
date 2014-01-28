<?php
$ALLOW_COPAYS=false;

function justify_is_used() {
 global $code_types;
 foreach ($code_types as $value) { if ($value['just']) return true; }
 return false;
}


// This flag comes from the LBFmsivd form and perhaps later others.
$rapid_data_entry = empty($_GET['rde']) ? 0 : 1;
?>