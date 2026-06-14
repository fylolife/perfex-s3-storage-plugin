<?php

defined('BASEPATH') or exit('No direct script access allowed');

class S3_storage extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        if (!is_admin()) {
            access_denied('S3 Storage');
        }
    }

    public function index()
    {
        if ($this->input->post()) {
            update_option('s3_enabled', $this->input->post('s3_enabled') ? 1 : 0);
            update_option('s3_access_key', $this->input->post('s3_access_key'));
            update_option('s3_secret_key', $this->input->post('s3_secret_key'));
            update_option('s3_bucket', $this->input->post('s3_bucket'));
            update_option('s3_region', $this->input->post('s3_region'));
            update_option('s3_endpoint', $this->input->post('s3_endpoint'));

            set_alert('success', _l('settings_updated'));
            redirect(admin_url('s3_storage'));
        }

        $data['title'] = _l('s3_storage');
        $this->load->view('settings', $data);
    }
}
