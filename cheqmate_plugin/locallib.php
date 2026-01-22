<?php

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

class assign_submission_cheqmate extends assign_submission_plugin {

    public function get_name() {
        return get_string('pluginname', 'assignsubmission_cheqmate');
    }

    public function get_settings($mform) {

        $mform->addElement('advcheckbox', 'assignsubmission_cheqmate_enabled', get_string('enabled', 'assignsubmission_cheqmate'));
        $mform->setDefault('assignsubmission_cheqmate_enabled', 0);

        $mform->addElement('text', 'assignsubmission_cheqmate_threshold', get_string('plagiarism_threshold', 'assignsubmission_cheqmate'));
        $mform->setType('assignsubmission_cheqmate_threshold', PARAM_INT);
        $mform->setDefault('assignsubmission_cheqmate_threshold', 50);
        $mform->addHelpButton('assignsubmission_cheqmate_threshold', 'plagiarism_threshold', 'assignsubmission_cheqmate');
        $mform->hideIf('assignsubmission_cheqmate_threshold', 'assignsubmission_cheqmate_enabled', 'notchecked');

        $mform->addElement('advcheckbox', 'assignsubmission_cheqmate_check_ai', get_string('check_ai', 'assignsubmission_cheqmate'));
        $mform->setDefault('assignsubmission_cheqmate_check_ai', 1);
        $mform->hideIf('assignsubmission_cheqmate_check_ai', 'assignsubmission_cheqmate_enabled', 'notchecked');

        $mform->addElement('advcheckbox', 'assignsubmission_cheqmate_student_view', get_string('student_view', 'assignsubmission_cheqmate'));
        $mform->setDefault('assignsubmission_cheqmate_student_view', 0);
        $mform->addHelpButton('assignsubmission_cheqmate_student_view', 'student_view', 'assignsubmission_cheqmate');
        $mform->hideIf('assignsubmission_cheqmate_student_view', 'assignsubmission_cheqmate_enabled', 'notchecked');
    }

    public function get_form_elements($submission, $mform, $data) {
        return false;
    }

    public function save_settings(stdClass $data) {
        global $DB;

        $record = new stdClass();
        $record->assignment = $this->assignment->get_instance()->id;
        $record->enabled = !empty($data->assignsubmission_cheqmate_enabled) ? 1 : 0;
        $record->plagiarism_threshold = $data->assignsubmission_cheqmate_threshold;
        $record->check_ai = !empty($data->assignsubmission_cheqmate_check_ai) ? 1 : 0;
        $record->student_view = !empty($data->assignsubmission_cheqmate_student_view) ? 1 : 0;

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
        $assignmentid = $this->assignment->get_instance()->id;
        $enabled = (bool) $DB->get_field('assignsubmission_cheqmate', 'enabled', ['assignment' => $assignmentid]);
        return $enabled;
    }

    public function submit_for_grading($submission) {
        return true;
    }

    public function save($submission, $data) {
        global $DB, $CFG;

        // 1. Check if enabled
        if (!$this->is_enabled()) {
            return true;
        }

        // 2. We need to find the files attached to this submission.
        $fs = get_file_storage();
        
        // Try getting files from the final submission area first
        $context = context_module::instance($this->assignment->get_course_module()->id);
        $files = $fs->get_area_files($context->id, 'assignsubmission_file', 'submission_files', $submission->id, 'sortorder', false);
        
        // If no files found, check DRAFT area (because we might be running BEFORE the file plugin saves)
        // Log showed key is 'files_filemanager'
        if (empty($files) && (isset($data->files_filemanager) || isset($data->assignsubmission_file_filemanager))) {
            $draftitemid = isset($data->files_filemanager) ? $data->files_filemanager : $data->assignsubmission_file_filemanager;
            $usercontext = context_user::instance($submission->userid);
            
            // Get files from draft area
            $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'sortorder', false);
        }

        if (empty($files)) {
            return true;
        }

        // Create a temp directory for processing
        $tempdir = make_temp_directory('assignsubmission_cheqmate');

