<?php
/**
 * Course settings page for CheqMate - Global Source Upload and Skip Patterns
 */

require_once('../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/formslib.php');

$courseid = required_param('courseid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$deleteid = optional_param('deleteid', 0, PARAM_INT);
$gradeid = optional_param('gradeid', 0, PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

require_login($course);
$context = context_course::instance($courseid);
require_capability('moodle/course:update', $context);

$PAGE->set_url('/mod/assign/submission/cheqmate/course_settings.php', ['courseid' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('course_settings', 'assignsubmission_cheqmate'));
$PAGE->set_heading($course->fullname . ' - ' . get_string('course_settings', 'assignsubmission_cheqmate'));

// Auto-patch Moodle DB to add is_grading and sections columns if not exists
$dbman = $DB->get_manager();
$table_gs = new xmldb_table('cheqmate_global_source');
if ($dbman->table_exists($table_gs)) {
    $field_is_grading = new xmldb_field('is_grading', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'userid');
    if (!$dbman->field_exists($table_gs, $field_is_grading)) {
        $dbman->add_field($table_gs, $field_is_grading);
    }
    $field_sections = new xmldb_field('sections', XMLDB_TYPE_TEXT, null, null, null, null, null, 'is_grading');
    if (!$dbman->field_exists($table_gs, $field_sections)) {
        $dbman->add_field($table_gs, $field_sections);
    }
}

// Auto-patch Moodle DB to add auto_grade_mode and grading_section_tag columns if not exists
$table_ac = new xmldb_table('assignsubmission_cheqmate');
if ($dbman->table_exists($table_ac)) {
    $field_mode = new xmldb_field('auto_grade_mode', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'disabled', 'minimum_mark');
    if (!$dbman->field_exists($table_ac, $field_mode)) {
        $dbman->add_field($table_ac, $field_mode);
    }
    $field_tag = new xmldb_field('grading_section_tag', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'auto_grade_mode');
    if (!$dbman->field_exists($table_ac, $field_tag)) {
        $dbman->add_field($table_ac, $field_tag);
    }
    $field_strict = new xmldb_field('grading_strictness', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '50', 'grading_section_tag');
    if (!$dbman->field_exists($table_ac, $field_strict)) {
        $dbman->add_field($table_ac, $field_strict);
    }
}

// Handle viewing PDF from the engine
if ($action == 'viewpdf' && $gradeid) {
    $source = $DB->get_record('cheqmate_global_source', ['id' => $gradeid, 'courseid' => $courseid]);
    if ($source) {
        $api_url = get_config('assignsubmission_cheqmate', 'api_url') ?: 'http://localhost:8000';
        $url = $api_url . '/global-source/download/' . $courseid . '/' . rawurlencode($source->filename);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $pdf_content = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 200) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $source->filename . '"');
            header('Content-Length: ' . strlen($pdf_content));
            echo $pdf_content;
            exit;
        } else {
            print_error('File not found on the CheqMate engine.');
        }
    } else {
        print_error('Invalid global source ID.');
    }
}

// Handle saving custom manual sections via AJAX
if ($action == 'savesections' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    header('Content-Type: application/json');
    
    $gradeid = required_param('gradeid', PARAM_INT);
    $sections_raw = required_param('sections', PARAM_RAW); // JSON array string
    
    // Verify sections JSON
    $sections_decoded = json_decode($sections_raw, true);
    if ($sections_decoded === null) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
        exit;
    }
    
    $source = $DB->get_record('cheqmate_global_source', ['id' => $gradeid, 'courseid' => $courseid]);
    if ($source) {
        // Save to Moodle DB
        $source->sections = $sections_raw;
        $DB->update_record('cheqmate_global_source', $source);
        
        // Sync to engine
        $api_url = get_config('assignsubmission_cheqmate', 'api_url') ?: 'http://localhost:8000';
        $endpoint = $api_url . '/global-source/update-sections';
        
        $payload = json_encode([
            'course_id' => (int) $courseid,
            'filename' => $source->filename,
            'sections' => $sections_raw
        ]);
        
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpcode == 200) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to sync with engine. Code: ' . $httpcode]);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Global source not found']);
    }
    exit;
}

