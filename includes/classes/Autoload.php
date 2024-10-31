<?php

/**
 * Generic autoloader for classes named in WordPress coding style.
 */
function mycred_pay_autoload_register($class_name) {

    $class_path = dirname(__FILE__) . '/' . str_replace('_', '/', $class_name) . '.php';

    if (file_exists($class_path)) {
        require_once $class_path;
    }
}

spl_autoload_register('mycred_pay_autoload_register');
