<?php

defined('BASEPATH') or exit('No direct script access allowed');

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class S3_storage_module
{
    private $client;
    private $bucket;

    public function __construct()
    {
        $CI = &get_instance();
        if (get_option('s3_enabled') != '1') {
            return;
        }

        $config = [
            'region'  => get_option('s3_region'),
            'version' => 'latest',
            'credentials' => [
                'key'    => get_option('s3_access_key'),
                'secret' => get_option('s3_secret_key'),
            ]
        ];

        $endpoint = get_option('s3_endpoint');
        if (!empty($endpoint)) {
            $config['endpoint'] = $endpoint;
            $config['use_path_style_endpoint'] = true;
        }

        try {
            $this->client = new S3Client($config);
            $this->bucket = get_option('s3_bucket');

            $this->register_hooks();
        } catch (\Exception $e) {
            log_message('error', 'S3 Init Error: ' . $e->getMessage());
        }
    }

    private function register_hooks()
    {
        // Instead of breaking Perfex's DB insertions by handling uploads externally,
        // we let Perfex save it & do DB inserts, then we immediately upload to S3
        // and delete the local file at the end of the request.
        register_shutdown_function([$this, 'sync_local_to_s3']);
        
        hooks()->add_filter('download_file_path', [$this, 'handle_download_hook'], 10, 2);
        hooks()->add_filter('company_logo', [$this, 'handle_company_logo'], 10, 1);
        hooks()->add_filter('admin_header_logo_url', [$this, 'handle_admin_header_logo_url'], 10, 1);
        hooks()->add_filter('pdf_logo_url', [$this, 'handle_pdf_logo_url'], 10, 1);
        hooks()->add_filter('favicon_url', [$this, 'handle_favicon_url'], 10, 1);

        // Profile image upload hooks — handle entirely in S3 to avoid broken thumb paths in Perfex core
        hooks()->add_filter('before_handle_staff_profile_image_upload', [$this, 'handle_staff_profile_image_upload'], 10, 1);
        hooks()->add_filter('before_handle_contact_profile_image_upload', [$this, 'handle_contact_profile_image_upload'], 10, 1);

        // Deletion hooks
        hooks()->add_action('before_project_deleted', [$this, 'handle_project_deletion']);
        hooks()->add_action('before_ticket_deleted', [$this, 'handle_ticket_deletion']);
        hooks()->add_action('before_delete_ticket_reply', [$this, 'handle_ticket_reply_deletion']);
        hooks()->add_action('before_client_deleted', [$this, 'handle_client_deletion']);
        hooks()->add_action('before_lead_deleted', [$this, 'handle_lead_deletion']);
        hooks()->add_action('before_remove_project_file', [$this, 'handle_remove_project_file']);

        // Profile image deletion hooks
        hooks()->add_action('before_remove_staff_profile_image', [$this, 'handle_remove_staff_profile_image']);
        hooks()->add_action('staff_member_deleted', [$this, 'handle_staff_member_deleted']);
        hooks()->add_action('before_contact_deleted', [$this, 'handle_contact_profile_image_deletion']);
    }

    public function delete_s3_file($s3_key)
    {
        if (empty($s3_key)) return;
        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key'    => $s3_key
            ]);
        } catch (\Exception $e) {
            log_message('error', 'S3 Delete Error: ' . $e->getMessage());
        }
    }

    public function handle_project_deletion($project_id)
    {
        $CI = &get_instance();
        $CI->db->where('project_id', $project_id);
        $CI->db->where('external', 's3');
        $files = $CI->db->get(db_prefix() . 'project_files')->result();
        foreach ($files as $file) {
            $this->delete_s3_file($file->external_link);
        }
    }

    public function handle_ticket_deletion($ticket_id)
    {
        $CI = &get_instance();
        if ($CI->db->field_exists('external', db_prefix() . 'ticket_attachments')) {
            $CI->db->where('ticketid', $ticket_id);
            $CI->db->where('external', 's3');
            $files = $CI->db->get(db_prefix() . 'ticket_attachments')->result();
            foreach ($files as $file) {
                $this->delete_s3_file($file->external_link);
            }
        }
    }

    public function handle_ticket_reply_deletion($data)
    {
        $CI = &get_instance();
        if ($CI->db->field_exists('external', db_prefix() . 'ticket_attachments')) {
            $CI->db->where('ticketid', $data['ticket_id']);
            $CI->db->where('replyid', $data['reply_id']);
            $CI->db->where('external', 's3');
            $files = $CI->db->get(db_prefix() . 'ticket_attachments')->result();
            foreach ($files as $file) {
                $this->delete_s3_file($file->external_link);
            }
        }
    }

    public function handle_client_deletion($id)
    {
        $CI = &get_instance();
        $CI->db->where('rel_id', $id);
        $CI->db->where('rel_type', 'customer');
        $CI->db->where('external', 's3');
        $files = $CI->db->get(db_prefix() . 'files')->result();
        foreach ($files as $file) {
            $this->delete_s3_file($file->external_link);
        }
    }

    public function handle_remove_project_file($id)
    {
        $CI = &get_instance();
        $CI->db->where('id', $id);
        $CI->db->where('external', 's3');
        $file = $CI->db->get(db_prefix() . 'project_files')->row();
        if ($file) {
            $this->delete_s3_file($file->external_link);
        }
    }

    public function handle_lead_deletion($id)
    {
        $CI = &get_instance();
        $CI->db->where('rel_id', $id);
        $CI->db->where('rel_type', 'lead');
        $CI->db->where('external', 's3');
        $files = $CI->db->get(db_prefix() . 'files')->result();
        foreach ($files as $file) {
            $this->delete_s3_file($file->external_link);
        }
    }

    /**
     * Handle staff profile image upload entirely — resize locally with correct paths,
     * push both thumb and small variants to S3, remove local files, update DB.
     */
    public function handle_staff_profile_image_upload($hookData)
    {
        if (empty($_FILES['profile_image']['name']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
            return $hookData; // not uploading, let Perfex handle (nothing to do)
        }

        $staff_id = isset($hookData['staff_id']) ? $hookData['staff_id'] : null;
        if (!$staff_id) {
            return $hookData;
        }

        $allowed = ['jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
            $hookData['handled_externally'] = true;
            $hookData['handled_externally_successfully'] = false;
            return $hookData;
        }

        $path = get_upload_path_by_type('staff') . $staff_id . '/';
        if (!file_exists($path)) {
            mkdir($path, 0755, true);
        }

        $filename    = unique_filename($path, $_FILES['profile_image']['name']);
        $tmpFilePath = $_FILES['profile_image']['tmp_name'];
        $localFile   = $path . $filename;

        if (!move_uploaded_file($tmpFilePath, $localFile)) {
            return $hookData;
        }

        $CI = &get_instance();

        // Resize to thumb (320x320)
        $thumbFile = $path . 'thumb_' . $filename;
        $config = [
            'image_library'  => 'gd2',
            'source_image'   => $localFile,
            'new_image'      => $thumbFile,
            'maintain_ratio' => true,
            'width'          => hooks()->apply_filters('staff_profile_image_thumb_width', 320),
            'height'         => hooks()->apply_filters('staff_profile_image_thumb_height', 320),
        ];
        $CI->image_lib->initialize($config);
        $CI->image_lib->resize();
        $CI->image_lib->clear();

        // Resize to small (96x96)
        $smallFile = $path . 'small_' . $filename;
        $config['new_image'] = $smallFile;
        $config['width']     = hooks()->apply_filters('staff_profile_image_small_width', 96);
        $config['height']    = hooks()->apply_filters('staff_profile_image_small_height', 96);
        $CI->image_lib->initialize($config);
        $CI->image_lib->resize();
        $CI->image_lib->clear();

        // Remove original (only thumb + small go to S3)
        @unlink($localFile);

        $success = false;

        // Upload both variants to S3
        foreach (['thumb_' => $thumbFile, 'small_' => $smallFile] as $prefix => $filePath) {
            if (file_exists($filePath)) {
                try {
                    $this->client->putObject([
                        'Bucket'     => $this->bucket,
                        'Key'        => 'uploads/staff_profile_images/' . $staff_id . '/' . $prefix . $filename,
                        'SourceFile' => $filePath,
                    ]);
                    @unlink($filePath);
                    $success = true;
                } catch (\Exception $e) {
                    log_message('error', 'S3 Staff Profile Upload Error: ' . $e->getMessage());
                }
            }
        }

        if ($success) {
            $CI->db->where('staffid', $staff_id);
            $CI->db->update(db_prefix() . 'staff', ['profile_image' => $filename]);
        }

        $hookData['handled_externally'] = true;
        $hookData['handled_externally_successfully'] = $success;
        return $hookData;
    }

    /**
     * Handle contact profile image upload entirely — same pattern as staff.
     */
    public function handle_contact_profile_image_upload($hookData)
    {
        if (empty($_FILES['profile_image']['name']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
            return $hookData;
        }

        $contact_id = isset($hookData['contact_id']) ? $hookData['contact_id'] : null;
        if (!$contact_id) {
            return $hookData;
        }

        $allowed = ['jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
            $hookData['handled_externally'] = true;
            $hookData['handled_externally_successfully'] = false;
            return $hookData;
        }

        $path = get_upload_path_by_type('contact_profile_images') . $contact_id . '/';
        if (!file_exists($path)) {
            mkdir($path, 0755, true);
        }

        $filename    = unique_filename($path, $_FILES['profile_image']['name']);
        $tmpFilePath = $_FILES['profile_image']['tmp_name'];
        $localFile   = $path . $filename;

        if (!move_uploaded_file($tmpFilePath, $localFile)) {
            return $hookData;
        }

        $CI = &get_instance();

        // Resize to thumb (320x320)
        $thumbFile = $path . 'thumb_' . $filename;
        $config = [
            'image_library'  => 'gd2',
            'source_image'   => $localFile,
            'new_image'      => $thumbFile,
            'maintain_ratio' => true,
            'width'          => hooks()->apply_filters('contact_profile_image_thumb_width', 320),
            'height'         => hooks()->apply_filters('contact_profile_image_thumb_height', 320),
        ];
        $CI->image_lib->initialize($config);
        $CI->image_lib->resize();
        $CI->image_lib->clear();

        // Resize to small (32x32)
        $smallFile = $path . 'small_' . $filename;
        $config['new_image'] = $smallFile;
        $config['width']     = hooks()->apply_filters('contact_profile_image_small_width', 32);
        $config['height']    = hooks()->apply_filters('contact_profile_image_small_height', 32);
        $CI->image_lib->initialize($config);
        $CI->image_lib->resize();
        $CI->image_lib->clear();

        @unlink($localFile);

        $success = false;

        foreach (['thumb_' => $thumbFile, 'small_' => $smallFile] as $prefix => $filePath) {
            if (file_exists($filePath)) {
                try {
                    $this->client->putObject([
                        'Bucket'     => $this->bucket,
                        'Key'        => 'uploads/client_profile_images/' . $contact_id . '/' . $prefix . $filename,
                        'SourceFile' => $filePath,
                    ]);
                    @unlink($filePath);
                    $success = true;
                } catch (\Exception $e) {
                    log_message('error', 'S3 Contact Profile Upload Error: ' . $e->getMessage());
                }
            }
        }

        if ($success) {
            $CI->db->where('id', $contact_id);
            $CI->db->update(db_prefix() . 'contacts', ['profile_image' => $filename]);
        }

        $hookData['handled_externally'] = true;
        $hookData['handled_externally_successfully'] = $success;
        return $hookData;
    }

    public function handle_company_logo($logo)
    {
        $base_url = get_option('s3_base_url');
        if (!empty($base_url)) {
            $s3_base = rtrim($base_url, '/') . '/';
            // Perfex CRM outputs `<img src="http://localhost/.../uploads/company/logo.png">`
            // We just gracefully replace the local base_url with the S3 base_url.
            $logo = str_replace(base_url(), $s3_base, $logo);
        }
        return $logo;
    }

    public function handle_admin_header_logo_url($url)
    {
        $s3_base = get_option('s3_base_url');
        if (!empty($s3_base)) {
            // Determine which logo file is in use (dark preferred, fall back to light)
            $logo = get_option('company_logo_dark');
            if (empty($logo)) {
                $logo = get_option('company_logo');
            }
            if (!empty($logo)) {
                $url = rtrim($s3_base, '/') . '/uploads/company/' . $logo;
            }
        }
        return $url;
    }

    public function handle_pdf_logo_url($logoImage)
    {
        $base_url = get_option('s3_base_url');
        if (!empty($base_url)) {
            $s3_base = rtrim($base_url, '/') . '/';
            $width = get_option('pdf_logo_width');
            if ($width == '') {
                $width = 120;
            }
            
            // If logoImage is empty, it means local file_exists() failed (because we moved it to S3)
            // We need to rebuild it using the S3 URL.
            $company_logo = get_option('company_logo');
            $company_logo_dark = get_option('company_logo_dark');
            
            $logo_to_use = '';
            if (!empty($company_logo_dark)) {
                $logo_to_use = $company_logo_dark;
            } elseif (!empty($company_logo)) {
                $logo_to_use = $company_logo;
            }
            
            if (!empty($logo_to_use)) {
                $logoUrl = $s3_base . 'uploads/company/' . $logo_to_use;
                $logoImage = '<img width="' . $width . 'px" src="' . $logoUrl . '">';
            }
        }
        return $logoImage;
    }

    public function handle_favicon_url($url)
    {
        $favicon = get_option('favicon');
        if (!empty($favicon)) {
            $base_url = get_option('s3_base_url');
            if (!empty($base_url)) {
                $url = rtrim($base_url, '/') . '/uploads/company/' . $favicon;
            }
        }
        return $url;
    }

    public function sync_local_to_s3()
    {
        // Buffer any output so S3 errors don't corrupt AJAX responses (e.g., Dropzone)
        @ob_start();
        try {
        $CI = &get_instance();
        $base_url = get_option('s3_base_url');
        
        // 1. Sync tblfiles
        $CI->db->where('external IS NULL', null, false);
        // Process all available files
        // $CI->db->limit(10);
        $files = $CI->db->get(db_prefix() . 'files')->result();

        foreach ($files as $file) {
            $path = get_upload_path_by_type($file->rel_type) . $file->rel_id . '/' . $file->file_name;
            if (file_exists($path)) {
                $s3_key = date('Y/m/d') . '/' . $file->file_name;
                try {
                    $this->client->putObject([
                        'Bucket' => $this->bucket,
                        'Key'    => $s3_key,
                        'SourceFile' => $path,
                        'ContentType' => $file->filetype
                    ]);

                    $CI->db->where('id', $file->id);
                    $CI->db->update(db_prefix() . 'files', [
                        'external' => 's3',
                        'external_link' => $s3_key,
                        'thumbnail_link' => rtrim($base_url, '/') . '/' . ltrim($s3_key, '/')
                    ]);
                    @unlink($path);
                } catch (\Exception $e) {
                    log_message('error', 'S3 Upload Error: ' . $e->getMessage());
                }
            } else {
                // If the file doesn't exist locally, it might be an orphaned record or already moved
                // We could mark it as missing, but for safety we do nothing.
            }
        }

        // 2. Sync project_files
        $CI->db->where('external IS NULL', null, false);
        // $CI->db->limit(10);
        $project_files = $CI->db->get(db_prefix() . 'project_files')->result();

        foreach ($project_files as $file) {
            $path = get_upload_path_by_type('project') . $file->project_id . '/' . $file->file_name;
            if (file_exists($path)) {
                $s3_key = date('Y/m/d') . '/' . $file->file_name;
                try {
                    $this->client->putObject([
                        'Bucket' => $this->bucket,
                        'Key'    => $s3_key,
                        'SourceFile' => $path,
                        'ContentType' => $file->filetype
                    ]);

                    $CI->db->where('id', $file->id);
                    $CI->db->update(db_prefix() . 'project_files', [
                        'external' => 's3',
                        'external_link' => $s3_key,
                        'thumbnail_link' => rtrim($base_url, '/') . '/' . ltrim($s3_key, '/')
                    ]);
                    @unlink($path);
                } catch (\Exception $e) {
                    log_message('error', 'S3 Upload Error: ' . $e->getMessage());
                }
            }
        }

        // 3. Sync ticket_attachments
        if ($CI->db->field_exists('external', db_prefix() . 'ticket_attachments')) {
            $CI->db->where('external IS NULL', null, false);
            $ticket_files = $CI->db->get(db_prefix() . 'ticket_attachments')->result();

            foreach ($ticket_files as $file) {
                $path = get_upload_path_by_type('ticket') . $file->ticketid . '/' . $file->file_name;
                if (file_exists($path)) {
                    $s3_key = date('Y/m/d') . '/' . $file->file_name;
                    try {
                        $this->client->putObject([
                            'Bucket' => $this->bucket,
                            'Key'    => $s3_key,
                            'SourceFile' => $path,
                            'ContentType' => $file->filetype
                        ]);

                        $CI->db->where('id', $file->id);
                        $CI->db->update(db_prefix() . 'ticket_attachments', [
                            'external' => 's3',
                            'external_link' => $s3_key,
                            'thumbnail_link' => rtrim($base_url, '/') . '/' . ltrim($s3_key, '/')
                        ]);
                        @unlink($path);
                    } catch (\Exception $e) {
                        log_message('error', 'S3 Upload Error: ' . $e->getMessage());
                    }
                }
            }
        }

        // 4. Sync Company Logo and Favicon
        $logos_to_sync = [
            get_option('company_logo'),
            get_option('company_logo_dark'),
            get_option('favicon')
        ];
        
        foreach ($logos_to_sync as $logo_file) {
            if (!empty($logo_file)) {
                $path = get_upload_path_by_type('company') . $logo_file;
                if (file_exists($path)) {
                    try {
                        $this->client->putObject([
                            'Bucket' => $this->bucket,
                            'Key'    => 'uploads/company/' . $logo_file,
                            'SourceFile' => $path
                        ]);
                        @unlink($path);
                    } catch (\Exception $e) {}
                }
            }
        }


        // 5. Sync Staff Profile Images
        $staff = $CI->db->select('staffid, profile_image')->where('profile_image !=', '')->where('profile_image IS NOT NULL')->get(db_prefix() . 'staff')->result();
        foreach ($staff as $s) {
            foreach (['small_', 'thumb_'] as $prefix) {
                $path = get_upload_path_by_type('staff') . $s->staffid . '/' . $prefix . $s->profile_image;
                if (file_exists($path)) {
                    try {
                        $this->client->putObject([
                            'Bucket' => $this->bucket,
                            'Key'    => 'uploads/staff_profile_images/' . $s->staffid . '/' . $prefix . $s->profile_image,
                            'SourceFile' => $path
                        ]);
                        @unlink($path);
                    } catch (\Exception $e) {}
                }
            }
        }

        // 6. Sync Contact Profile Images
        $contacts = $CI->db->select('id, profile_image')->where('profile_image !=', '')->where('profile_image IS NOT NULL')->get(db_prefix() . 'contacts')->result();
        foreach ($contacts as $c) {
            foreach (['small_', 'thumb_'] as $prefix) {
                $path = get_upload_path_by_type('contact_profile_images') . $c->id . '/' . $prefix . $c->profile_image;
                if (file_exists($path)) {
                    try {
                        $this->client->putObject([
                            'Bucket' => $this->bucket,
                            'Key'    => 'uploads/client_profile_images/' . $c->id . '/' . $prefix . $c->profile_image,
                            'SourceFile' => $path
                        ]);
                        @unlink($path);
                    } catch (\Exception $e) {}
                }
            }
        }

        } catch (\Exception $e) {
            log_message('error', 'S3 sync_local_to_s3 Error: ' . $e->getMessage());
        }
        @ob_end_clean();
    }

    /**
     * Delete staff profile images from S3 when user clicks 'remove profile image'.
     * The action 'before_remove_staff_profile_image' passes no arguments,
     * so we reconstruct the staff_id from the URI.
     */
    public function handle_remove_staff_profile_image()
    {
        $CI = &get_instance();
        // Reconstruct staff_id from the URL segments (same logic as the controller)
        $staff_id = get_staff_user_id();
        $uri_segment = $CI->uri->segment(4); // admin/staff/remove_staff_profile_image/{id}
        if (is_numeric($uri_segment)) {
            $staff_id = $uri_segment;
        }

        $CI->db->select('profile_image');
        $CI->db->where('staffid', $staff_id);
        $staff = $CI->db->get(db_prefix() . 'staff')->row();

        if ($staff && !empty($staff->profile_image)) {
            foreach (['thumb_', 'small_'] as $prefix) {
                $this->delete_s3_file('uploads/staff_profile_images/' . $staff_id . '/' . $prefix . $staff->profile_image);
            }
        }
    }

    /**
     * Delete staff profile images from S3 when the staff member is deleted entirely.
     */
    public function handle_staff_member_deleted($data)
    {
        $staff_id = is_array($data) ? (isset($data['id']) ? $data['id'] : (isset($data['staffid']) ? $data['staffid'] : null)) : $data;
        if (!$staff_id) return;

        $CI = &get_instance();
        $CI->db->select('profile_image');
        $CI->db->where('staffid', $staff_id);
        $staff = $CI->db->get(db_prefix() . 'staff')->row();

        if ($staff && !empty($staff->profile_image)) {
            foreach (['thumb_', 'small_'] as $prefix) {
                $this->delete_s3_file('uploads/staff_profile_images/' . $staff_id . '/' . $prefix . $staff->profile_image);
            }
        }
    }

    /**
     * Delete contact profile images from S3 when the contact is deleted.
     */
    public function handle_contact_profile_image_deletion($data)
    {
        $contact_id = is_array($data) ? (isset($data['id']) ? $data['id'] : null) : $data;
        if (!$contact_id) return;

        $CI = &get_instance();
        $CI->db->select('profile_image');
        $CI->db->where('id', $contact_id);
        $contact = $CI->db->get(db_prefix() . 'contacts')->row();

        if ($contact && !empty($contact->profile_image)) {
            foreach (['thumb_', 'small_'] as $prefix) {
                $this->delete_s3_file('uploads/client_profile_images/' . $contact_id . '/' . $prefix . $contact->profile_image);
            }
        }
    }

    public function handle_download_hook($path, $data)
    {
        $filename = basename($path);
        
        $CI = &get_instance();
        $CI->db->where('file_name', $filename);
        // Sometimes we might not find it in tblfiles if it's a project_file, so we should check both
        $file = $CI->db->get(db_prefix() . 'files')->row();
        
        $s3_key = null;
        if ($file && $file->external == 's3') {
            $s3_key = $file->external_link;
        } else {
            // Check project_files
            $CI->db->where('file_name', $filename);
            $p_file = $CI->db->get(db_prefix() . 'project_files')->row();
            if ($p_file && $p_file->external == 's3') {
                $s3_key = $p_file->external_link;
            } else {
                if ($CI->db->field_exists('external', db_prefix() . 'ticket_attachments')) {
                    $CI->db->where('file_name', $filename);
                    $t_file = $CI->db->get(db_prefix() . 'ticket_attachments')->row();
                    if ($t_file && $t_file->external == 's3') {
                        $s3_key = $t_file->external_link;
                    }
                }
            }
        }

        if ($s3_key) {
            $base_url = get_option('s3_base_url');
            if (!empty($base_url)) {
                $url = rtrim($base_url, '/') . '/' . ltrim($s3_key, '/');
            } else {
                $cmd = $this->client->getCommand('GetObject', [
                    'Bucket' => $this->bucket,
                    'Key' => $s3_key
                ]);
                $request = $this->client->createPresignedRequest($cmd, '+60 minutes');
                $url = (string)$request->getUri();
            }
            header('Location: ' . $url);
            exit;
        }

        return $path;
    }
}
