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

        if ($old = $DB->get_record('assignsubmission_cheqmate', ['assignment' => $record->assignment])) {
            $record->id = $old->id;
            $DB->update_record('assignsubmission_cheqmate', $record);
        } else {
            $DB->insert_record('assignsubmission_cheqmate', $record);
        }
        
        return true;
    }

    public function is_enabled() {
        global $DB;
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
            return true;
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
            
            $api_url = get_config('assignsubmission_cheqmate', 'api_url') ?: 'http://localhost:8000';
            $endpoint = $api_url . '/analyze';
            
            $payload = json_encode([
                'file_path' => $tempfilepath,
                'submission_id' => $submission->id,
                'context_id' => $context->id,
                'assignment_id' => $assignmentid,
                'course_id' => $courseid,
                'check_global_source' => (bool) $settings->check_global_source,
                'enable_peer_comparison' => (bool) $settings->enable_peer_comparison,
                'skip_patterns' => $skip_patterns
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
                throw new moodle_exception('error_analysis', 'assignsubmission_cheqmate', '', $result['message'] ?? 'Unknown error');
            }
            
            clearstatcache();
            $newfilesize = filesize($tempfilepath);
            
            if ($newfilesize != $file->get_filesize()) {
                $file_record = array(
                    'contextid' => $file->get_contextid(),
                    'component' => $file->get_component(),
                    'filearea'  => $file->get_filearea(),
                    'itemid'    => $file->get_itemid(),
                    'filepath'  => $file->get_filepath(),
                    'filename'  => $file->get_filename(),
                    'userid'    => $file->get_userid(),
                    'sortorder' => $file->get_sortorder(),
                    'license'   => $file->get_license(),
                    'author'    => $file->get_author(),
                    'source'    => $file->get_source(),
                );
                $file->delete();
                $fs->create_file_from_pathname($file_record, $tempfilepath);
            }
            
            @unlink($tempfilepath);

            $plag_score = $result['plagiarism_score'] ?? 0;
            $ai_score = $result['ai_probability'] ?? 0;

            $record = new stdClass();
            $record->submission = $submission->id;
            $record->filehash = $contenthash;
            $record->plagiarism_score = $plag_score;
            $record->ai_probability = $ai_score;
            $record->report_path = '';
            $record->json_result = json_encode($result);
            $record->status = $result['status'] ?? 'processed';
            $record->timecreated = time();
            
            if ($old = $DB->get_record('assignsub_cheqmate_res', ['submission' => $submission->id])) {
                $record->id = $old->id;
                $DB->update_record('assignsub_cheqmate_res', $record);
            } else {
                $DB->insert_record('assignsub_cheqmate_res', $record);
            }

            $threshold = $settings->plagiarism_threshold;
            if (isset($result['status']) && $result['status'] == 'processed' && $result['plagiarism_score'] > $threshold) {
                // Delete the fingerprint since submission is blocked (prevents 100% self-match on re-upload)
                $delete_endpoint = $api_url . '/fingerprint/' . $submission->id;
                $dch = curl_init($delete_endpoint);
                curl_setopt($dch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($dch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                curl_setopt($dch, CURLOPT_TIMEOUT, 10);
                curl_exec($dch);
                curl_close($dch);
                
                // Also remove result record from Moodle DB
                $DB->delete_records('assignsub_cheqmate_res', ['submission' => $submission->id]);
                
                $error_data = new stdClass();
                $error_data->score = $result['plagiarism_score'];
                $error_data->ai = $result['ai_probability'];
                $error_data->details = $this->format_match_details($result['details'] ?? []);
                throw new moodle_exception('submission_blocked_detailed', 'assignsubmission_cheqmate', '', $error_data);
            }
        }
        
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
                    "SELECT u.firstname, u.lastname FROM {user} u JOIN {assign_submission} s ON s.userid = u.id WHERE s.id = ?", 
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
        global $DB;

        $showviewlink = false;
        
        $settings = $this->get_plugin_settings();
        $student_view = $settings ? $settings->student_view : false;
        $is_teacher = has_capability('mod/assign:grade', $this->assignment->get_context());

        if (!$is_teacher && !$student_view) {
            return '';
        }

        $record = $DB->get_record('assignsub_cheqmate_res', ['submission' => $submission->id], '*', IGNORE_MULTIPLE);
        
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
                            "SELECT u.firstname, u.lastname FROM {user} u JOIN {assign_submission} s ON s.userid = u.id WHERE s.id = ?", 
                            [$match['submission_id']]
                        );
                        $name = $peer_user ? fullname($peer_user) : "ID:" . $match['submission_id'];
                        $output .= '<li>' . $name . ' - ' . round($match['score'], 0) . '%</li>';
                    }
                }
                $output .= '</ul>';
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
