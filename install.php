<?php
defined('BASEPATH') or exit('No direct script access allowed');

$CI = &get_instance();

if (!$CI->db->field_exists('external', db_prefix() . 'files')) {
    $CI->db->query("ALTER TABLE `" . db_prefix() . "files` ADD `external` VARCHAR(40) NULL DEFAULT NULL;");
    $CI->db->query("ALTER TABLE `" . db_prefix() . "files` ADD `external_link` TEXT NULL DEFAULT NULL;");
}

if (!$CI->db->field_exists('external', db_prefix() . 'project_files')) {
    $CI->db->query("ALTER TABLE `" . db_prefix() . "project_files` ADD `external` VARCHAR(40) NULL DEFAULT NULL;");
    $CI->db->query("ALTER TABLE `" . db_prefix() . "project_files` ADD `external_link` TEXT NULL DEFAULT NULL;");
}

if (!$CI->db->field_exists('external', db_prefix() . 'ticket_attachments')) {
    $CI->db->query("ALTER TABLE `" . db_prefix() . "ticket_attachments` ADD `external` VARCHAR(40) NULL DEFAULT NULL;");
    $CI->db->query("ALTER TABLE `" . db_prefix() . "ticket_attachments` ADD `external_link` TEXT NULL DEFAULT NULL;");
}

if (!$CI->db->table_exists(db_prefix() . 's3_storage_files')) {
    $CI->db->query("CREATE TABLE `" . db_prefix() . "s3_storage_files` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `rel_id` int(11) NOT NULL,
        `rel_type` varchar(20) NOT NULL,
        `file_name` varchar(255) NOT NULL,
        `s3_key` varchar(500) NOT NULL,
        `dateadded` datetime NOT NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
}

add_option('s3_enabled', 0);
add_option('s3_access_key', '');
add_option('s3_secret_key', '');
add_option('s3_bucket', '');
add_option('s3_region', '');
add_option('s3_endpoint', ''); // For MinIO/Spaces compatibility
add_option('s3_base_url', ''); // Custom Base URL for serving files
