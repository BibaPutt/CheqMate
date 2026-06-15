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
            assignsubmission_cheqmate_get_string('course_settings', 'CheqMate Settings'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            'cheqmate_settings',
            new pix_icon('i/settings', '')
        );

        $download_url = new moodle_url('/mod/assign/submission/cheqmate/select_user_download.php', ['courseid' => $course->id]);
        $navigation->add(
            'Download User Assignments',
            $download_url,
            navigation_node::TYPE_SETTING,
            null,
            'cheqmate_download',
            new pix_icon('i/export', '')
        );

        $revert_url = new moodle_url('/mod/assign/submission/cheqmate/revert_submission.php', ['courseid' => $course->id]);
        $navigation->add(
            assignsubmission_cheqmate_get_string('revert_submissions', 'Revert Student Assignments'),
            $revert_url,
            navigation_node::TYPE_SETTING,
            null,
            'cheqmate_revert',
            new pix_icon('i/reload', '')
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
            'message' => assignsubmission_cheqmate_get_string('cache_cleared', 'Cache cleared. {$a} fingerprints removed.', $result['cleared_count'] ?? 0)
        ]);
    } else {
        return json_encode([
            'success' => false,
            'message' => assignsubmission_cheqmate_get_string('error_connection', 'Could not connect to CheqMate engine.')
        ]);
    }
}

/**
 * Safely get a string, using a fallback if the string cache is stale in Moodle.
 */
function assignsubmission_cheqmate_get_string($identifier, $fallback, $a = null) {
    if (get_string_manager()->string_exists($identifier, 'assignsubmission_cheqmate')) {
        return get_string($identifier, 'assignsubmission_cheqmate', $a);
    }
    if ($a !== null) {
        if (is_scalar($a)) {
            return str_replace('{$a}', $a, $fallback);
        } else if (is_object($a) || is_array($a)) {
            $a_arr = (array)$a;
            $res = $fallback;
            foreach ($a_arr as $k => $v) {
                $res = str_replace('{$a->' . $k . '}', $v, $res);
            }
            return $res;
        }
    }
    return $fallback;
}
