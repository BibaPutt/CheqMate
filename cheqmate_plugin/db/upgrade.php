<?php
// Database upgrade steps for assignsubmission_cheqmate

defined('MOODLE_INTERNAL') || die();

function xmldb_assignsubmission_cheqmate_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 20260305023) {
        
        // Add fields to assignsubmission_cheqmate table
        $table = new xmldb_table('assignsubmission_cheqmate');
        
        $field = new xmldb_field('check_global_source', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'student_view');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('enable_peer_comparison', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1', 'check_global_source');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add Auto-Grading fields
        $field = new xmldb_field('auto_grading_enabled', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'enable_peer_comparison');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        $field = new xmldb_field('criteria_name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, 'Punctuality', 'auto_grading_enabled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        $field = new xmldb_field('deduction_amount', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0.10', 'criteria_name');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        $field = new xmldb_field('deduction_interval', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1', 'deduction_amount');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        $field = new xmldb_field('start_deducting_after', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '3', 'deduction_interval');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        $field = new xmldb_field('minimum_mark', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '1.00', 'start_deducting_after');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Create global source table if not exists
        $table = new xmldb_table('cheqmate_global_source');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('filename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('contenthash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('fingerprint', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('is_grading', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $dbman->create_table($table);
        } else {
            $field = new xmldb_field('is_grading', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'userid');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Create course settings table if not exists
        $table = new xmldb_table('cheqmate_course_settings');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('skip_patterns', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_courseid', XMLDB_KEY_FOREIGN_UNIQUE, ['courseid'], 'course', ['id']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026013200, 'assignsubmission', 'cheqmate');
    }

    if ($oldversion < 20260305027) {
        $table = new xmldb_table('cheqmate_global_source');
        $field = new xmldb_field('sections', XMLDB_TYPE_TEXT, null, null, null, null, null, 'is_grading');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table_ac = new xmldb_table('assignsubmission_cheqmate');
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

        upgrade_plugin_savepoint(true, 20260305027, 'assignsubmission', 'cheqmate');
    }

    return true;
}
