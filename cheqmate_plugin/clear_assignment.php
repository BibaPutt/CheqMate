<?php
/**
 * CheqMate - Clear Assignment
 *
 * Allows admins/teachers to select an assignment from the course and wipe
 * ALL student submissions for it completely, making the assignment empty
 * so every student can upload fresh files.
 *
 * What "Clear" does for each submission:
 *   1. Deletes the submitted files from Moodle file storage
 *   2. Deletes the CheqMate result record from assignsub_cheqmate_res
 *   3. Calls the engine DELETE /fingerprint/{submission_id}
 *   4. Resets assign_submission status → 'new' and clears timemodified
 *
 * Nothing else in the plugin is touched — existing workflow is preserved.
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

// -------------------------------------------------------------------------
// Parameters
// -------------------------------------------------------------------------
$courseid      = required_param('courseid', PARAM_INT);
$assignmentid  = optional_param('assignmentid', 0, PARAM_INT);
$action        = optional_param('action', '', PARAM_ALPHA);

// -------------------------------------------------------------------------
// Access control
// -------------------------------------------------------------------------
$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('moodle/course:update', $context);

// -------------------------------------------------------------------------
// Page setup
// -------------------------------------------------------------------------
$PAGE->set_url('/mod/assign/submission/cheqmate/clear_assignment.php', ['courseid' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title(get_string('clear_assignment', 'assignsubmission_cheqmate'));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('admin');

// -------------------------------------------------------------------------
// Handle clear POST action
// -------------------------------------------------------------------------
$message      = '';
$message_type = '';

if ($action === 'clear' && $assignmentid > 0 && confirm_sesskey()) {

    // Security: verify the assignment belongs to this course
    $assignment = $DB->get_record_sql(
        "SELECT a.id, a.name
           FROM {assign} a
           JOIN {course_modules} cm ON cm.instance = a.id
           JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
          WHERE a.id = :assignid
            AND a.course = :courseid",
        ['assignid' => $assignmentid, 'courseid' => $courseid]
    );

    if (!$assignment) {
        $message      = get_string('clear_invalid_assignment', 'assignsubmission_cheqmate');
        $message_type = 'danger';
    } else {

        // Get the course module context for file deletion
        $cm = get_coursemodule_from_instance('assign', $assignmentid, $courseid, false, MUST_EXIST);
        $assign_context = context_module::instance($cm->id);

        // Get ALL submissions for this assignment (all students)
        $submissions = $DB->get_records_sql(
            "SELECT s.id, s.userid, s.status
               FROM {assign_submission} s
              WHERE s.assignment = :assignid
                AND s.status != 'new'",
            ['assignid' => $assignmentid]
        );

        $api_url = get_config('assignsubmission_cheqmate', 'api_url') ?: 'http://localhost:8000';
        $fs      = get_file_storage();
        $cleared = 0;
        $errors  = 0;

        foreach ($submissions as $sub) {

            try {
                // 1. Delete submitted files from Moodle file storage
                $fs->delete_area_files(
                    $assign_context->id,
                    'assignsubmission_file',
                    'submission_files',
                    $sub->id
                );

                // 2. Delete CheqMate result record
                $DB->delete_records('assignsub_cheqmate_res', ['submission' => $sub->id]);

                // 3. Delete fingerprint from engine (same as remove() in locallib.php)
                $endpoint = rtrim($api_url, '/') . '/fingerprint/' . $sub->id;
                $ch = curl_init($endpoint);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_exec($ch);
                curl_close($ch);

                // 4. Reset submission record → 'new' (empty)
                $DB->update_record('assign_submission', (object)[
                    'id'           => $sub->id,
                    'status'       => 'new',
                    'timemodified' => time(),
                ]);

                $cleared++;

            } catch (Exception $e) {
                $errors++;
            }
        }

        // Also delete any grade records linked to this assignment so gradebook is clean
        // (only the rubric auto-grade filling — leave teacher manual grades intact)
        // We deliberately do NOT touch mdl_assign_grades to preserve any manual grades.

        if ($cleared > 0 && $errors === 0) {
            $message      = get_string('clear_success', 'assignsubmission_cheqmate',
                                (object)['count' => $cleared, 'name' => $assignment->name]);
            $message_type = 'success';
        } elseif ($cleared > 0 && $errors > 0) {
            $message      = get_string('clear_partial', 'assignsubmission_cheqmate',
                                (object)['cleared' => $cleared, 'errors' => $errors, 'name' => $assignment->name]);
            $message_type = 'warning';
        } elseif ($cleared === 0 && $errors === 0) {
            $message      = get_string('clear_nothing_to_clear', 'assignsubmission_cheqmate');
            $message_type = 'info';
        } else {
            $message      = get_string('clear_failed', 'assignsubmission_cheqmate');
            $message_type = 'danger';
        }

        // Reset assignmentid so the preview table refreshes to show 0 submissions
        if ($cleared > 0) {
            $assignmentid = 0;
        }
    }
}

// -------------------------------------------------------------------------
// Data: all assignments in this course that have CheqMate enabled
// We show ALL assignments so admin can clear any of them
// -------------------------------------------------------------------------
$assignments = $DB->get_records_sql(
    "SELECT a.id, a.name, a.duedate,
            (SELECT COUNT(*) FROM {assign_submission} s
              WHERE s.assignment = a.id AND s.status != 'new') AS submission_count
       FROM {assign} a
      WHERE a.course = :courseid
      ORDER BY a.name ASC",
    ['courseid' => $courseid]
);

// -------------------------------------------------------------------------
// Data: submissions preview for selected assignment
// -------------------------------------------------------------------------
$preview_submissions = [];
if ($assignmentid > 0) {
    $preview_submissions = $DB->get_records_sql(
        "SELECT s.id          AS submission_id,
                s.status      AS submission_status,
                s.timemodified,
                u.firstname,
                u.lastname,
                u.email,
                r.plagiarism_score,
                r.ai_probability,
                r.status      AS cheqmate_status
           FROM {assign_submission} s
           JOIN {user} u ON u.id = s.userid
           LEFT JOIN {assignsub_cheqmate_res} r ON r.submission = s.id
          WHERE s.assignment = :assignid
            AND s.status != 'new'
          ORDER BY u.lastname ASC, u.firstname ASC",
        ['assignid' => $assignmentid]
    );
}

// -------------------------------------------------------------------------
// Render
// -------------------------------------------------------------------------
echo $OUTPUT->header();

// Back link
$back_url = new moodle_url('/mod/assign/submission/cheqmate/course_settings.php', ['courseid' => $courseid]);
echo '<div style="margin-bottom:16px;">';
echo '<a href="' . $back_url->out() . '" class="btn btn-sm btn-secondary">&larr; '
    . get_string('back_to_settings', 'assignsubmission_cheqmate') . '</a>';
echo '</div>';

echo $OUTPUT->heading(get_string('clear_assignment', 'assignsubmission_cheqmate'));
echo '<p class="text-muted">' . get_string('clear_page_desc', 'assignsubmission_cheqmate') . '</p>';

// Alert message
if ($message) {
    echo '<div class="alert alert-' . $message_type . ' alert-dismissible fade show" role="alert">'
        . htmlspecialchars($message)
        . '<button type="button" class="close" data-dismiss="alert" aria-label="Close">'
        . '<span aria-hidden="true">&times;</span></button></div>';
}

// =========================================================================
// STEP 1 — Assignment selector
// =========================================================================
echo '<div class="card mb-4">';
echo '<div class="card-header"><strong>' . get_string('clear_step1', 'assignsubmission_cheqmate') . '</strong></div>';
echo '<div class="card-body">';

$select_url = new moodle_url('/mod/assign/submission/cheqmate/clear_assignment.php', ['courseid' => $courseid]);

echo '<form method="get" action="' . $select_url->out(false) . '" id="assignment-select-form">';
echo '<input type="hidden" name="courseid" value="' . (int)$courseid . '">';

echo '<div class="form-group d-flex align-items-center" style="gap:12px;">';
echo '<label for="assignmentid" class="mb-0 mr-2" style="white-space:nowrap;">'
    . get_string('select_assignment', 'assignsubmission_cheqmate') . '</label>';

echo '<select name="assignmentid" id="assignmentid" class="form-control" style="max-width:400px;" required>';
echo '<option value="">' . get_string('choose_assignment', 'assignsubmission_cheqmate') . '</option>';

foreach ($assignments as $assign) {
    $selected = ($assignmentid == $assign->id) ? ' selected' : '';
    $sub_count = (int)$assign->submission_count;
    $label = htmlspecialchars($assign->name);
    if ($sub_count > 0) {
        $label .= ' (' . $sub_count . ' ' . get_string('submissions_count_label', 'assignsubmission_cheqmate') . ')';
    } else {
        $label .= ' (' . get_string('no_submissions_label', 'assignsubmission_cheqmate') . ')';
    }
    echo '<option value="' . (int)$assign->id . '"' . $selected . '>' . $label . '</option>';
}

echo '</select>';
echo '<button type="submit" class="btn btn-primary">'
    . get_string('preview_submissions', 'assignsubmission_cheqmate') . '</button>';
echo '</div>';
echo '</form>';
echo '</div>';
echo '</div>';

// =========================================================================
// STEP 2 — Preview table + Clear button
// =========================================================================
if ($assignmentid > 0) {

    $selected_assignment = $assignments[$assignmentid] ?? null;

    echo '<div class="card">';
    echo '<div class="card-header d-flex justify-content-between align-items-center">';
    echo '<strong>' . get_string('clear_step2', 'assignsubmission_cheqmate') . '</strong>';
    if ($selected_assignment) {
        echo '<span class="text-muted" style="font-size:0.9em;">'
            . get_string('clear_showing_for', 'assignsubmission_cheqmate')
            . ' <strong>' . htmlspecialchars($selected_assignment->name) . '</strong></span>';
    }
    echo '</div>';
    echo '<div class="card-body">';

    if (empty($preview_submissions)) {
        echo '<div class="alert alert-info mb-0">'
            . get_string('clear_no_submissions', 'assignsubmission_cheqmate') . '</div>';
    } else {

        // Summary counts
        $total = count($preview_submissions);
        echo '<p class="mb-3">';
        echo '<span class="badge badge-secondary" style="font-size:0.95em; padding:6px 12px;">';
        echo $total . ' ' . get_string('students_will_be_cleared', 'assignsubmission_cheqmate');
        echo '</span>';
        echo '</p>';

        // Preview table
        echo '<div class="table-responsive mb-3">';
        echo '<table class="table table-hover table-sm">';
        echo '<thead class="thead-light"><tr>';
        echo '<th>' . get_string('student_name_col',    'assignsubmission_cheqmate') . '</th>';
        echo '<th>' . get_string('submitted_on',         'assignsubmission_cheqmate') . '</th>';
        echo '<th>' . get_string('plagiarism_col',        'assignsubmission_cheqmate') . '</th>';
        echo '<th>' . get_string('ai_col',                'assignsubmission_cheqmate') . '</th>';
        echo '<th>' . get_string('cheqmate_status_col',   'assignsubmission_cheqmate') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($preview_submissions as $sub) {
            $has_result = !empty($sub->cheqmate_status);
            $plag_val   = $has_result ? round($sub->plagiarism_score, 1) : null;
            $ai_val     = $has_result ? round($sub->ai_probability,   1) : null;
            $plag_class = !$has_result ? 'secondary' : ($plag_val > 50 ? 'danger' : ($plag_val > 25 ? 'warning' : 'success'));
            $ai_class   = !$has_result ? 'secondary' : ($ai_val   > 50 ? 'danger' : ($ai_val   > 25 ? 'warning' : 'success'));

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
            echo '<td><strong>' . htmlspecialchars($sub->lastname . ', ' . $sub->firstname) . '</strong>'
                . '<br><small class="text-muted">' . htmlspecialchars($sub->email) . '</small></td>';
            echo '<td>' . userdate($sub->timemodified, get_string('strftimedatetimeshort', 'langconfig')) . '</td>';
            echo '<td>' . ($plag_val !== null
                ? '<span class="badge badge-' . $plag_class . '">' . $plag_val . '%</span>'
                : '<span class="badge badge-secondary">—</span>') . '</td>';
            echo '<td>' . ($ai_val !== null
                ? '<span class="badge badge-' . $ai_class . '">' . $ai_val . '%</span>'
                : '<span class="badge badge-secondary">—</span>') . '</td>';
            echo '<td>' . $cheqmate_badge . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        // Danger warning box
        echo '<div class="alert alert-danger" role="alert">';
        echo '<strong>' . get_string('clear_warning_title', 'assignsubmission_cheqmate') . '</strong> ';
        echo get_string('clear_warning_body', 'assignsubmission_cheqmate');
        echo '</div>';

        // Clear form
        $clear_url = new moodle_url('/mod/assign/submission/cheqmate/clear_assignment.php');

        echo '<form method="post" action="' . $clear_url->out(false) . '" id="clear-form">';
        echo '<input type="hidden" name="courseid"     value="' . (int)$courseid . '">';
        echo '<input type="hidden" name="assignmentid" value="' . (int)$assignmentid . '">';
        echo '<input type="hidden" name="action"       value="clear">';
        echo '<input type="hidden" name="sesskey"      value="' . sesskey() . '">';

        echo '<div class="d-flex align-items-center" style="gap:12px;">';
        echo '<button type="submit" class="btn btn-danger" id="clear-btn">'
            . get_string('clear_all_submissions', 'assignsubmission_cheqmate')
            . ' (' . $total . ' ' . get_string('students_label', 'assignsubmission_cheqmate') . ')'
            . '</button>';
        echo '<span class="text-muted" style="font-size:0.85em;">'
            . get_string('clear_irreversible', 'assignsubmission_cheqmate') . '</span>';
        echo '</div>';

        echo '</form>';
    }

    echo '</div></div>'; // card-body / card
}
?>
<script>
(function () {
    var clearForm = document.getElementById('clear-form');
    if (clearForm) {
        clearForm.addEventListener('submit', function (e) {
            var assignName = document.getElementById('assignmentid');
            var name = assignName
                ? assignName.options[assignName.selectedIndex]
                    ? assignName.options[assignName.selectedIndex].text
                    : ''
                : '';
            var msg = '<?php echo get_string('clear_confirm', 'assignsubmission_cheqmate'); ?>';
            if (!confirm(msg)) {
                e.preventDefault();
            }
        });
    }
}());
</script>
<?php
echo $OUTPUT->footer();