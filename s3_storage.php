<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: S3 Storage
Description: Upload attachments to Amazon S3 or compatible storage instead of local server. Seamless integration for Perfex CRM.
Version: 1.0.0
Requires at least: 2.3.*
Author: Fylo
Author URI: https://www.fylo.co.in
*/

define('S3_STORAGE_MODULE_NAME', 's3_storage');

// Register activation hook
register_activation_hook(S3_STORAGE_MODULE_NAME, 's3_storage_module_activation_hook');

function s3_storage_module_activation_hook()
{
    require_once(__DIR__ . '/install.php');
}

// Load Composer autoload
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once(__DIR__ . '/vendor/autoload.php');
}

// Register language files
register_language_files(S3_STORAGE_MODULE_NAME, [S3_STORAGE_MODULE_NAME]);

hooks()->add_action('admin_init', 's3_storage_module_init_settings');

function s3_storage_module_init_settings()
{
    $CI = &get_instance();
    if (is_admin()) {
        $CI->app->add_settings_section_child('other', 's3_storage', [
            'name'     => _l('s3_storage'),
            'view'     => 's3_storage/settings',
            'position' => 30,
        ]);
    }
}

// Load the library early if enabled
hooks()->add_action('app_init', 's3_storage_load_library');

function s3_storage_load_library()
{
    if (get_option('s3_enabled') == '1') {
        $CI = &get_instance();
        $CI->load->library(S3_STORAGE_MODULE_NAME . '/s3_storage_module');
    }
}