        // 3. Process each file
        foreach ($files as $file) {
            // Skip directories or empty files
            if ($file->is_directory() || $file->get_filesize() == 0) {
                continue;
            }

            $contenthash = $file->get_contenthash();
            $filename = $file->get_filename();
            
            // Create a temp file copy
            $tempfilepath = $tempdir . '/' . $contenthash . '_' . $filename;
            $file->copy_content_to($tempfilepath);
            
            // 4. Send to CheqMate Python Engine using the TEMP file path
            $api_url = $this->get_config('api_url') ?: 'http://localhost:8000';
            $endpoint = $api_url . '/analyze';
            
            $payload = json_encode([
                'file_path' => $tempfilepath,
                'submission_id' => $submission->id,
                'context_id' => $context->id  // Keep module context for ID purposes
            ]);

            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload)
            ]);
            
            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($httpcode !== 200) {
                 continue;
            }
            
            $result = json_decode($response, true);
            
            // 5. Check if report was generated (i.e. if Python modified the file)
            clearstatcache();
            $newfilesize = filesize($tempfilepath);
            
            if ($newfilesize != $file->get_filesize()) {
                // File modified! We must update Moodle storage.
                
                // Prepare file record info. Crucial: Use the file's EXISTING metadata (context, area, etc)
                // This ensures we update the draft file in the draft area correctly.
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

                // Delete old file first
                $file->delete();

                // Create new file from temp
                $fs->create_file_from_pathname($file_record, $tempfilepath);
            }
            
            // Cleanup temp file
            @unlink($tempfilepath);

            // 6. Save Result to DB
            $record = new stdClass();
            $record->submission = $submission->id;
            // Use the ORIGINAL content hash for tracking (or new, but consistent)
            $record->filehash = $contenthash; 
            // Ideally we store the NEW hash, but the result is for the submitted content.
            // Let's keep it linked to the submission.
            
            $record->plagiarism_score = $result['plagiarism_score'] ?? 0.0;
            $record->ai_probability = $result['ai_probability'] ?? 0.0;
            $record->report_path = ''; 
            $record->json_result = json_encode($result);
            $record->status = 'processed';
            $record->timecreated = time();
            
            // Check if exists
             if ($old = $DB->get_record('assignsub_cheqmate_res', ['submission' => $submission->id])) {
                 // For simplicity, just update the first one found or overwrite
                 // In a multi-file submission, this table needs better handling (composite key), 
                 // but checking 'submission' is enough for single-file assignments.
                $record->id = $old->id;
                $DB->update_record('assignsub_cheqmate_res', $record);
            } else {
                $DB->insert_record('assignsub_cheqmate_res', $record);
            }

            // 7. Blocking Logic
            $threshold = $this->get_config('plagiarism_threshold');
            if (isset($result['status']) && $result['status'] == 'processed' && $result['plagiarism_score'] > $threshold) {
                 throw new moodle_exception('submission_blocked', 'assignsubmission_cheqmate', '', $result['plagiarism_score']);
                 return false;
            }
        }
        
        return true;
    }
    public function view_summary(stdClass $submission, & $showviewlink) {
        global $DB, $OUTPUT;

        // Check if student view is enabled
        $student_view = $this->get_config('student_view');
        $is_teacher = has_capability('mod/assign:grade', $this->assignment->get_context());

        if (!$is_teacher && !$student_view) {
            return '';
        }

        // Fetch result
        $record = $DB->get_record('assignsub_cheqmate_res', ['submission' => $submission->id], '*', IGNORE_MULTIPLE);
        
        if ($record) {
             $plag_class = $record->plagiarism_score > 50 ? 'text-danger' : 'text-success';
             $ai_class = $record->ai_probability > 50 ? 'text-danger' : 'text-success';
             
             $output = '<div class="cheqmate-summary">';
             $output .= '<b>CheqMate Report:</b><br>';
             $output .= 'Plagiarism: <span class="' . $plag_class . '">' . $record->plagiarism_score . '%</span><br>';
             $output .= 'AI Detection: <span class="' . $ai_class . '">' . $record->ai_probability . '%</span>';
             
             // Parse details for peer matches
             $result_json = json_decode($record->json_result, true);
             if (!empty($result_json['details'])) {
                 $output .= '<br><b>Matched with:</b><ul>';
                 foreach ($result_json['details'] as $match) {
                     $peer_sub_id = $match['submission_id'];
                     $peer_score = round($match['score'], 2);
                     
                     // Lookup user name
                     $peer_user = $DB->get_record_sql(
                         "SELECT u.firstname, u.lastname 
                          FROM {user} u 
                          JOIN {assign_submission} s ON s.userid = u.id 
                          WHERE s.id = ?", 
                         [$peer_sub_id]
                     );
                     
                     $peer_name = $peer_user ? fullname($peer_user) : "Unknown Student (ID: $peer_sub_id)";
                     $output .= "<li>$peer_name: $peer_score%</li>";
                 }
                 $output .= '</ul>';
             }
             
             $output .= '</div>';
             return $output;
        } else {
             // no record
        }

        return '';
    }
}
