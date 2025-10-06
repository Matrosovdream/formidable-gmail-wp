<?php
/*
Plugin Name: Formidable forms Extension - Gmail parser
Description: 
Version: 1.0
Plugin URI: 
Author URI: 
Author: Stanislav Matrosov
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Variables
define('FRM_GML_BASE_URL', __DIR__);
define('FRM_GML_BASE_PATH', plugin_dir_url(__FILE__));

// Initialize core
require_once 'classes/FrmGmailInit.php';


add_action('init', 'formidable_gmail_init');
function formidable_gmail_init() {
    
    if( isset( $_GET['gmail'] ) ) {

        
        exit();

    }

}


