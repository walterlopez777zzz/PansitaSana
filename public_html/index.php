<?php
/**
 * ============================================================================
 * BANANO STORE - PUBLIC ENTRY POINT (Bridge)
 * ============================================================================
 * 
 * This file MUST exist in public_html/ so the web server's DirectoryIndex
 * can find it when the root domain "/" is requested.
 * It simply delegates all work to router.php.
 * 
 * @version 4.0.0 (Security Migration)
 */

require __DIR__ . '/router.php';
