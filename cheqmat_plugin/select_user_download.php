<?php
require_once(__DIR__ . '/../../../../config.php');

$courseid = required_param('courseid', PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

require_login($course);

$context = context_course::instance($courseid);
require_capability('moodle/course:update', $context);

$PAGE->set_url('/mod/assign/submission/cheqmate/select_user_download.php', [
    'courseid' => $courseid
]);

$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Download Student Assignments');
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading('Select Student to Download Assignments');

// Get enrolled students
$students = get_enrolled_users($context, 'mod/assign:submit');

// Build dropdown options
$options = [];
foreach ($students as $student) {
    $options[$student->id] = fullname($student);
}

// Display form
echo '<form method="get" action="download_user_assignments.php">';
echo '<input type="hidden" name="courseid" value="'.$courseid.'">';
echo '<select name="userid" required>';

foreach ($options as $id => $name) {
    echo '<option value="'.$id.'">'.$name.'</option>';
}

echo '</select> ';
echo '<button type="submit" class="btn btn-primary">Download</button>';
echo '</form>';

echo $OUTPUT->footer();