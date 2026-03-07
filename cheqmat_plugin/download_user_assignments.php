<?php
require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/filelib.php');

$courseid = required_param('courseid', PARAM_INT);
$userid   = required_param('userid', PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$user   = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

require_login($course);

$context = context_course::instance($courseid);
require_capability('moodle/course:update', $context);

$fs = get_file_storage();

// Clean names
$username   = clean_filename($user->username);
$coursename = clean_filename($course->fullname);

$zipname = $username . '_' . $coursename . '_assignments.zip';

$tempdir = make_temp_directory('cheqmate_user_download_' . time());
$zippath = $tempdir . '/' . $zipname;

$assignments = $DB->get_records('assign', ['course' => $courseid]);

$filesforzip = [];
$hasfiles = false;

foreach ($assignments as $assign) {

    $submission = $DB->get_record('assign_submission', [
        'assignment' => $assign->id,
        'userid' => $userid
    ]);

    if (!$submission) {
        continue;
    }

    $cm = get_coursemodule_from_instance('assign', $assign->id, $courseid);
    if (!$cm) {
        continue;
    }

    $modcontext = context_module::instance($cm->id);

    $files = $fs->get_area_files(
        $modcontext->id,
        'assignsubmission_file',
        'submission_files',
        $submission->id,
        'sortorder',
        false
    );

    if (!$files) {
        continue;
    }

    foreach ($files as $file) {
        if ($file->is_directory()) {
            continue;
        }

        $assignmentfolder = clean_filename($assign->name);

        // Create temp file
        $temppath = $tempdir . '/' . uniqid() . '_' . $file->get_filename();
        $file->copy_content_to($temppath);

        // Internal path inside zip
        $internalpath = $assignmentfolder . '/' . $file->get_filename();

        $filesforzip[$internalpath] = $temppath;

        $hasfiles = true;
    }
}

if (!$hasfiles) {
    throw new moodle_exception('nofiles', 'assignsubmission_cheqmate');
}

$zipper = new zip_packer();
$zipper->archive_to_pathname($filesforzip, $zippath);

send_temp_file($zippath, $zipname);