<?php
/**
 * Plugin Name: H2 Network
 * Description: Helper for running a network of H2 sites.
 * Author: Human Made
 * Network: true
 */

namespace H2\Network;

const FILE = __FILE__;

require __DIR__ . '/inc/namespace.php';
require __DIR__ . '/inc/api/namespace.php';
require __DIR__ . '/inc/comments/namespace.php';
require __DIR__ . '/inc/privacy/namespace.php';
require __DIR__ . '/inc/ui/namespace.php';

bootstrap();