// Handle file deletion
if ($action == 'delete' && $deleteid) {
    require_sesskey();
    $DB->delete_records('cheqmate_global_source', ['id' => $deleteid, 'courseid' => $courseid]);
    redirect(new moodle_url('/mod/assign/submission/cheqmate/course_settings.php', ['courseid' => $courseid]),
        get_string('global_source_deleted', 'assignsubmission_cheqmate'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Handle setting grading document
if ($action == 'setgrading' && $gradeid) {
    require_sesskey();
    // Clear existing grading document for this course
    $DB->execute("UPDATE {cheqmate_global_source} SET is_grading = 0 WHERE courseid = ?", [$courseid]);
    // Set selected as grading
    $DB->execute("UPDATE {cheqmate_global_source} SET is_grading = 1 WHERE id = ? AND courseid = ?", [$gradeid, $courseid]);
    
    // Notify the engine of grading document selection
    $grading_source = $DB->get_record('cheqmate_global_source', ['id' => $gradeid]);
    if ($grading_source) {
        $api_url = get_config('assignsubmission_cheqmate', 'api_url') ?: 'http://localhost:8000';
        $endpoint = $api_url . '/global-source/set-grading';
        
        $payload = json_encode([
            'course_id' => $courseid,
            'filename' => $grading_source->filename
        ]);
        
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_exec($ch);
        curl_close($ch);
    }
    
    redirect(new moodle_url('/mod/assign/submission/cheqmate/course_settings.php', ['courseid' => $courseid]),
        "Grading document set successfully.", null, \core\output\notification::NOTIFY_SUCCESS);
}

// Settings form
class cheqmate_settings_form extends moodleform {
    public function definition() {
        $mform = $this->_form;
        $courseid = $this->_customdata['courseid'];
        
        $mform->addElement('hidden', 'courseid', $courseid);
        $mform->setType('courseid', PARAM_INT);
        
        // Skip patterns
        $mform->addElement('header', 'skip_header', get_string('skip_patterns', 'assignsubmission_cheqmate'));
        $mform->addElement('textarea', 'skip_patterns', get_string('skip_patterns', 'assignsubmission_cheqmate'), 
            ['rows' => 2, 'cols' => 50]);
        $mform->addHelpButton('skip_patterns', 'skip_patterns', 'assignsubmission_cheqmate');
        $mform->setType('skip_patterns', PARAM_TEXT);
        
        // Global source upload
        $mform->addElement('header', 'upload_header', get_string('global_source_upload', 'assignsubmission_cheqmate'));
        $mform->addElement('filepicker', 'global_source_file', get_string('global_source_upload', 'assignsubmission_cheqmate'), null,
            ['maxbytes' => 10485760, 'accepted_types' => ['.pdf', '.docx', '.doc', '.txt']]);
        $mform->addHelpButton('global_source_file', 'global_source_upload', 'assignsubmission_cheqmate');
        
        $this->add_action_buttons(true, get_string('savechanges'));
    }
}

// Load existing settings
$existing = $DB->get_record('cheqmate_course_settings', ['courseid' => $courseid]);
$settingsform = new cheqmate_settings_form(null, ['courseid' => $courseid]);
$settingsform->set_data(['skip_patterns' => $existing ? $existing->skip_patterns : '']);

if ($settingsform->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
} else if ($data = $settingsform->get_data()) {

    // Save settings
    if ($existing) {
        $existing->skip_patterns = $data->skip_patterns;
        $existing->timemodified = time();
        $DB->update_record('cheqmate_course_settings', $existing);
    } else {
        $record = new stdClass();
        $record->courseid = $courseid;
        $record->skip_patterns = $data->skip_patterns;
        $record->timecreated = time();
        $record->timemodified = time();
        $DB->insert_record('cheqmate_course_settings', $record);
    }

    // Handle file upload
    $fs = get_file_storage();
    $draftitemid = file_get_submitted_draft_itemid('global_source_file');

    if ($draftitemid) {
        $usercontext = context_user::instance($USER->id);
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'sortorder', false);

        foreach ($files as $file) {
            if ($file->is_directory() || $file->get_filesize() == 0) {
                continue;
            }

            $filename = $file->get_filename();
            $contenthash = $file->get_contenthash();

            $tempdir = make_temp_directory('cheqmate_global_source');
            $temppath = $tempdir . '/' . $contenthash . '_' . $filename;
            $file->copy_content_to($temppath);

            $normalized_temp = str_replace('\\', '/', $temppath);
            $normalized_dataroot = str_replace('\\', '/', $CFG->dataroot);
            $filecontent = $file->get_content();
            $base64_content = base64_encode($filecontent);

            $api_url = get_config('assignsubmission_cheqmate', 'api_url') ?: 'http://localhost:8000';
            $endpoint = $api_url . '/global-source/upload';

            $payload = json_encode([
                'course_id' => $courseid,
                'file_path' => $normalized_temp,
                'dataroot' => $normalized_dataroot,
                'filename' => $filename,
                'file_content' => $base64_content
            ]);

            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);

            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            @unlink($temppath);

            if ($httpcode == 200) {
                $res_data = json_decode($response, true);
                $sections_str = null;
                if ($res_data && !empty($res_data["sections"])) {
                    $sections_str = json_encode($res_data["sections"]);
                }

                $existing_source = $DB->get_record("cheqmate_global_source", [
                    "courseid" => $courseid,
                    "filename" => $filename
                ]);

                if ($existing_source) {
                    $existing_source->contenthash = $contenthash;
                    $existing_source->timecreated = time();
                    $existing_source->userid = $USER->id;
                    if ($sections_str !== null) {
                        $existing_source->sections = $sections_str;
                    }
                    $DB->update_record("cheqmate_global_source", $existing_source);
                    $manual_id = $existing_source->id;
                } else {
                    $record = new stdClass();
                    $record->courseid = $courseid;
                    $record->filename = $filename;
                    $record->contenthash = $contenthash;
                    $record->fingerprint = "";
                    $record->timecreated = time();
                    $record->userid = $USER->id;
                    $record->is_grading = 0;
                    $record->sections = $sections_str;
                    $manual_id = $DB->insert_record("cheqmate_global_source", $record);
                }

                // Save to Moodle permanent filearea
                $context_course = context_course::instance($courseid);
                $fs->delete_area_files($context_course->id, 'assignsubmission_cheqmate', 'global_source', $manual_id);
                $file_record = array(
                    'contextid' => $context_course->id,
                    'component' => 'assignsubmission_cheqmate',
                    'filearea' => 'global_source',
                    'itemid' => $manual_id,
                    'filepath' => '/',
                    'filename' => $filename,
                    'userid' => $USER->id
                );
                $fs->create_file_from_storedfile($file_record, $file);
            }
        }
    }

    redirect(new moodle_url('/mod/assign/submission/cheqmate/course_settings.php', ['courseid' => $courseid]),
        get_string('settings_saved', 'assignsubmission_cheqmate'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Output
echo $OUTPUT->header();

// Display existing global sources
echo $OUTPUT->heading(get_string('global_source_list', 'assignsubmission_cheqmate'), 3);

$sources = $DB->get_records('cheqmate_global_source', ['courseid' => $courseid], 'timecreated DESC');

if ($sources) {
    $table = new html_table();
    $table->head = ['Filename', 'Uploaded By', 'Date', 'Grading Standard', 'Actions'];
    $table->attributes['class'] = 'generaltable';
    
    foreach ($sources as $source) {
        $user = $DB->get_record('user', ['id' => $source->userid]);
        $username = $user ? fullname($user) : 'Unknown';
        $date = userdate($source->timecreated);
        
        $deleteurl = new moodle_url('/mod/assign/submission/cheqmate/course_settings.php', [
            'courseid' => $courseid,
            'action' => 'delete',
            'deleteid' => $source->id,
            'sesskey' => sesskey()
        ]);
        $deletelink = html_writer::link($deleteurl, get_string('delete'), 
            ['class' => 'btn btn-danger btn-sm', 'onclick' => 'return confirm("Delete this global source?")']);
        
        $gradeurl = new moodle_url('/mod/assign/submission/cheqmate/course_settings.php', [
            'courseid' => $courseid,
            'action' => 'setgrading',
            'gradeid' => $source->id,
            'sesskey' => sesskey()
        ]);
        
        $is_grading = !empty($source->is_grading);
        if ($is_grading) {
            $statushtml = '<span class="badge badge-success" style="font-size: 0.9em; padding: 4px 8px; font-weight: bold; background-color: #28a745; color: white; border-radius: 4px;">Active Grading Doc</span>';
            $editsectionsbtn = ' <button class="btn btn-secondary btn-sm edit-sections-btn" data-id="' . $source->id . '" data-filename="' . s($source->filename) . '" data-sections="' . s($source->sections ?: '[]') . '">Edit Sections</button>';
            $statushtml .= $editsectionsbtn;
            $actionshtml = $deletelink;
        } else {
            $statushtml = '<span class="text-muted" style="font-size: 0.9em;">-</span>';
            $gradelink = html_writer::link($gradeurl, 'Set for Grading', ['class' => 'btn btn-primary btn-sm mr-2', 'style' => 'margin-right: 5px;']);
            $actionshtml = $gradelink . $deletelink;
        }
        
        $table->data[] = [$source->filename, $username, $date, $statushtml, $actionshtml];
    }
    echo html_writer::table($table);
} else {
    echo html_writer::div(get_string('global_source_none', 'assignsubmission_cheqmate'), 'alert alert-info');
}

echo html_writer::empty_tag('hr');

// Display form
$settingsform->display();

// Modal CSS & HTML/JS
echo '
<style>
#cheqmate-sections-modal .modal-dialog {
    max-width: 90% !important;
    width: 1200px !important;
}
#cheqmate-sections-modal .modal-body {
    display: flex;
    gap: 20px;
    height: 70vh;
    min-height: 500px;
}
#sections-left-col {
    flex: 4;
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    padding-right: 10px;
    border-right: 1px solid #ddd;
}
#sections-right-col {
    flex: 6;
    height: 100%;
}
.section-item-row {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 6px;
    padding: 10px;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.section-item-row input[type="text"] {
    flex: 2;
}
.section-item-row input[type="number"] {
    width: 70px;
}
</style>

<div class="modal fade" id="cheqmate-sections-modal" tabindex="-1" role="dialog" aria-hidden="true" style="display:none;">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-title-text">Edit Sections for Global Manual</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close" onclick="closeCheqmateModal()">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div id="sections-left-col">
                    <h6>Experiment tags & page ranges:</h6>
                    <div id="sections-list-container"></div>
                    <button class="btn btn-secondary btn-sm mt-2" onclick="addSectionRow()">+ Add Section</button>
                </div>
                <div id="sections-right-col">
                    <iframe id="modal-pdf-viewer" src="" style="width:100%; height:100%; border:none;"></iframe>
                </div>
            </div>
            <div class="modal-footer">
                <span id="save-status-msg" style="margin-right:15px; font-weight:bold;"></span>
                <button type="button" class="btn btn-secondary" onclick="closeCheqmateModal()">Close</button>
                <button type="button" class="btn btn-primary" id="save-sections-btn" onclick="saveSectionsData()">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    function init() {
        if (typeof require === "function") {
            require(["jquery"], function($) {
                setupCheqmateSections($);
            });
        } else if (typeof jQuery !== "undefined") {
            setupCheqmateSections(jQuery);
        } else {
            setTimeout(init, 100);
        }
    }
    
    function setupCheqmateSections($) {
        let currentGradeId = null;
        let currentSections = [];

        $(document).on("click", ".edit-sections-btn", function(e) {
            e.preventDefault();
            currentGradeId = $(this).attr("data-id");
            const filename = $(this).attr("data-filename");
            const sectionsRaw = $(this).attr("data-sections");
            
            try {
                currentSections = JSON.parse(sectionsRaw);
            } catch (err) {
                currentSections = [];
            }
            
            $("#modal-title-text").text("Edit Sections for " + filename);
            
            const pdfUrl = "course_settings.php?courseid=' . $courseid . '&action=viewpdf&gradeid=" + currentGradeId;
            $("#modal-pdf-viewer").attr("src", pdfUrl);
            
            renderSectionsList();
            $("#cheqmate-sections-modal").modal("show");
        });

        function renderSectionsList() {
            const container = document.getElementById("sections-list-container");
            container.innerHTML = "";
            
            if (currentSections.length === 0) {
                container.innerHTML = "<p class=\"text-muted\" id=\"no-sections-alert\">No experiment sections configured yet. Add one below.</p>";
                return;
            }
            
            currentSections.forEach((sec, idx) => {
                const row = document.createElement("div");
                row.className = "section-item-row";
                row.dataset.index = idx;
                
                row.innerHTML = `
                    <input type="text" class="form-control form-control-sm sec-tag-input" value="${sec.tag || ""}" placeholder="e.g. Experiment 1" required>
                    <span class="text-muted small">Pages:</span>
                    <input type="number" class="form-control form-control-sm sec-start-input" value="${sec.start_page || 1}" min="1" required>
                    <span class="text-muted small">to</span>
                    <input type="number" class="form-control form-control-sm sec-end-input" value="${sec.end_page || 1}" min="1" required>
                    <button class="btn btn-danger btn-sm" onclick="deleteSectionRow(${idx})" type="button">&times;</button>
                `;
                container.appendChild(row);
            });
        }

        window.addSectionRow = function() {
            const alert = document.getElementById("no-sections-alert");
            if (alert) alert.remove();
            
            syncCurrentSections();
            
            let lastEnd = 0;
            if (currentSections.length > 0) {
                lastEnd = parseInt(currentSections[currentSections.length - 1].end_page) || 0;
            }
            
            currentSections.push({
                tag: "Experiment " + (currentSections.length + 1),
                start_page: lastEnd + 1,
                end_page: lastEnd + 5
            });
            
            renderSectionsList();
        };

        window.deleteSectionRow = function(idx) {
            syncCurrentSections();
            currentSections.splice(idx, 1);
            renderSectionsList();
        };

        function syncCurrentSections() {
            const rows = document.querySelectorAll(".section-item-row");
            const updated = [];
            
            rows.forEach(row => {
                const tag = row.querySelector(".sec-tag-input").value.trim();
                const start = parseInt(row.querySelector(".sec-start-input").value) || 1;
                const end = parseInt(row.querySelector(".sec-end-input").value) || 1;
                
                if (tag) {
                    updated.push({
                        tag: tag,
                        start_page: start,
                        end_page: end
                    });
                }
            });
            
            currentSections = updated;
        }

        window.closeCheqmateModal = function() {
            $("#cheqmate-sections-modal").modal("hide");
            $("#modal-pdf-viewer").attr("src", "");
        };

        window.saveSectionsData = function() {
            syncCurrentSections();
            
            for (let i = 0; i < currentSections.length; i++) {
                const sec = currentSections[i];
                if (sec.start_page > sec.end_page) {
                    alert("Error in " + sec.tag + ": Start page cannot be greater than End page.");
                    return;
                }
            }
            
            const saveBtn = document.getElementById("save-sections-btn");
            const statusMsg = document.getElementById("save-status-msg");
            
            saveBtn.disabled = true;
            statusMsg.style.color = "orange";
            statusMsg.innerText = "Saving...";
            
            const fd = new FormData();
            fd.append("gradeid", currentGradeId);
            fd.append("sections", JSON.stringify(currentSections));
            fd.append("sesskey", "' . sesskey() . '");
            
            fetch("course_settings.php?courseid=' . $courseid . '&action=savesections", {
                method: "POST",
                body: fd
            })
            .then(res => res.json())
            .then(data => {
                saveBtn.disabled = false;
                if (data.status === "success") {
                    statusMsg.style.color = "green";
                    statusMsg.innerText = "Saved successfully!";
                    
                    const editBtn = document.querySelector(`.edit-sections-btn[data-id="${currentGradeId}"]`);
                    if (editBtn) {
                        editBtn.setAttribute("data-sections", JSON.stringify(currentSections));
                    }
                    
                    setTimeout(() => {
                        closeCheqmateModal();
                        statusMsg.innerText = "";
                    }, 1000);
                } else {
                    statusMsg.style.color = "red";
                    statusMsg.innerText = "Error: " + (data.message || "Unknown error");
                }
            })
            .catch(err => {
                saveBtn.disabled = false;
                statusMsg.style.color = "red";
                statusMsg.innerText = "Network Error.";
                console.error(err);
            });
        };
    }

    init();
})();
</script>
';

echo $OUTPUT->footer();
