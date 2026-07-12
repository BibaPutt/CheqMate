<?php
$string['pluginname'] = 'CheqMate Plagiarism Checker';
$string['enabled'] = 'Enable CheqMate';
$string['enabled_help'] = 'If enabled, submissions will be checked for plagiarism and AI content.';

$string['plagiarism_threshold'] = 'Plagiarism Blocking Threshold (%)';
$string['plagiarism_threshold_help'] = 'Block submissions if the similarity score exceeds this value. Set to 100 to disable blocking.';

$string['check_ai'] = 'Check for AI Content';
$string['check_ai_help'] = 'Enable AI-generated content detection.';

$string['enable_peer_comparison'] = 'Enable Peer-to-Peer Comparison';
$string['enable_peer_comparison_help'] = 'Compare submissions against other students in the same assignment.';

$string['check_global_source'] = 'Compare with Global Source';
$string['check_global_source_help'] = 'Compare against teacher-uploaded reference documents.';

$string['student_view'] = 'Allow students to view report';
$string['student_view_help'] = 'Students can see their plagiarism/AI scores after submission.';

// --- Punctuality Settings Strings ---
$string['punctuality_settings'] = 'Punctuality Auto-Grading Settings';
$string['auto_grading_enabled'] = 'Enable Auto Grading for Punctuality';
$string['auto_submit_grade'] = 'True Auto-Submit Grade';
$string['auto_submit_grade_help'] = 'If enabled, rubric grades are released immediately to students upon final submission, rather than remaining as draft grades for teacher review.';
$string['criteria_name'] = 'Rubric Criteria Name';
$string['deduction_amount'] = 'Deduction Amount';
$string['deduction_interval_days'] = 'Deduction Interval (Days)';
$string['start_deducting_after_days'] = 'Start deducting after (Days)';
$string['minimum_mark'] = 'Minimum Mark Bound';

$string['clear_cache'] = 'Clear Plagiarism Cache';
$string['clear_cache_confirm'] = 'Clear the plagiarism cache for this assignment?';
$string['cache_info'] = 'Cache Management';
$string['cache_cleared'] = 'Cache cleared. {$a} fingerprints removed.';

$string['error_connection'] = 'Could not connect to CheqMate engine.';
$string['error_analysis'] = 'Analysis failed: {$a}';
$string['error_display'] = 'Analysis error. Contact administrator.';

$string['submission_blocked'] = 'Submission blocked: Plagiarism score {$a}% exceeds limit.';
$string['submission_blocked_detailed'] = 'Blocked: Plagiarism {$a->score}%, AI {$a->ai}%. Matches: {$a->details}';
$string['error_no_files'] = 'You must upload at least one file before submitting.';

// Course settings
$string['course_settings'] = 'CheqMate Settings';
$string['course_settings_desc'] = 'Configure CheqMate for this course.';
$string['settings_saved'] = 'Settings saved successfully.';

// Global sources
$string['global_source_upload'] = 'Upload Global Source';
$string['global_source_upload_help'] = 'Upload reference documents for plagiarism comparison.';
$string['global_source_deleted'] = 'Global source deleted.';
$string['global_source_list'] = 'Global Sources';
$string['global_source_none'] = 'No global sources uploaded.';

// Skip patterns
$string['skip_patterns'] = 'Skip Sections';
$string['skip_patterns_help'] = 'Comma-separated sections to exclude: aim, introduction, code, references';



