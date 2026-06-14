<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<h4 class="no-margin">
    <?php echo _l('s3_storage_settings'); ?>
</h4>
<hr class="hr-panel-heading" />

<div class="form-group">
    <label for="settings[s3_enabled]" class="control-label clearfix">
        <?php echo _l('s3_storage_enable'); ?>
    </label>
    <div class="radio radio-primary radio-inline">
        <input type="radio" id="y_opt_1_s3_enabled" name="settings[s3_enabled]" value="1" <?php if(get_option('s3_enabled') == '1'){echo 'checked';} ?>>
        <label for="y_opt_1_s3_enabled">Yes</label>
    </div>
    <div class="radio radio-primary radio-inline">
        <input type="radio" id="y_opt_2_s3_enabled" name="settings[s3_enabled]" value="0" <?php if(get_option('s3_enabled') == '0'){echo 'checked';} ?>>
        <label for="y_opt_2_s3_enabled">No</label>
    </div>
</div>

<?php echo render_input('settings[s3_access_key]', 's3_storage_access_key', get_option('s3_access_key')); ?>

<?php echo render_input('settings[s3_secret_key]', 's3_storage_secret_key', get_option('s3_secret_key'), 'password'); ?>

<?php echo render_input('settings[s3_bucket]', 's3_storage_bucket', get_option('s3_bucket')); ?>

<?php echo render_input('settings[s3_region]', 's3_storage_region', get_option('s3_region')); ?>

<?php echo render_input('settings[s3_endpoint]', 's3_storage_endpoint', get_option('s3_endpoint'), 'text', ['placeholder' => 'Optional for custom S3 like MinIO / Spaces']); ?>

<?php echo render_input('settings[s3_base_url]', 's3_storage_base_url', get_option('s3_base_url'), 'text', ['placeholder' => 'Optional (e.g., https://cdn.example.com/)']); ?>
