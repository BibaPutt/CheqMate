<?php
require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

$submissionid = required_param('id', PARAM_INT);

// Basic checks and capabilities
require_login();
$submission = $DB->get_record('assign_submission', array('id' => $submissionid), '*', MUST_EXIST);
$assign = $DB->get_record('assign', array('id' => $submission->assignment), '*', MUST_EXIST);
$course = $DB->get_record('course', array('id' => $assign->course), '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('assign', $assign->id, $course->id, false, MUST_EXIST);
$context = context_module::instance($cm->id);

$is_teacher = has_capability('mod/assign:grade', $context);
$is_owner = ($USER->id == $submission->userid);

$cheqmate_settings = $DB->get_record('assignsubmission_cheqmate', ['assignment' => $assign->id]);
$student_view = $cheqmate_settings ? $cheqmate_settings->student_view : 0;

if (!$is_teacher && !($is_owner && $student_view)) {
    throw new required_capability_exception($context, 'mod/assign:grade', 'nopermissions', '');
}

// Fetch CheqMate Result
$result_record = $DB->get_record('assignsub_cheqmate_res', ['submission' => $submissionid]);
if (!$result_record) {
    throw new moodle_exception('No CheqMate data found for this submission.');
}

$details = json_decode($result_record->json_result, true);
if (empty($details['details'])) {
    throw new moodle_exception('No identical peers found to generate an advanced report against.');
}

// Get source file
$fs = get_file_storage();
$files = $fs->get_area_files($context->id, 'assignsubmission_file', 'submission_files', $submissionid, 'timemodified', false);
if (empty($files)) {
    throw new moodle_exception('Source submission file not found.');
}
$source_file = reset($files);
$source_tmp = make_request_directory() . '/' . $source_file->get_contenthash() . '_' . $source_file->get_filename();
$source_file->copy_content_to($source_tmp);

$post_data = array(
    'source_file' => new CURLFile($source_tmp, $source_file->get_mimetype(), $source_file->get_filename()),
    'submission_id' => $submissionid,
    'plagiarism_score' => $result_record->plagiarism_score,
    'ai_probability' => $result_record->ai_probability
);

$tmps_to_delete = [$source_tmp];

$i = 0;
foreach ($details['details'] as $match) {
    if ($i >= 4) break; // Limit to top 4 matches for multi-color clarity

    if (isset($match['source_type']) && $match['source_type'] == 'global') {
        // Pass Global metadata (Python has the file)
        $name = "Global System: " . ($match['filename'] ?? 'Unknown');
        $post_data["peer_names[$i]"] = $name;
        $post_data["peer_scores[$i]"] = $match['score'];
        // Pass empty file indicator or skip passing file for global
        $i++;
    } else if (isset($match['submission_id'])) {
        $peer_sub_id = $match['submission_id'];
        
        $peer_user = $DB->get_record_sql(
            "SELECT u.* FROM {user} u JOIN {assign_submission} s ON s.userid = u.id WHERE s.id = ?", 
            [$peer_sub_id]
        );
        $name = $peer_user ? fullname($peer_user) : "ID:" . $peer_sub_id;
        
        // Get peer file
        $p_files = $fs->get_area_files($context->id, 'assignsubmission_file', 'submission_files', $peer_sub_id, 'timemodified', false);
        if (!empty($p_files)) {
            $p_file = reset($p_files);
            $p_tmp = make_request_directory() . '/' . $p_file->get_contenthash() . '_' . $p_file->get_filename();
            $p_file->copy_content_to($p_tmp);
            
            $post_data["peer_files[$i]"] = new CURLFile($p_tmp, $p_file->get_mimetype(), $p_file->get_filename());
            $post_data["peer_names[$i]"] = $name;
            $post_data["peer_scores[$i]"] = $match['score'];
            
            $tmps_to_delete[] = $p_tmp;
            $i++;
        }
    }
}

// Get Python Engine URL
$api_url = get_config('assignsubmission_cheqmate', 'api_url');
if (!$api_url) {
    $api_url = 'http://localhost:8000'; // fallback
}
$endpoint = rtrim($api_url, '/') . '/advanced_report';

// Send to Python Engine via cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $endpoint);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, false);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// Cleanup Temp Files
foreach ($tmps_to_delete as $tmp) {
    @unlink($tmp);
}

if ($http_code === 200 && $response !== false) {
    // Clean any existing output buffers (like Moodle warnings) to prevent PDF corruption
    while (@ob_end_clean());
    
    // Stream PDF to browser
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Advanced_Plagiarism_Report_' . $submissionid . '.pdf"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . strlen($response));
    echo $response;
    exit;
} else {
    throw new moodle_exception("Failed to generate advanced report. Engine responded with code $http_code. Error: $error. Response: " . htmlspecialchars(substr($response, 0, 500)));
}
