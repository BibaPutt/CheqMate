<?php
/**
 * CheqMate Submission Finalization Helper Script
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

set_time_limit(0);

global $CFG, $DB, $USER, $PAGE;

$submissionid = required_param('id', PARAM_INT);
require_sesskey();

// Fetch the submission record
$submission = $DB->get_record('assign_submission', array('id' => $submissionid), '*', MUST_EXIST);
$assign_rec = $DB->get_record('assign', array('id' => $submission->assignment), '*', MUST_EXIST);
$course = $DB->get_record('course', array('id' => $assign_rec->course), '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('assign', $assign_rec->id, $course->id, false, MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
$PAGE->set_url(new moodle_url('/mod/assign/submission/cheqmate/submit_assignment.php', array('id' => $submissionid)));
$PAGE->set_context($context);

// Ensure student owns this submission
$is_owner = ($USER->id == $submission->userid);
if (!$is_owner) {
    throw new required_capability_exception($context, 'mod/assign:submit', 'nopermissions', '');
}

// Mark as final submitted in CheqMate result table
$result_record = $DB->get_record('assignsub_cheqmate_res', ['submission' => $submissionid]);
if ($result_record) {
    $result_record->final_submitted = 1;
    $DB->update_record('assignsub_cheqmate_res', $result_record);
} else {
    $result_record = new stdClass();
    $result_record->submission = $submissionid;
    $result_record->plagiarism_score = 0.00;
    $result_record->ai_probability = 0.00;
    $result_record->report_path = '';
    $result_record->json_result = '{}';
    $result_record->status = 'processed';
    $result_record->timecreated = time();
    $result_record->final_submitted = 1;
    $DB->insert_record('assignsub_cheqmate_res', $result_record);
}

// Instantiate the assignment class
$assignment = new assign($context, $cm, $course);

// Execute Moodle's native submit for grading process (which runs rubric auto-grading via our plugin)
$notices = array();
$data = new stdClass();
$data->userid = $USER->id;
$data->submissionstatement = 1; // Bypass native submission statement screen if required

if (!$assignment->submit_for_grading($data, $notices)) {
    $errormsg = !empty($notices) ? implode(', ', $notices) : 'Submission failed.';
    throw new moodle_exception('error_submission_failed', 'assignsubmission_cheqmate', '', $errormsg);
}

// Redirect back to Moodle's assignment view page
redirect(new moodle_url('/mod/assign/view.php', array('id' => $cm->id)));
