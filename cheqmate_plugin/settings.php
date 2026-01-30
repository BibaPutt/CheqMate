<?php
defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // API URL setting
    $settings->add(new admin_setting_configtext(
        'assignsubmission_cheqmate/api_url',
        'CheqMate API URL',
        'URL of the local Python service (e.g., http://localhost:8000)',
        'http://localhost:8000',
        PARAM_URL
    ));
}

// Course settings - add link to course admin menu
$ADMIN->add('courses', new admin_externalpage(
    'cheqmate_course_settings',
    get_string('course_settings', 'assignsubmission_cheqmate'),
    new moodle_url('/mod/assign/submission/cheqmate/course_settings.php'),
    'moodle/course:update'
));
