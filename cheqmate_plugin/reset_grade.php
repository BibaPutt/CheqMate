<?php
/**
 * CheqMate - Reset Student Rubric Grades
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

$submissionid = required_param('id', PARAM_INT);
confirm_sesskey();

$submission = $DB->get_record('assign_submission', ['id' => $submissionid], '*', MUST_EXIST);
$assign_rec = $DB->get_record('assign', ['id' => $submission->assignment], '*', MUST_EXIST);
$course = $DB->get_record('course', ['id' => $assign_rec->course], '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('assign', $assign_rec->id, $course->id, false, MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('mod/assign:grade', $context);

// Clear grades and rubric fillings
$grade = $DB->get_record('assign_grades', [
    'assignment' => $submission->assignment,
    'userid' => $submission->userid,
    'attemptnumber' => $submission->attemptnumber
]);
if ($grade) {
    $instances = $DB->get_records('grading_instances', ['itemid' => $grade->id]);
    foreach ($instances as $instance) {
        $DB->delete_records('gradingform_rubric_fillings', ['instanceid' => $instance->id]);
        $DB->delete_records('grading_instances', ['id' => $instance->id]);
    }
    $grade->grade = -1.0;
    $grade->grader = -1;
    $grade->timemodified = time();
    $DB->update_record('assign_grades', $grade);

    $assignment = new assign($context, $cm, $course);
    $assignment->update_grade($grade);

    // Reset marking workflow state if enabled
    if ($assignment->get_instance()->markingworkflow) {
        $flags = $assignment->get_user_flags($submission->userid, true);
        $flags->workflowstate = 'notmarked';
        $assignment->update_user_flags($flags);
    }
}

redirect(new moodle_url('/mod/assign/view.php', ['id' => $cm->id, 'action' => 'grading']), 'Rubric grades successfully reset.', null, \core\output\notification::NOTIFY_SUCCESS);
