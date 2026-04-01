<?php
// Emergency opcache reset - hit this URL once then delete
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "opcache cleared at " . date('Y-m-d H:i:s');
} else {
    echo "opcache not available";
}
