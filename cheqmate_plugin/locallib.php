<?php

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

class assign_submission_cheqmate extends assign_submission_plugin {

    public function get_name() {
        return get_string('pluginname', 'assignsubmission_cheqmate');
    }

    public function get_settings(\MoodleQuickForm $mform) {
        global $DB;
        
        // Auto-patch Moodle DB to add auto_grade_mode and grading_section_tag columns if not exists
        $dbman = $DB->get_manager();
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

        $settings = null;
        $assignment_id = 0;
        
        // Safely get assignment instance (may throw error for NEW assignments)
        try {
            $instance = $this->assignment->get_instance();
            if ($instance && isset($instance->id)) {
                $assignment_id = $instance->id;
                $settings = $DB->get_record('assignsubmission_cheqmate', ['assignment' => $assignment_id]);
            }
        } catch (Exception $e) {
            $instance = null;
        } catch (TypeError $e) {
            $instance = null;
        }
        
        // NOTE: We do NOT add our own "Enable" checkbox - Moodle provides one automatically
        // for submission plugins (the "CheqMate Plagiarism Checker" checkbox)

        // Plagiarism threshold
        $mform->addElement('text', 'assignsubmission_cheqmate_threshold', get_string('plagiarism_threshold', 'assignsubmission_cheqmate'));
        $mform->setType('assignsubmission_cheqmate_threshold', PARAM_INT);
        $mform->setDefault('assignsubmission_cheqmate_threshold', $settings ? $settings->plagiarism_threshold : 50);
        $mform->addHelpButton('assignsubmission_cheqmate_threshold', 'plagiarism_threshold', 'assignsubmission_cheqmate');
        $mform->hideIf('assignsubmission_cheqmate_threshold', 'assignsubmission_cheqmate_enabled', 'notchecked');

        // AI Detection toggle
        $mform->addElement('advcheckbox', 'assignsubmission_cheqmate_check_ai', get_string('check_ai', 'assignsubmission_cheqmate'));
        $mform->setDefault('assignsubmission_cheqmate_check_ai', $settings ? $settings->check_ai : 1);
        $mform->hideIf('assignsubmission_cheqmate_check_ai', 'assignsubmission_cheqmate_enabled', 'notchecked');

        // Peer-to-peer comparison toggle
        $mform->addElement('advcheckbox', 'assignsubmission_cheqmate_peer_comparison', get_string('enable_peer_comparison', 'assignsubmission_cheqmate'));
        $mform->setDefault('assignsubmission_cheqmate_peer_comparison', $settings ? $settings->enable_peer_comparison : 1);
        $mform->addHelpButton('assignsubmission_cheqmate_peer_comparison', 'enable_peer_comparison', 'assignsubmission_cheqmate');
        $mform->hideIf('assignsubmission_cheqmate_peer_comparison', 'assignsubmission_cheqmate_enabled', 'notchecked');

        // Global source comparison toggle
        $mform->addElement('advcheckbox', 'assignsubmission_cheqmate_global_source', get_string('check_global_source', 'assignsubmission_cheqmate'));
        $mform->setDefault('assignsubmission_cheqmate_global_source', $settings ? $settings->check_global_source : 0);
        $mform->addHelpButton('assignsubmission_cheqmate_global_source', 'check_global_source', 'assignsubmission_cheqmate');
        $mform->hideIf('assignsubmission_cheqmate_global_source', 'assignsubmission_cheqmate_enabled', 'notchecked');

        // Student view toggle
        $mform->addElement('advcheckbox', 'assignsubmission_cheqmate_student_view', get_string('student_view', 'assignsubmission_cheqmate'));
        $mform->setDefault('assignsubmission_cheqmate_student_view', $settings ? $settings->student_view : 0);
        $mform->addHelpButton('assignsubmission_cheqmate_student_view', 'student_view', 'assignsubmission_cheqmate');
        $mform->hideIf('assignsubmission_cheqmate_student_view', 'assignsubmission_cheqmate_enabled', 'notchecked');

        // --- Punctuality Auto-Grading Settings ---
        $mform->addElement('header', 'assignsubmission_cheqmate_punctuality_hdr', get_string('punctuality_settings', 'assignsubmission_cheqmate'));

        $mform->addElement('advcheckbox', 'assignsubmission_cheqmate_auto_grading_enabled', get_string('auto_grading_enabled', 'assignsubmission_cheqmate'));
        $mform->setDefault('assignsubmission_cheqmate_auto_grading_enabled', ($settings && isset($settings->auto_grading_enabled)) ? $settings->auto_grading_enabled : 0);
        $mform->hideIf('assignsubmission_cheqmate_auto_grading_enabled', 'assignsubmission_cheqmate_enabled', 'notchecked');

        $mform->addElement('text', 'assignsubmission_cheqmate_criteria_name', get_string('criteria_name', 'assignsubmission_cheqmate'));
        $mform->setType('assignsubmission_cheqmate_criteria_name', PARAM_TEXT);
        $mform->setDefault('assignsubmission_cheqmate_criteria_name', ($settings && isset($settings->criteria_name)) ? $settings->criteria_name : 'Punctuality');
        $mform->hideIf('assignsubmission_cheqmate_criteria_name', 'assignsubmission_cheqmate_auto_grading_enabled', 'notchecked');

        $mform->addElement('text', 'assignsubmission_cheqmate_deduction_amount', get_string('deduction_amount', 'assignsubmission_cheqmate'));
        $mform->setType('assignsubmission_cheqmate_deduction_amount', PARAM_FLOAT);
        $mform->setDefault('assignsubmission_cheqmate_deduction_amount', ($settings && isset($settings->deduction_amount)) ? $settings->deduction_amount : 0.1);
        $mform->hideIf('assignsubmission_cheqmate_deduction_amount', 'assignsubmission_cheqmate_auto_grading_enabled', 'notchecked');

        $mform->addElement('text', 'assignsubmission_cheqmate_deduction_interval', get_string('deduction_interval_days', 'assignsubmission_cheqmate'));
        $mform->setType('assignsubmission_cheqmate_deduction_interval', PARAM_INT);
        $mform->setDefault('assignsubmission_cheqmate_deduction_interval', ($settings && isset($settings->deduction_interval)) ? $settings->deduction_interval : 1);
        $mform->hideIf('assignsubmission_cheqmate_deduction_interval', 'assignsubmission_cheqmate_auto_grading_enabled', 'notchecked');

        $mform->addElement('text', 'assignsubmission_cheqmate_start_deducting_after', get_string('start_deducting_after_days', 'assignsubmission_cheqmate'));
        $mform->setType('assignsubmission_cheqmate_start_deducting_after', PARAM_INT);
        $mform->setDefault('assignsubmission_cheqmate_start_deducting_after', ($settings && isset($settings->start_deducting_after)) ? $settings->start_deducting_after : 3);
        $mform->hideIf('assignsubmission_cheqmate_start_deducting_after', 'assignsubmission_cheqmate_auto_grading_enabled', 'notchecked');

        $mform->addElement('text', 'assignsubmission_cheqmate_minimum_mark', get_string('minimum_mark', 'assignsubmission_cheqmate'));
        $mform->setType('assignsubmission_cheqmate_minimum_mark', PARAM_FLOAT);
        $mform->setDefault('assignsubmission_cheqmate_minimum_mark', ($settings && isset($settings->minimum_mark)) ? $settings->minimum_mark : 1.0);
        $mform->hideIf('assignsubmission_cheqmate_minimum_mark', 'assignsubmission_cheqmate_auto_grading_enabled', 'notchecked');

        // --- Topic Knowledge & Lab Performance Auto-Grading Settings ---
        $mform->addElement('header', 'assignsubmission_cheqmate_grading_hdr', 'Auto-Grading Criteria Mapping');
        
        $mode_options = [
            'disabled' => 'Disable Auto Grading (Rubric)',
            'full' => 'Full Auto Grade (Compare against whole manual)',
            'specific' => 'Specific Section / Experiment Only'
        ];
        $mform->addElement('select', 'assignsubmission_cheqmate_auto_grade_mode', 'Auto-Grading Mode', $mode_options);
        $mform->setDefault('assignsubmission_cheqmate_auto_grade_mode', ($settings && isset($settings->auto_grade_mode)) ? $settings->auto_grade_mode : 'disabled');
        $mform->hideIf('assignsubmission_cheqmate_auto_grade_mode', 'assignsubmission_cheqmate_enabled', 'notchecked');

        $section_options = ['' => '-- Select Experiment Section --'];
        try {
            $courseid = $this->assignment->get_course()->id;
            $grading_manual = $DB->get_record('cheqmate_global_source', ['courseid' => $courseid, 'is_grading' => 1]);
            if ($grading_manual && !empty($grading_manual->sections)) {
                $sections = json_decode($grading_manual->sections, true);
                if (is_array($sections)) {
                    foreach ($sections as $sec) {
                        if (isset($sec['tag'])) {
                            $section_options[$sec['tag']] = $sec['tag'] . ' (Pages ' . $sec['start_page'] . '-' . $sec['end_page'] . ')';
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Safe fallback
        }

        $mform->addElement('select', 'assignsubmission_cheqmate_grading_section_tag', 'Grading Experiment Section', $section_options);
        $mform->setDefault('assignsubmission_cheqmate_grading_section_tag', ($settings && isset($settings->grading_section_tag)) ? $settings->grading_section_tag : '');
        $mform->hideIf('assignsubmission_cheqmate_grading_section_tag', 'assignsubmission_cheqmate_enabled', 'notchecked');
        $mform->hideIf('assignsubmission_cheqmate_grading_section_tag', 'assignsubmission_cheqmate_auto_grade_mode', 'neq', 'specific');

        $mform->addElement('text', 'assignsubmission_cheqmate_grading_strictness', get_string('grading_strictness', 'assignsubmission_cheqmate'));
        $mform->setType('assignsubmission_cheqmate_grading_strictness', PARAM_INT);
        $mform->setDefault('assignsubmission_cheqmate_grading_strictness', ($settings && isset($settings->grading_strictness)) ? $settings->grading_strictness : 50);
        $mform->addHelpButton('assignsubmission_cheqmate_grading_strictness', 'grading_strictness', 'assignsubmission_cheqmate');
        $mform->hideIf('assignsubmission_cheqmate_grading_strictness', 'assignsubmission_cheqmate_enabled', 'notchecked');
        $mform->hideIf('assignsubmission_cheqmate_grading_strictness', 'assignsubmission_cheqmate_auto_grade_mode', 'eq', 'disabled');
    }

    public function get_form_elements($submission, \MoodleQuickForm $mform, stdClass $data) {
        return false;
    }

    public function save_settings(stdClass $data) {
        global $DB;

        try {
            $instance = $this->assignment->get_instance();
            if (!$instance || !isset($instance->id)) {
                return true;
            }
        } catch (Exception $e) {
            return true;
        } catch (TypeError $e) {
            return true;
        }

        $record = new stdClass();
        $record->assignment = $instance->id;
        // Use Moodle's built-in enabled state
        $record->enabled = !empty($data->assignsubmission_cheqmate_enabled) ? 1 : 0;
        $record->plagiarism_threshold = isset($data->assignsubmission_cheqmate_threshold) ? $data->assignsubmission_cheqmate_threshold : 50;
        $record->check_ai = !empty($data->assignsubmission_cheqmate_check_ai) ? 1 : 0;
        $record->student_view = !empty($data->assignsubmission_cheqmate_student_view) ? 1 : 0;
        $record->check_global_source = !empty($data->assignsubmission_cheqmate_global_source) ? 1 : 0;
        $record->enable_peer_comparison = !empty($data->assignsubmission_cheqmate_peer_comparison) ? 1 : 0;

        // Auto-grading settings
        $record->auto_grading_enabled = !empty($data->assignsubmission_cheqmate_auto_grading_enabled) ? 1 : 0;
        $record->criteria_name = isset($data->assignsubmission_cheqmate_criteria_name) ? $data->assignsubmission_cheqmate_criteria_name : 'Punctuality';
        $record->deduction_amount = isset($data->assignsubmission_cheqmate_deduction_amount) ? (float)$data->assignsubmission_cheqmate_deduction_amount : 0.1;
        $record->deduction_interval = isset($data->assignsubmission_cheqmate_deduction_interval) ? (int)$data->assignsubmission_cheqmate_deduction_interval : 1;
        $record->start_deducting_after = isset($data->assignsubmission_cheqmate_start_deducting_after) ? (int)$data->assignsubmission_cheqmate_start_deducting_after : 3;
        $record->minimum_mark = isset($data->assignsubmission_cheqmate_minimum_mark) ? (float)$data->assignsubmission_cheqmate_minimum_mark : 1.0;
        $record->auto_grade_mode = isset($data->assignsubmission_cheqmate_auto_grade_mode) ? $data->assignsubmission_cheqmate_auto_grade_mode : 'disabled';
        $record->grading_section_tag = isset($data->assignsubmission_cheqmate_grading_section_tag) ? $data->assignsubmission_cheqmate_grading_section_tag : '';
        $record->grading_strictness = isset($data->assignsubmission_cheqmate_grading_strictness) ? (int)$data->assignsubmission_cheqmate_grading_strictness : 50;

        // Auto-patch Moodle DB if columns don't exist (in case plugin upgrade wasn't clicked in admin)
        $dbman = $DB->get_manager();
        $table = new xmldb_table('assignsubmission_cheqmate');
        if ($dbman->table_exists($table)) {
            $fields_to_add = [
                new xmldb_field('auto_grading_enabled', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0'),
                new xmldb_field('criteria_name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, 'Punctuality'),
                new xmldb_field('deduction_amount', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0.10'),
                new xmldb_field('deduction_interval', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1'),
                new xmldb_field('start_deducting_after', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '3'),
                new xmldb_field('minimum_mark', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '1.00'),
                new xmldb_field('auto_grade_mode', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'disabled'),
                new xmldb_field('grading_section_tag', XMLDB_TYPE_CHAR, '255', null, null, null, null),
                new xmldb_field('grading_strictness', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '50')
            ];
            foreach ($fields_to_add as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
        }

        if ($old = $DB->get_record('assignsubmission_cheqmate', ['assignment' => $record->assignment])) {
            $record->id = $old->id;
            $DB->update_record('assignsubmission_cheqmate', $record);
        } else {
            $DB->insert_record('assignsubmission_cheqmate', $record);
        }

        return true;
    }

    /**
     * Validate the submission form.
     * Prevents submission if no files are uploaded and the plugin is enabled.
     */
    public function validate_submission_form(stdClass $submission, stdClass $data) {
        $errors = array();
        
        if (!$this->is_enabled()) {
            return $errors;
        }

        $fs = get_file_storage();
        $cm = $this->assignment->get_course_module();
        if (!$cm) {
            return $errors;
        }
        $context = context_module::instance($cm->id);
        
        // Check for existing files in the submission area
        $files = $fs->get_area_files($context->id, 'assignsubmission_file', 'submission_files', $submission->id, 'sortorder', false);
        
        $has_real_files = false;
        foreach ($files as $file) {
            if (!$file->is_directory() && $file->get_filesize() > 0) {
                $has_real_files = true;
                break;
            }
        }

        // If no existing files, check for files in the draft area (filemanager)
        if (!$has_real_files) {
            $draftitemid = 0;
            if (isset($data->assignsubmission_file_filemanager)) {
                $draftitemid = $data->assignsubmission_file_filemanager;
            } else if (isset($data->files_filemanager)) {
                $draftitemid = $data->files_filemanager;
            }

            if ($draftitemid) {
                $usercontext = context_user::instance($submission->userid);
                $draftfiles = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'sortorder', false);
                foreach ($draftfiles as $file) {
                    if (!$file->is_directory() && $file->get_filesize() > 0) {
                        $has_real_files = true;
                        break;
                    }
                }
            }
        }

        if (!$has_real_files) {
            $msg = get_string('error_no_files', 'assignsubmission_cheqmate');
            $errors['assignsubmission_file_filemanager'] = $msg;
            $errors['files_filemanager'] = $msg;
            $errors['files'] = $msg;
        }

        return $errors;
    }

    public function is_enabled() {
        global $DB;
        $this->check_db_schema();
        try {
            $instance = $this->assignment->get_instance();
            if (!$instance || !isset($instance->id)) {
                return false;
            }
            $record = $DB->get_record('assignsubmission_cheqmate', ['assignment' => $instance->id]);
            return $record && $record->enabled;
        } catch (Exception $e) {
            return false;
        } catch (TypeError $e) {
            return false;
        }
    }

    private function check_db_schema() {
        global $DB;
        $dbman = $DB->get_manager();
        $table_res = new xmldb_table('assignsub_cheqmate_res');
        if ($dbman->table_exists($table_res)) {
            $field_final = new xmldb_field('final_submitted', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'timecreated');
            if (!$dbman->field_exists($table_res, $field_final)) {
                $dbman->add_field($table_res, $field_final);
            }
        }
    }

    private function get_plugin_settings() {
        global $DB;
        try {
            $instance = $this->assignment->get_instance();
            if (!$instance || !isset($instance->id)) {
                return null;
            }
            return $DB->get_record('assignsubmission_cheqmate', ['assignment' => $instance->id]);
        } catch (Exception $e) {
            return null;
        } catch (TypeError $e) {
            return null;
        }
    }

    private function get_skip_patterns() {
        global $DB;
        $courseid = $this->assignment->get_course()->id;
        $settings = $DB->get_record('cheqmate_course_settings', ['courseid' => $courseid]);
        
        if ($settings && !empty($settings->skip_patterns)) {
            return array_map('trim', explode(',', $settings->skip_patterns));
        }
        return [];
    }

    public function submit_for_grading($submission) {
        return true;
    }

    public function save(stdClass $submission, stdClass $data) {
    global $DB, $CFG;
    $this->check_db_schema();

    if (!$this->is_enabled()) {
        return true;
    }

    $settings = $this->get_plugin_settings();
    if (!$settings || !$settings->enabled) {
        return true;
    }

    $fs = get_file_storage();
    $context = context_module::instance($this->assignment->get_course_module()->id);
    $files = $fs->get_area_files($context->id, 'assignsubmission_file', 'submission_files', $submission->id, 'sortorder', false);
    
    if (empty($files) && (isset($data->files_filemanager) || isset($data->assignsubmission_file_filemanager))) {
        $draftitemid = isset($data->files_filemanager) ? $data->files_filemanager : $data->assignsubmission_file_filemanager;
        $usercontext = context_user::instance($submission->userid);
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'sortorder', false);
    }

    if (empty($files)) {
        throw new moodle_exception('error_no_files', 'assignsubmission_cheqmate');
    }

    $tempdir = make_temp_directory('assignsubmission_cheqmate');
    $courseid = $this->assignment->get_course()->id;
    $assignmentid = $this->assignment->get_instance()->id;
    $skip_patterns = $this->get_skip_patterns();

    foreach ($files as $file) {

        if ($file->is_directory() || $file->get_filesize() == 0) {
            continue;
        }

        $contenthash = $file->get_contenthash();
        $filename = $file->get_filename();
        $tempfilepath = $tempdir . '/' . $contenthash . '_' . $filename;
        $file->copy_content_to($tempfilepath);

        $normalized_temp = str_replace('\\', '/', $tempfilepath);
        $normalized_dataroot = str_replace('\\', '/', $CFG->dataroot);
        $filecontent = $file->get_content();
        $base64_content = base64_encode($filecontent);

        $api_url = get_config('assignsubmission_cheqmate', 'api_url') ?: 'http://localhost:8000';
        $endpoint = $api_url . '/analyze';

        $payload = json_encode([
            'file_path' => $normalized_temp,
            'dataroot' => $normalized_dataroot,
            'submission_id' => $submission->id,
            'context_id' => $context->id,
            'assignment_id' => $assignmentid,
            'course_id' => $courseid,
            'check_global_source' => (bool) $settings->check_global_source,
            'enable_peer_comparison' => (bool) $settings->enable_peer_comparison,
            'skip_patterns' => $skip_patterns,
            'file_content' => $base64_content,
            'section_tag' => ($settings->auto_grade_mode == 'specific') ? $settings->grading_section_tag : null,
            'grading_strictness' => isset($settings->grading_strictness) ? (int)$settings->grading_strictness : 50
        ]);

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode === 0 || $httpcode >= 500) {
            @unlink($tempfilepath);
            throw new moodle_exception('error_connection', 'assignsubmission_cheqmate');
        }

        if ($httpcode !== 200) {
            @unlink($tempfilepath);
            continue;
        }

        $result = json_decode($response, true);

        if (isset($result['status']) && $result['status'] == 'error') {
            @unlink($tempfilepath);
            throw new moodle_exception(
                'error_analysis',
                'assignsubmission_cheqmate',
                '',
                $result['message'] ?? 'Unknown error'
            );
        }

        // We no longer overwrite the original file to append the report. 
        // The Advanced Report is generated on-the-fly via advanced_report.php
        @unlink($tempfilepath);

        $plag_score = $result['plagiarism_score'] ?? 0;
        $ai_score = $result['ai_probability'] ?? 0;
        $threshold = $settings->plagiarism_threshold;

        // --- Plagiarism Blocking & Notification ---
        if (
            isset($result['status']) &&
            $result['status'] == 'processed' &&
            $plag_score > $threshold
        ) {
            // --- Teacher Notification ---
            $context = $this->assignment->get_context();
            // Find users who can grade (teachers/admins / Course Owners / Managers)
            // Using moodle/course:update targets users with manager/admin privileges or course editors.
            $teachers = get_users_by_capability($context, 'moodle/course:update', 'u.id, u.firstname, u.lastname, u.email, u.lang', '', '', '', '', '', false, true);
            
            // Fallback to graders if no course owners found
            if (empty($teachers)) {
                $teachers = get_users_by_capability($context, 'mod/assign:grade', 'u.id, u.firstname, u.lastname, u.email, u.lang', '', '', '', '', '', false, true);
            }
            
            if ($teachers) {
                $student = $DB->get_record('user', ['id' => $submission->userid], '*', IGNORE_MISSING);
                $student_name = $student ? fullname($student) : 'A student';
                $assignment_name = $this->assignment->get_instance()->name;
                
                foreach ($teachers as $teacher) {
                    $eventdata = new \core\message\message();
                    $eventdata->courseid          = $this->assignment->get_course()->id;
                    $eventdata->component         = 'assignsubmission_cheqmate';
                    $eventdata->name              = 'plagiarism_alert';
                    $eventdata->userfrom          = \core_user::get_noreply_user();
                    $eventdata->userto            = $teacher;
                    $eventdata->subject           = "Plagiarism Alert: {$student_name}";
                    $eventdata->fullmessage       = "Hello {$teacher->firstname},\n\nA submission for the assignment '{$assignment_name}' by {$student_name} has been automatically blocked by the CheqMate system.\n\nThe detected plagiarism score was {$plag_score}%, which exceeds your configured threshold of {$threshold}%.\n\nPlease review the submission dashboard for more details.";
                    $eventdata->fullmessageformat = FORMAT_PLAIN;
                    $eventdata->fullmessagehtml   = "<p>Hello {$teacher->firstname},</p><p>A submission for the assignment <b>'{$assignment_name}'</b> by <b>{$student_name}</b> has been automatically blocked by the CheqMate system.</p><p>The detected plagiarism score was <b>{$plag_score}%</b>, which exceeds your configured threshold of {$threshold}%.</p><p>Please review the submission dashboard for more details.</p>";
                    $eventdata->smallmessage      = "Plagiarism Alert: {$student_name} exceeded threshold ({$plag_score}%).";
                    $eventdata->notification      = 1;
                    
                    message_send($eventdata);
                }
            }
            // --- End Teacher Notification ---

            // Delete fingerprint to avoid self-match on re-upload
            $delete_endpoint = $api_url . '/fingerprint/' . $submission->id;
            $dch = curl_init($delete_endpoint);
            curl_setopt($dch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($dch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($dch, CURLOPT_TIMEOUT, 10);
            curl_exec($dch);
            curl_close($dch);

            // Ensure no stale result remains
            $DB->delete_records('assignsub_cheqmate_res', ['submission' => $submission->id]);

            $error_data = new stdClass();
            $error_data->score = $plag_score;
            $error_data->ai = $ai_score;
            $error_data->details = $this->format_match_details($result['details'] ?? []);

            throw new moodle_exception(
                'submission_blocked_detailed',
                'assignsubmission_cheqmate',
                '',
                $error_data
            );
        }

        // --- Save CheqMate Result (Only if not blocked) ---
        $record = new stdClass();
        $record->submission = $submission->id;
        $record->filehash = $contenthash;
        $record->plagiarism_score = $plag_score;
        $record->ai_probability = $ai_score;
        $record->report_path = '';
        $record->json_result = json_encode($result);
        $record->status = $result['status'] ?? 'processed';
        $record->timecreated = time();
        $record->final_submitted = 0;

        if ($old = $DB->get_record('assignsub_cheqmate_res', ['submission' => $submission->id])) {
            $record->id = $old->id;
            $DB->update_record('assignsub_cheqmate_res', $record);
        } else {
            $DB->insert_record('assignsub_cheqmate_res', $record);
        }

        if ($settings && !empty($settings->auto_grading_enabled)) {
            $plag_score = $result['plagiarism_score'] ?? 0;
            $ai_score = $result['ai_probability'] ?? 0;
            $combined = max($plag_score, $ai_score);

            if ($combined > 80) {
                // Generate a notification and skip auto grading!
                $context = $this->assignment->get_context();
                $teachers = get_users_by_capability($context, 'moodle/course:update', 'u.id, u.firstname, u.lastname, u.email, u.lang', '', '', '', '', '', false, true);
                if (empty($teachers)) {
                    $teachers = get_users_by_capability($context, 'mod/assign:grade', 'u.id, u.firstname, u.lastname, u.email, u.lang', '', '', '', '', '', false, true);
                }
                if ($teachers) {
                    $student = $DB->get_record('user', ['id' => $submission->userid], '*', IGNORE_MISSING);
                    $student_name = $student ? fullname($student) : 'A student';
                    $assignment_name = $this->assignment->get_instance()->name;
                    
                    foreach ($teachers as $teacher) {
                        $eventdata = new \core\message\message();
                        $eventdata->courseid          = $this->assignment->get_course()->id;
                        $eventdata->component         = 'assignsubmission_cheqmate';
                        $eventdata->name              = 'plagiarism_alert';
                        $eventdata->userfrom          = \core_user::get_noreply_user();
                        $eventdata->userto            = $teacher;
                        $eventdata->subject           = "Auto-Grading Suspended: {$student_name}";
                        $eventdata->fullmessage       = "Hello {$teacher->firstname},\n\nA submission for the assignment '{$assignment_name}' by {$student_name} was not auto-graded because the combined similarity/AI score was {$combined}%, which exceeds the 80% threshold.\n\nPlease review and grade this submission manually.";
                        $eventdata->fullmessageformat = FORMAT_PLAIN;
                        $eventdata->fullmessagehtml   = "<p>Hello {$teacher->firstname},</p><p>A submission for the assignment <b>'{$assignment_name}'</b> by <b>{$student_name}</b> was not auto-graded because the combined similarity/AI score was <b>{$combined}%</b>, which exceeds the 80% threshold.</p><p>Please review and grade this submission manually.</p>";
                        $eventdata->smallmessage      = "Auto-grading suspended for {$student_name} due to high similarity/AI score ({$combined}%).";
                        $eventdata->notification      = 1;
                        message_send($eventdata);
                    }
                }
            } else {
                // Proceed with auto grading
                $context = $this->assignment->get_context();
                $sql = "SELECT gd.id, gd.method FROM {grading_areas} ga JOIN {grading_definitions} gd ON ga.id = gd.areaid WHERE ga.contextid = ? AND ga.component = 'mod_assign' AND ga.areaname = 'submissions' AND gd.method = 'rubric' AND gd.status = 20";
                $definition = $DB->get_record_sql($sql, [$context->id]);

                if ($definition) {
                    // Check user grade and get/create instance
                    $grade = $this->assignment->get_user_grade($submission->userid, true);
                    if ($grade) {
                        $sql_inst = "SELECT id FROM {grading_instances} WHERE definitionid = ? AND itemid = ?";
                        $instances = $DB->get_records_sql($sql_inst, [$definition->id, $grade->id]);
                        $instance = $instances ? reset($instances) : null;

                        if (!$instance) {
                            $instance = new stdClass();
                            $instance->definitionid = $definition->id;
                            $instance->raterid = $submission->userid;
                            $instance->itemid = $grade->id;
                            $instance->status = 1;
                            $instance->timemodified = time();
                            $instance->id = $DB->insert_record('grading_instances', $instance);
                        }

                        $criteria_to_grade = [];

                        // 1. Punctuality
                        $punctuality_criteria_name = !empty($settings->criteria_name) ? $settings->criteria_name : 'Punctuality';
                        $clean_criteria_name = preg_replace('/[^a-zA-Z0-9]/', '', $punctuality_criteria_name);
                        $sql_crit = "SELECT id, description FROM {gradingform_rubric_criteria} WHERE definitionid = ? AND (LOWER(description) LIKE ? OR LOWER(description) LIKE ?)";
                        $criteria_records = $DB->get_records_sql($sql_crit, [
                            $definition->id, 
                            '%' . strtolower($punctuality_criteria_name) . '%',
                            '%' . strtolower($clean_criteria_name) . '%'
                        ]);
                        if (!$criteria_records) {
                            // Try common fallbacks for Punctuality
                            $sql_crit_fallback = "SELECT id, description FROM {gradingform_rubric_criteria} WHERE definitionid = ? AND (LOWER(description) LIKE '%punctual%' OR LOWER(description) LIKE '%late%' OR LOWER(description) LIKE '%submission%')";
                            $criteria_records = $DB->get_records_sql($sql_crit_fallback, [$definition->id]);
                        }
                        
                        if ($criteria_records) {
                            $criteria = reset($criteria_records);
                            $sql_levels = "SELECT id, score FROM {gradingform_rubric_levels} WHERE criterionid = ? ORDER BY score DESC";
                            $levels = $DB->get_records_sql($sql_levels, [$criteria->id]);
                            if ($levels) {
                                $max_level = reset($levels);
                                $max_score = $max_level->score;
                                $score = $max_score;

                                $timemodified = $submission->timemodified;
                                $duedate = $this->assignment->get_instance()->duedate;

                                if ($duedate > 0 && $timemodified > $duedate) {
                                    $seconds_late = $timemodified - $duedate;
                                    $days_late = floor($seconds_late / 86400);
                                    $grace_period = isset($settings->start_deducting_after) ? $settings->start_deducting_after : 0;

                                    if ($days_late >= $grace_period) {
                                        $intervals_late = floor(($days_late - $grace_period) / ($settings->deduction_interval ?: 1)) + 1;
                                        $deduction = $intervals_late * $settings->deduction_amount;
                                        $score = $max_score - $deduction;
                                        
                                        $min_bound = isset($settings->minimum_mark) ? $settings->minimum_mark : 1.0;
                                        if ($score < $min_bound) {
                                            $score = $min_bound;
                                        }
                                    }
                                }
                                $criteria_to_grade[] = [
                                    'criteria' => $criteria,
                                    'levels' => $levels,
                                    'score' => $score,
                                    'remark' => "Auto-graded: " . round($score, 2) . " points (Late deduction applied)"
                                ];
                            }
                        }

                        // 2. Topic Knowledge
                        if ($settings->auto_grade_mode !== 'disabled') {
                            $topic_score = $result['topic_knowledge_score'] ?? 3.0;
                            $sql_crit_tk = "SELECT id, description FROM {gradingform_rubric_criteria} WHERE definitionid = ? AND (LOWER(description) LIKE '%topic knowledge%' OR LOWER(description) LIKE '%knowledge%')";
                            $criteria_records_tk = $DB->get_records_sql($sql_crit_tk, [$definition->id]);
                            if (!$criteria_records_tk) {
                                // Fallbacks for Topic Knowledge
                                $sql_crit_tk_fallback = "SELECT id, description FROM {gradingform_rubric_criteria} WHERE definitionid = ? AND (LOWER(description) LIKE '%topic%' OR LOWER(description) LIKE '%experiment%' OR LOWER(description) LIKE '%exp%')";
                                $criteria_records_tk = $DB->get_records_sql($sql_crit_tk_fallback, [$definition->id]);
                            }
                            if ($criteria_records_tk) {
                                $criteria = reset($criteria_records_tk);
                                $sql_levels = "SELECT id, score FROM {gradingform_rubric_levels} WHERE criterionid = ? ORDER BY score DESC";
                                $levels = $DB->get_records_sql($sql_levels, [$criteria->id]);
                                if ($levels) {
                                    $criteria_to_grade[] = [
                                        'criteria' => $criteria,
                                        'levels' => $levels,
                                        'score' => $topic_score,
                                        'remark' => "Auto-graded: " . round($topic_score, 2) . " points (Topic Knowledge standard)"
                                    ];
                                }
                            }
                        }

                        // 3. Lab Performance
                        if ($settings->auto_grade_mode !== 'disabled') {
                            $lab_score = $result['lab_performance_score'] ?? 3.0;
                            $sql_crit_lp = "SELECT id, description FROM {gradingform_rubric_criteria} WHERE definitionid = ? AND (LOWER(description) LIKE '%lab performance%' OR LOWER(description) LIKE '%performance%')";
                            $criteria_records_lp = $DB->get_records_sql($sql_crit_lp, [$definition->id]);
                            if (!$criteria_records_lp) {
                                // Fallbacks for Lab Performance
                                $sql_crit_lp_fallback = "SELECT id, description FROM {gradingform_rubric_criteria} WHERE definitionid = ? AND (LOWER(description) LIKE '%performance%' OR LOWER(description) LIKE '%lab%' OR LOWER(description) LIKE '%practical%')";
                                $criteria_records_lp = $DB->get_records_sql($sql_crit_lp_fallback, [$definition->id]);
                            }
                            if ($criteria_records_lp) {
                                $criteria = reset($criteria_records_lp);
                                $sql_levels = "SELECT id, score FROM {gradingform_rubric_levels} WHERE criterionid = ? ORDER BY score DESC";
                                $levels = $DB->get_records_sql($sql_levels, [$criteria->id]);
                                if ($levels) {
                                    $criteria_to_grade[] = [
                                        'criteria' => $criteria,
                                        'levels' => $levels,
                                        'score' => $lab_score,
                                        'remark' => "Auto-graded: " . round($lab_score, 2) . " points (Lab Performance standard)"
                                    ];
                                }
                            }
                        }

                        // Apply the grades to fillings
                        foreach ($criteria_to_grade as $item) {
                            $crit = $item['criteria'];
                            $lvls = $item['levels'];
                            $score = $item['score'];
                            $remark = $item['remark'];

                            // Find closest level
                            $closest_level_id = null;
                            $min_diff = PHP_INT_MAX;
                            foreach ($lvls as $lvl) {
                                $diff = abs($lvl->score - $score);
                                if ($diff < $min_diff) {
                                    $min_diff = $diff;
                                    $closest_level_id = $lvl->id;
                                }
                            }

                            if ($closest_level_id !== null) {
                                $sql_fill = "SELECT id FROM {gradingform_rubric_fillings} WHERE instanceid = ? AND criterionid = ?";
                                $filling = $DB->get_record_sql($sql_fill, [$instance->id, $crit->id]);

                                $filling_record = new stdClass();
                                $filling_record->instanceid = $instance->id;
                                $filling_record->criterionid = $crit->id;
                                $filling_record->levelid = $closest_level_id;
                                $filling_record->remark = $remark;

                                if ($filling) {
                                    $filling_record->id = $filling->id;
                                    $DB->update_record('gradingform_rubric_fillings', $filling_record);
                                } else {
                                    $DB->insert_record('gradingform_rubric_fillings', $filling_record);
                                }
                            }
                        }
                    }
                }
            }
        }
        // --- End Auto-Grading Logic ---
    }

    $submission->status = 'draft';
    return true;
}


    /**
     * Called when a submission is removed/deleted by the student.
     * Cleans up plagiarism data to prevent ghost records and self-plagiarism on re-upload.
     */
    public function remove(stdClass $submission) {
        global $DB;
        
        // Delete from local Moodle DB
        $DB->delete_records('assignsub_cheqmate_res', ['submission' => $submission->id]);
        
        // Call engine to delete fingerprint
        $api_url = get_config('assignsubmission_cheqmate', 'api_url') ?: 'http://localhost:8000';
        $endpoint = $api_url . '/fingerprint/' . $submission->id;
        
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_exec($ch);
        curl_close($ch);
        
        return true;
    }

    private function format_match_details($details) {
        global $DB;
        
        $output = [];
        foreach ($details as $match) {
            if (isset($match['source_type']) && $match['source_type'] == 'global') {
                $output[] = "Global: " . ($match['filename'] ?? 'Unknown') . " - " . round($match['score'], 2) . "%";
            } else if (isset($match['submission_id'])) {
                $peer_user = $DB->get_record_sql(
                    "SELECT u.* FROM {user} u JOIN {assign_submission} s ON s.userid = u.id WHERE s.id = ?", 
                    [$match['submission_id']]
                );
                $name = $peer_user ? fullname($peer_user) : "Unknown";
                $output[] = "$name: " . round($match['score'], 2) . "%";
            }
        }
        return implode(', ', $output);
    }

    /**
     * Display the plagiarism results in the submission status table
     */
    public function view_summary(stdClass $submission, & $showviewlink) {
        global $DB, $USER;

        $showviewlink = false;
        
        $this->check_db_schema();

        $settings = $this->get_plugin_settings();
        $student_view = $settings ? $settings->student_view : false;
        $is_teacher = has_capability('mod/assign:grade', $this->assignment->get_context());
        $is_owner = ($USER->id == $submission->userid);

        if (!$is_teacher && !$student_view) {
            return '';
        }

        $record = $DB->get_record('assignsub_cheqmate_res', ['submission' => $submission->id], '*', IGNORE_MULTIPLE);
        
        // Force status back to draft if not final submitted
        if ($record && empty($record->final_submitted) && $submission->status == ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
            $submission->status = ASSIGN_SUBMISSION_STATUS_DRAFT;
            $DB->update_record('assign_submission', $submission);
        }

        if ($record) {
            if ($record->status == 'error') {
                return '<span class="text-warning">CheqMate: Error</span>';
            }
            
            $plag_class = $record->plagiarism_score > 50 ? 'text-danger' : ($record->plagiarism_score > 25 ? 'text-warning' : 'text-success');
            $ai_class = $record->ai_probability > 50 ? 'text-danger' : ($record->ai_probability > 25 ? 'text-warning' : 'text-success');
            
            $output = 'Plagiarism: <strong class="' . $plag_class . '">' . round($record->plagiarism_score, 1) . '%</strong> | ' .
                      'AI: <strong class="' . $ai_class . '">' . round($record->ai_probability, 1) . '%</strong>';
            
            // Add match details as bullet points
            $result_json = json_decode($record->json_result, true);
            if (!empty($result_json['details'])) {
                $output .= '<ul style="font-size: 0.85em; color: #555; margin: 3px 0 0 15px; padding: 0;">';
                foreach ($result_json['details'] as $match) {
                    if (isset($match['source_type']) && $match['source_type'] == 'global') {
                        $output .= '<li>Global: ' . htmlspecialchars($match['filename'] ?? 'doc') . ' - ' . round($match['score'], 0) . '%</li>';
                    } else if (isset($match['submission_id'])) {
                        $peer_user = $DB->get_record_sql(
                            "SELECT u.* FROM {user} u JOIN {assign_submission} s ON s.userid = u.id WHERE s.id = ?", 
                            [$match['submission_id']]
                        );
                        $name = $peer_user ? fullname($peer_user) : "ID:" . $match['submission_id'];
                        $output .= '<li>' . $name . ' - ' . round($match['score'], 0) . '%</li>';
                    }
                }
                $output .= '</ul>';
                
                $url = new moodle_url('/mod/assign/submission/cheqmate/advanced_report.php', ['id' => $submission->id]);
                
                if ($is_owner && $student_view) {
                    $output .= '<div style="margin-top: 10px;">';
                    $output .= '<iframe src="' . $url->out() . '" width="100%" height="450px" style="border: 1px solid #ddd; border-radius: 4px;"></iframe>';
                    $output .= '</div>';
                } else if ($is_teacher) {
                    $output .= '<div style="margin-top: 8px;">';
                    $output .= '<a href="' . $url->out() . '" target="_blank" class="btn btn-sm btn-secondary" style="font-size: 0.8em; padding: 2px 6px;">&#8942; View Advanced Report</a>';
                    $output .= '</div>';
                }
            }
            
            if ($is_owner && $submission->status != 'submitted') {
                $submiturl = new moodle_url('/mod/assign/submission/cheqmate/submit_assignment.php', [
                    'id' => $submission->id,
                    'sesskey' => sesskey()
                ]);
                $confirm_msg = get_string('confirm_final_submission', 'assignsubmission_cheqmate');
                $button_text = get_string('final_submission', 'assignsubmission_cheqmate');
                $output .= '<div class="cheqmate-final-submission-container" style="margin-top: 20px; padding: 15px; border-top: 1px solid #eee;">';
                $output .= '<h5 style="margin-bottom: 10px; color: #333;">Ready to submit your assignment?</h5>';
                $output .= '<p style="font-size: 0.9em; color: #666; margin-bottom: 15px;">Please review your plagiarism and AI scores above. If you are satisfied, click the button below to submit your work to the teacher. You will not be able to make changes after submission.</p>';
                $output .= '<form action="' . $submiturl->out(false) . '" method="POST" onsubmit="return confirm(\'' . addslashes($confirm_msg) . '\');">';
                $output .= '<button type="submit" class="btn btn-primary cheqmate-submit-btn" style="font-weight: bold; padding: 8px 16px; background-color: #007bff; border-color: #007bff; color: #fff; border-radius: 4px; cursor: pointer;">';
                $output .= $button_text;
                $output .= '</button>';
                $output .= '</form>';
                $output .= '</div>';
                
                // Inject Javascript to hide Moodle's own submit button if it's there.
                $output .= '<script>
                (function() {
                    var hideMoodleSubmit = function() {
                        var submitButtons = document.querySelectorAll(\'form[action*="action=submit"] input[type="submit"], form[action*="action=submit"] button, button[id*="id_submit"], input[id*="id_submit"]\');
                        submitButtons.forEach(function(btn) {
                            btn.style.display = "none";
                        });
                        var allButtons = document.querySelectorAll(\'button, input[type="submit"], input[type="button"]\');
                        allButtons.forEach(function(btn) {
                            var text = (btn.textContent || btn.value || "").toLowerCase();
                            if (text.includes("submit assignment") || text.includes("submit_assignment") || text.includes("submissionstatement")) {
                                if (!btn.classList.contains("cheqmate-submit-btn")) {
                                    btn.style.display = "none";
                                    var parentForm = btn.closest("form");
                                    if (parentForm && parentForm.querySelectorAll(\'button, input[type="submit"]\').length === 1) {
                                        parentForm.style.display = "none";
                                    }
                                }
                            }
                        });
                    };
                    hideMoodleSubmit();
                    window.addEventListener("load", hideMoodleSubmit);
                    document.addEventListener("DOMContentLoaded", hideMoodleSubmit);
                    setTimeout(hideMoodleSubmit, 500);
                    setTimeout(hideMoodleSubmit, 1500);
                })();
                </script>';
            }
            
            return $output;
        }

        return '';
    }

    /**
     * Display in grading table summary column
     */
    public function get_grading_summary(stdClass $submission) {
        global $DB;
        
        $record = $DB->get_record('assignsub_cheqmate_res', ['submission' => $submission->id]);
        if (!$record) {
            return '-';
        }
        
        $plag = round($record->plagiarism_score, 1);
        $ai = round($record->ai_probability, 1);
        
        $plag_class = $plag > 50 ? 'badge-danger' : ($plag > 25 ? 'badge-warning' : 'badge-success');
        $ai_class = $ai > 50 ? 'badge-danger' : ($ai > 25 ? 'badge-warning' : 'badge-success');
        
        return '<span class="badge ' . $plag_class . '" style="margin-right: 3px;">P: ' . $plag . '%</span>' .
               '<span class="badge ' . $ai_class . '">AI: ' . $ai . '%</span>';
    }
    
    /**
     * Return true if this plugin accepts submissions
     */
    public function is_empty(stdClass $submission) {
        return false;
    }
    
    /**
     * Get the default setting for submission plugin
     */
    public function get_default_setting() {
        return true;
    }
}
