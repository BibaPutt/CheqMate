<?php
/**
 * Library functions for CheqMate plugin
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Serve the embedded files.
 */
function assignsubmission_cheqmate_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB, $CFG;
    
    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }
    
    require_login($course, false, $cm);
    
    $itemid = array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
    
    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'assignsubmission_cheqmate', $filearea, $itemid, $filepath, $filename);
    
    if (!$file) {
        return false;
    }
    
    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Add course settings link to course administration
 */
function assignsubmission_cheqmate_extend_navigation_course($navigation, $course, $context) {
    if (has_capability('moodle/course:update', $context)) {
        $url = new moodle_url('/mod/assign/submission/cheqmate/course_settings.php', ['courseid' => $course->id]);
        $navigation->add(
            get_string('course_settings', 'assignsubmission_cheqmate'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            'cheqmate_settings',
            new pix_icon('i/settings', '')
        );
    }
}

/**
 * Fragment callback for AJAX operations
 */
function assignsubmission_cheqmate_output_fragment_clear_cache($args) {
    global $DB;
    
    $context = $args['context'];
    require_capability('mod/assign:grade', $context);
    
    $assignmentid = $args['assignmentid'];
    
    // Call engine to clear cache
    $api_url = get_config('assignsubmission_cheqmate', 'api_url') ?: 'http://localhost:8000';
    $endpoint = $api_url . '/cache/clear';
    
    $payload = json_encode(['assignment_id' => $assignmentid]);
    
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpcode == 200) {
        $result = json_decode($response, true);
        return json_encode([
            'success' => true,
            'message' => get_string('cache_cleared', 'assignsubmission_cheqmate', $result['cleared_count'] ?? 0)
        ]);
    } else {
        return json_encode([
            'success' => false,
            'message' => get_string('error_connection', 'assignsubmission_cheqmate')
        ]);
    }
}
