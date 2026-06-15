<?php
/**
 * CheqMate Submission Finalization Helper Script
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

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

// Instantiate Moodle assign class to do submission processing correctly
$assignment = new assign($context, $cm, $course);

// We need to submit for grading
$data = new stdClass();
$data->userid = $submission->userid;
// Accept submission statement if required
$data->submissionstatement = 1;

$notices = [];
$success = $assignment->submit_for_grading($data, $notices);

if ($success) {
    // If successful, redirect back to assignment page with a success message
    redirect(new moodle_url('/mod/assign/view.php', array('id' => $cm->id)), 'Assignment submitted successfully.', null, \core\output\notification::NOTIFY_SUCCESS);
} else {
    // If failed, redirect with notices or error message
    $message = !empty($notices) ? implode('<br>', $notices) : 'Could not submit assignment for grading.';
    redirect(new moodle_url('/mod/assign/view.php', array('id' => $cm->id)), $message, null, \core\output\notification::NOTIFY_ERROR);
}
