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

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

require_login($course);
$context = context_course::instance($courseid);
require_capability('moodle/course:update', $context);

$PAGE->set_url('/mod/assign/submission/cheqmate/course_settings.php', ['courseid' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('course_settings', 'assignsubmission_cheqmate'));
$PAGE->set_heading($course->fullname . ' - ' . get_string('course_settings', 'assignsubmission_cheqmate'));

// Handle file deletion
if ($action == 'delete' && $deleteid) {
    require_sesskey();
    $DB->delete_records('cheqmate_global_source', ['id' => $deleteid, 'courseid' => $courseid]);
    redirect(new moodle_url('/mod/assign/submission/cheqmate/course_settings.php', ['courseid' => $courseid]),
        get_string('global_source_deleted', 'assignsubmission_cheqmate'), null, \core\output\notification::NOTIFY_SUCCESS);
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
            
            $api_url = get_config('assignsubmission_cheqmate', 'api_url') ?: 'http://localhost:8000';
            $endpoint = $api_url . '/global-source/upload';
            
            $payload = json_encode([
                'course_id' => $courseid,
                'file_path' => $temppath,
                'filename' => $filename
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
                $record = new stdClass();
                $record->courseid = $courseid;
                $record->filename = $filename;
                $record->contenthash = $contenthash;
                $record->fingerprint = '';
                $record->timecreated = time();
                $record->userid = $USER->id;
                $DB->insert_record('cheqmate_global_source', $record);
            }
        }
    }
    
    redirect(new moodle_url('/mod/assign/submission/cheqmate/course_settings.php', ['courseid' => $courseid]),
        get_string('settings_saved', 'assignsubmission_cheqmate'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Output
echo $OUTPUT->header();

// Display existing global sources
echo $OUTPUT->heading(get_string('global_source_list', 'assignsubmission_cheqmate'), 3);

$sources = $DB->get_records('cheqmate_global_source', ['courseid' => $courseid], 'timecreated DESC');

if ($sources) {
    $table = new html_table();
    $table->head = ['Filename', 'Uploaded By', 'Date', 'Actions'];
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
        
        $table->data[] = [$source->filename, $username, $date, $deletelink];
    }
    echo html_writer::table($table);
} else {
    echo html_writer::div(get_string('global_source_none', 'assignsubmission_cheqmate'), 'alert alert-info');
}

echo html_writer::empty_tag('hr');

// Display form
$settingsform->display();

echo $OUTPUT->footer();
