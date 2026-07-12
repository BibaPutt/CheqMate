<?php
/**
 * CheqMate - Revert Student Submissions
 *
 * Reverting a submission:
 *   1. Deletes the CheqMate result from assignsub_cheqmate_res
 *   2. Calls the engine DELETE /fingerprint/{submission_id}
 *   3. Resets assign_submission.status back to 'new' so the student can resubmit
 *
 * Files are NOT deleted — the student simply regains the ability to resubmit.
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once(__DIR__ . '/lib.php');

$courseid   = required_param('courseid', PARAM_INT);
$studentid  = optional_param('studentid', 0, PARAM_INT);
$action     = optional_param('action', '', PARAM_ALPHA);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('moodle/course:update', $context);

$PAGE->set_url('/mod/assign/submission/cheqmate/revert_submission.php', ['courseid' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title(assignsubmission_cheqmate_get_string('revert_submissions', 'Revert Student Submissions'));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('admin');

$message      = '';
$message_type = '';

if ($action === 'revert' && $studentid > 0 && confirm_sesskey()) {

    $submission_ids = optional_param_array('submission_ids', [], PARAM_INT);

    if (empty($submission_ids)) {
        $message      = assignsubmission_cheqmate_get_string('revert_none_selected', 'No assignments were selected. Please tick at least one assignment.');
        $message_type = 'warning';
    } else {
        $api_url  = get_config('assignsubmission_cheqmate', 'api_url') ?: 'http://localhost:8000';
        $reverted = 0;
        $errors   = 0;

        foreach ($submission_ids as $sub_id) {
            $sub_id = (int) $sub_id;
            if ($sub_id <= 0) {
                continue;
            }

            $submission = $DB->get_record_sql(
                "SELECT s.id, s.status, s.assignment
                   FROM {assign_submission} s
                   JOIN {assign} a ON a.id = s.assignment
                  WHERE s.id = :subid
                    AND s.userid = :userid
                    AND a.course = :courseid",
                ['subid' => $sub_id, 'userid' => $studentid, 'courseid' => $courseid]
            );

            if (!$submission) {
                $errors++;
                continue;
            }

            // 1. Delete CheqMate result record
            $DB->delete_records('assignsub_cheqmate_res', ['submission' => $sub_id]);

            // 2. Delete fingerprint from engine
            $endpoint = rtrim($api_url, '/') . '/fingerprint/' . $sub_id;
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_exec($ch);
            curl_close($ch);

            // 3. Reset submission status so student can resubmit
            $DB->update_record('assign_submission', (object)[
                'id'           => $sub_id,
                'status'       => 'new',
                'timemodified' => time(),
            ]);

            // Clear grades and rubric fillings
            $assign_sub = $DB->get_record('assign_submission', ['id' => $sub_id]);
            if ($assign_sub) {
                $grade = $DB->get_record('assign_grades', [
                    'assignment' => $assign_sub->assignment,
                    'userid' => $studentid,
                    'attemptnumber' => $assign_sub->attemptnumber
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

                    $cm = get_coursemodule_from_instance('assign', $assign_sub->assignment, $courseid, false, IGNORE_MISSING);
                    if ($cm) {
                        $assignment = new assign(context_module::instance($cm->id), $cm, $course);
                        $assignment->update_grade($grade);
                    }
                }
            }

            $reverted++;
        }

        if ($reverted > 0 && $errors === 0) {
            $message      = assignsubmission_cheqmate_get_string('revert_success', 'Successfully reverted {$a} submission(s). The student can now resubmit.', $reverted);
            $message_type = 'success';
        } elseif ($reverted > 0 && $errors > 0) {
            $message      = assignsubmission_cheqmate_get_string('revert_partial', 'Reverted {$a->reverted} submission(s). {$a->errors} could not be reverted.', (object)['reverted' => $reverted, 'errors' => $errors]);
            $message_type = 'warning';
        } else {
            $message      = assignsubmission_cheqmate_get_string('revert_failed', 'No submissions could be reverted. Please check permissions and try again.');
            $message_type = 'danger';
        }
    }
}

$students = get_enrolled_users(
    $context,
    'mod/assign:submit',
    0,
    'u.id, u.firstname, u.lastname, u.email',
    'u.lastname ASC, u.firstname ASC'
);

$submissions = [];
if ($studentid > 0) {
    $submissions = $DB->get_records_sql(
        "SELECT s.id          AS submission_id,
                s.status      AS submission_status,
                s.timemodified,
                a.id          AS assignment_id,
                a.name        AS assignment_name,
                a.duedate,
                r.id          AS result_id,
                r.plagiarism_score,
                r.ai_probability,
                r.status      AS cheqmate_status,
                r.timecreated AS analysed_at
           FROM {assign_submission} s
           JOIN {assign} a ON a.id = s.assignment
           LEFT JOIN {assignsub_cheqmate_res} r ON r.submission = s.id
          WHERE s.userid  = :userid
            AND a.course  = :courseid
            AND s.status != 'new'
          ORDER BY a.name ASC",
        ['userid' => $studentid, 'courseid' => $courseid]
    );
}

echo $OUTPUT->header();

$back_url = new moodle_url('/mod/assign/submission/cheqmate/course_settings.php', ['courseid' => $courseid]);
echo '<div style="margin-bottom:16px;">';
echo '<a href="' . $back_url->out() . '" class="btn btn-sm btn-secondary">&larr; '
    . assignsubmission_cheqmate_get_string('back_to_settings', 'Back to CheqMate Settings') . '</a>';
echo '</div>';

echo $OUTPUT->heading(assignsubmission_cheqmate_get_string('revert_submissions', 'Revert Student Submissions'));
echo '<p class="text-muted">' . assignsubmission_cheqmate_get_string('revert_page_desc', 'Select a student to view all their submitted assignments in this course. Tick the assignments you wish to revert, then click Revert Selected. Reverting clears the CheqMate analysis and resets the submission so the student can upload and resubmit.') . '</p>';

if ($message) {
    echo '<div class="alert alert-' . $message_type . ' alert-dismissible fade show" role="alert">'
        . htmlspecialchars($message)
        . '<button type="button" class="close" data-dismiss="alert" aria-label="Close">'
        . '<span aria-hidden="true">&times;</span></button></div>';
}

// STEP 1 — Student selector
echo '<div class="card mb-4">';
echo '<div class="card-header"><strong>' . assignsubmission_cheqmate_get_string('revert_step1', 'Step 1 — Select Student') . '</strong></div>';
echo '<div class="card-body">';

$select_url = new moodle_url('/mod/assign/submission/cheqmate/revert_submission.php', ['courseid' => $courseid]);

echo '<form method="get" action="' . $select_url->out(false) . '" id="student-select-form">';
echo '<input type="hidden" name="courseid" value="' . $courseid . '">';
echo '<div class="form-group d-flex align-items-center" style="gap:12px;">';
echo '<label for="studentid" class="mb-0 mr-2" style="white-space:nowrap;">'
    . assignsubmission_cheqmate_get_string('select_student', 'Student:') . '</label>';
echo '<select name="studentid" id="studentid" class="form-control" style="max-width:320px;" required>';
echo '<option value="">' . assignsubmission_cheqmate_get_string('choose_student', '— Choose a student —') . '</option>';
foreach ($students as $student) {
    $selected = ($studentid == $student->id) ? ' selected' : '';
    echo '<option value="' . (int)$student->id . '"' . $selected . '>'
        . htmlspecialchars(fullname($student)) . ' ('
        . htmlspecialchars($student->email) . ')</option>';
}
echo '</select>';
echo '<button type="submit" class="btn btn-primary">'
    . assignsubmission_cheqmate_get_string('view_submissions', 'View Submissions') . '</button>';
echo '</div>';
echo '</form>';
echo '</div>';
echo '</div>';

// STEP 2 — Submission list
if ($studentid > 0) {

    $selected_student = null;
    foreach ($students as $s) {
        if ($s->id == $studentid) { $selected_student = $s; break; }
    }

    echo '<div class="card">';
    echo '<div class="card-header d-flex justify-content-between align-items-center">';
    echo '<strong>' . assignsubmission_cheqmate_get_string('revert_step2', 'Step 2 — Select Assignments to Revert') . '</strong>';
    if ($selected_student) {
        echo '<span class="text-muted" style="font-size:0.9em;">'
            . assignsubmission_cheqmate_get_string('showing_for', 'Showing submissions for')
            . ' <strong>' . htmlspecialchars(fullname($selected_student)) . '</strong></span>';
    }
    echo '</div>';
    echo '<div class="card-body">';

    if (empty($submissions)) {
        echo '<div class="alert alert-info mb-0">'
            . assignsubmission_cheqmate_get_string('revert_no_submissions', 'This student has no submitted assignments in this course.') . '</div>';
    } else {

        $revert_url = new moodle_url('/mod/assign/submission/cheqmate/revert_submission.php');

        echo '<form method="post" action="' . $revert_url->out(false) . '" id="revert-form">';
        echo '<input type="hidden" name="courseid"  value="' . (int)$courseid . '">';
        echo '<input type="hidden" name="studentid" value="' . (int)$studentid . '">';
        echo '<input type="hidden" name="action"    value="revert">';
        echo '<input type="hidden" name="sesskey"   value="' . sesskey() . '">';

        echo '<div class="mb-3 d-flex" style="gap:8px;">';
        echo '<button type="button" class="btn btn-sm btn-outline-secondary" id="select-all-btn">'
            . assignsubmission_cheqmate_get_string('select_all', 'Select All') . '</button>';
        echo '<button type="button" class="btn btn-sm btn-outline-secondary" id="deselect-all-btn">'
            . assignsubmission_cheqmate_get_string('deselect_all', 'Deselect All') . '</button>';
        echo '</div>';

        echo '<div class="table-responsive">';
        echo '<table class="table table-hover table-sm">';
        echo '<thead class="thead-light"><tr>';
        echo '<th style="width:40px;"><input type="checkbox" id="check-all" title="Select all"></th>';
        echo '<th>' . assignsubmission_cheqmate_get_string('assignment_name',      'Assignment') . '</th>';
        echo '<th>' . assignsubmission_cheqmate_get_string('submitted_on',         'Submitted On') . '</th>';
        echo '<th>' . assignsubmission_cheqmate_get_string('plagiarism_col',        'Plagiarism') . '</th>';
        echo '<th>' . assignsubmission_cheqmate_get_string('ai_col',                'AI Score') . '</th>';
        echo '<th>' . assignsubmission_cheqmate_get_string('cheqmate_status_col',   'CheqMate Status') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($submissions as $sub) {
            $has_result = !empty($sub->result_id);
            $plag_val   = $has_result ? round($sub->plagiarism_score, 1) : null;
            $ai_val     = $has_result ? round($sub->ai_probability,   1) : null;
            $plag_class = !$has_result ? 'secondary' : ($plag_val > 50 ? 'danger' : ($plag_val > 25 ? 'warning' : 'success'));
            $ai_class   = !$has_result ? 'secondary' : ($ai_val   > 50 ? 'danger' : ($ai_val   > 25 ? 'warning' : 'success'));

            switch ($sub->submission_status) {
                case 'submitted': $status_badge = '<span class="badge badge-primary">Submitted</span>'; break;
                case 'draft':     $status_badge = '<span class="badge badge-secondary">Draft</span>'; break;
                default:          $status_badge = '<span class="badge badge-light">' . htmlspecialchars($sub->submission_status) . '</span>';
            }

            if (!$has_result) {
                $cheqmate_badge = '<span class="badge badge-light">No analysis</span>';
            } else {
                switch ($sub->cheqmate_status) {
                    case 'processed': $cheqmate_badge = '<span class="badge badge-success">Analysed</span>'; break;
                    case 'error':     $cheqmate_badge = '<span class="badge badge-danger">Error</span>'; break;
                    default:          $cheqmate_badge = '<span class="badge badge-warning">' . htmlspecialchars($sub->cheqmate_status) . '</span>';
                }
            }

            echo '<tr>';
            echo '<td><input type="checkbox" name="submission_ids[]" value="' . (int)$sub->submission_id . '" class="submission-checkbox"></td>';
            echo '<td>' . htmlspecialchars($sub->assignment_name) . ' ' . $status_badge . '</td>';
            echo '<td>' . userdate($sub->timemodified, get_string('strftimedatetimeshort', 'langconfig')) . '</td>';
            echo '<td>' . ($plag_val !== null ? '<span class="badge badge-' . $plag_class . '">' . $plag_val . '%</span>' : '<span class="badge badge-secondary">—</span>') . '</td>';
            echo '<td>' . ($ai_val   !== null ? '<span class="badge badge-' . $ai_class   . '">' . $ai_val   . '%</span>' : '<span class="badge badge-secondary">—</span>') . '</td>';
            echo '<td>' . $cheqmate_badge . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        echo '<div class="alert alert-warning mt-3 mb-3" role="alert">';
        echo '<strong>' . assignsubmission_cheqmate_get_string('revert_warning_title', 'What does Revert do?') . '</strong> ';
        echo assignsubmission_cheqmate_get_string('revert_warning_body', 'The selected submissions will have their CheqMate plagiarism/AI results cleared and their status reset to Not submitted, allowing the student to upload a new file. Existing files are NOT deleted.');
        echo '</div>';

        echo '<div class="d-flex align-items-center" style="gap:12px;">';
        echo '<button type="submit" class="btn btn-danger" id="revert-btn" disabled>'
            . assignsubmission_cheqmate_get_string('revert_selected', 'Revert Selected') . '</button>';
        echo '<span class="text-muted" id="selected-count" style="font-size:0.9em;">'
            . assignsubmission_cheqmate_get_string('none_selected', 'No assignments selected') . '</span>';
        echo '</div>';

        echo '</form>';
    }

    echo '</div></div>';
}
?>
<script>
(function () {
    var checkAll     = document.getElementById('check-all');
    var selectAllBtn = document.getElementById('select-all-btn');
    var deselectBtn  = document.getElementById('deselect-all-btn');
    var revertBtn    = document.getElementById('revert-btn');
    var countLabel   = document.getElementById('selected-count');
    var revertForm   = document.getElementById('revert-form');

    function getCheckboxes() {
        return document.querySelectorAll('.submission-checkbox');
    }

    function updateUI() {
        var boxes = getCheckboxes(), checked = 0;
        boxes.forEach(function(b) { if (b.checked) checked++; });
        if (revertBtn)  revertBtn.disabled    = (checked === 0);
        if (countLabel) countLabel.textContent = checked === 0
            ? '<?php echo assignsubmission_cheqmate_get_string('none_selected', 'No assignments selected'); ?>'
            : checked + ' <?php echo assignsubmission_cheqmate_get_string('selected_count', 'assignment(s) selected'); ?>';
        if (checkAll)   checkAll.checked       = (checked === boxes.length && boxes.length > 0);
    }

    if (checkAll)     checkAll.addEventListener('change', function() { getCheckboxes().forEach(function(b) { b.checked = checkAll.checked; }); updateUI(); });
    if (selectAllBtn) selectAllBtn.addEventListener('click', function() { getCheckboxes().forEach(function(b) { b.checked = true;  }); updateUI(); });
    if (deselectBtn)  deselectBtn.addEventListener('click',  function() { getCheckboxes().forEach(function(b) { b.checked = false; }); updateUI(); });

    document.querySelectorAll('.submission-checkbox').forEach(function(b) { b.addEventListener('change', updateUI); });

    if (revertForm) {
        revertForm.addEventListener('submit', function(e) {
            var boxes = getCheckboxes(), checked = 0;
            boxes.forEach(function(b) { if (b.checked) checked++; });
            if (!confirm('<?php echo assignsubmission_cheqmate_get_string('revert_confirm', 'Are you sure you want to revert the selected submissions? This will clear CheqMate data and allow the student to resubmit.'); ?>' + ' (' + checked + ' assignment(s))')) {
                e.preventDefault();
            }
        });
    }

    updateUI();
}());
</script>
<?php
echo $OUTPUT->footer();