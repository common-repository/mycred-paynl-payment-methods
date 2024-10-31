<?php

/**
 * Plugin Name: myCRED Pay.nl Payment Methods
 * Description: Pay.nl payment methods for myCRED
 * Version: 1.0.0
 * Author: andypay
 * Author URI: http://www.pay.nl
 * Requires at least: 3.0.1
 * Tested up to: 4.0
 *
 * Domain Path: /i18n/languages/
 */
require_once 'includes/classes/Autoload.php';
require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
if (is_plugin_active('mycred/mycred.php')) {
    
    add_filter('mycred_setup_gateways', 'register_custom_gateway');

    function register_custom_gateway($gateways) {
        
        // Using a gateway class
        $gateways['mycred_paynl'] = array(
            'title' => __('Pay.nl'),
            'callback' => array('Mycred_Paynl')
        );

        return $gateways;
    }

}