// Revert Submission feature
$string['revert_submissions']       = 'Revert Student Assignments';
$string['revert_page_desc']         = 'Select a student to view all their submitted assignments in this course. Tick the assignments you wish to revert, then click Revert Selected. Reverting clears the CheqMate analysis and resets the submission so the student can upload and resubmit.';
$string['revert_step1']             = 'Step 1 — Select Student';
$string['revert_step2']             = 'Step 2 — Select Assignments to Revert';
$string['select_student']           = 'Student:';
$string['choose_student']           = '— Choose a student —';
$string['view_submissions']         = 'View Submissions';
$string['showing_for']              = 'Showing submissions for';
$string['revert_no_submissions']    = 'This student has no submitted assignments in this course.';
$string['revert_none_selected']     = 'No assignments were selected. Please tick at least one assignment.';
$string['revert_success']           = 'Successfully reverted {$a} submission(s). The student can now resubmit.';
$string['revert_partial']           = 'Reverted {$a->reverted} submission(s). {$a->errors} could not be reverted.';
$string['revert_failed']            = 'No submissions could be reverted. Please check permissions and try again.';
$string['revert_confirm']           = 'Are you sure you want to revert the selected submissions? This will clear CheqMate data and allow the student to resubmit.';
$string['revert_warning_title']     = 'What does Revert do?';
$string['revert_warning_body']      = 'The selected submissions will have their CheqMate plagiarism/AI results cleared and their status reset to Not submitted, allowing the student to upload a new file. Existing files are NOT deleted.';
$string['select_all']               = 'Select All';
$string['deselect_all']             = 'Deselect All';
$string['revert_selected']          = 'Revert Selected';
$string['none_selected']            = 'No assignments selected';
$string['selected_count']           = 'assignment(s) selected';
$string['assignment_name']          = 'Assignment';
$string['submitted_on']             = 'Submitted On';
$string['plagiarism_col']           = 'Plagiarism';
$string['ai_col']                   = 'AI Score';
$string['cheqmate_status_col']      = 'CheqMate Status';
$string['back_to_settings']         = 'Back to CheqMate Settings';



// -------------------------------------------------------------------------
// Clear Assignment feature
// -------------------------------------------------------------------------
$string['clear_assignment']             = 'Clear Assignment';
$string['clear_page_desc']              = 'Select an assignment to wipe <strong>all student submissions</strong> from it completely. Every student\'s uploaded files, CheqMate results, and fingerprints will be permanently deleted. Each student\'s submission will be reset to empty so they can upload fresh files.';
 
$string['clear_step1']                  = 'Step 1 — Select Assignment';
$string['clear_step2']                  = 'Step 2 — Review &amp; Confirm';
 
$string['select_assignment']            = 'Assignment:';
$string['choose_assignment']            = '— Choose an assignment —';
$string['preview_submissions']          = 'Preview';
$string['clear_showing_for']            = 'Clearing all submissions for';
 
$string['submissions_count_label']      = 'submitted';
$string['no_submissions_label']         = 'no submissions';
 
$string['clear_no_submissions']         = 'This assignment has no submissions to clear.';
$string['students_will_be_cleared']     = 'student submission(s) will be permanently cleared';
$string['students_label']               = 'student(s)';
 
$string['clear_warning_title']          = '⚠ This action is irreversible!';
$string['clear_warning_body']           = 'All uploaded files, plagiarism results, AI scores, and engine fingerprints for every student in this assignment will be permanently deleted. Students will be able to resubmit, but their previous files cannot be recovered.';
 
$string['clear_all_submissions']        = 'Clear All Submissions';
$string['clear_irreversible']           = 'This cannot be undone.';
$string['clear_confirm']                = 'WARNING: This will permanently delete ALL student files and CheqMate data for this assignment. This cannot be undone. Are you absolutely sure?';
 
$string['clear_success']                = 'Successfully cleared {$a->count} submission(s) from "{$a->name}". The assignment is now empty — students can resubmit.';
$string['clear_partial']                = 'Cleared {$a->cleared} submission(s) from "{$a->name}". {$a->errors} submission(s) could not be cleared due to errors.';
$string['clear_nothing_to_clear']       = 'This assignment already has no submissions.';
$string['clear_failed']                 = 'Failed to clear submissions. Please check permissions and try again.';
$string['clear_invalid_assignment']     = 'Invalid assignment selected. Please try again.';
 
$string['student_name_col']             = 'Student';
$string['grading_strictness']           = 'Auto-Grading Strictness (%)';
$string['grading_strictness_help']      = 'The minimum percentage of unique keywords from the lab manual that the student must cover to receive a full grade. Higher values require more coverage (stricter checking). Default is 50%.';
$string['final_submission']             = 'Final Submission to Teacher';
$string['confirm_final_submission']     = 'Are you sure you want to submit this assignment to the teacher? You will not be able to make changes after submission.';
$string['error_submission_failed']     = 'Submission failed: {$a}';