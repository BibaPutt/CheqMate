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

$string['clear_cache'] = 'Clear Plagiarism Cache';
$string['clear_cache_confirm'] = 'Clear the plagiarism cache for this assignment?';
$string['cache_info'] = 'Cache Management';
$string['cache_cleared'] = 'Cache cleared. {$a} fingerprints removed.';

$string['error_connection'] = 'Could not connect to CheqMate engine.';
$string['error_analysis'] = 'Analysis failed: {$a}';
$string['error_display'] = 'Analysis error. Contact administrator.';

$string['submission_blocked'] = 'Submission blocked: Plagiarism score {$a}% exceeds limit.';
$string['submission_blocked_detailed'] = 'Blocked: Plagiarism {$a->score}%, AI {$a->ai}%. Matches: {$a->details}';

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
