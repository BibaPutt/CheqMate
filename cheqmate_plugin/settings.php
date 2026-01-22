<?php
defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configtext('assignsubmission_cheqmate/api_url',
        'CheqMate API URL',
        'URL of the local Python service (e.g., http://localhost:8000)',
        'http://localhost:8000',
        PARAM_URL));
}
