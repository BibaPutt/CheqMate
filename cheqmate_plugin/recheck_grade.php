<?php
/**
 * CheqMate - Recheck Student Grades (Recalculate Rubric)
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once(__DIR__ . '/lib.php');

$submissionid = required_param('id', PARAM_INT);
confirm_sesskey();

$submission = $DB->get_record('assign_submission', ['id' => $submissionid], '*', MUST_EXIST);
$assign_rec = $DB->get_record('assign', ['id' => $submission->assignment], '*', MUST_EXIST);
$course = $DB->get_record('course', ['id' => $assign_rec->course], '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('assign', $assign_rec->id, $course->id, false, MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('mod/assign:grade', $context);

$assignment = new assign($context, $cm, $course);
$plugin = $assignment->get_submission_plugin_by_type('cheqmate');
$result = $plugin->run_analysis($submission);

if (!$result) {
    $res = $DB->get_record('assignsub_cheqmate_res', ['submission' => $submissionid]);
    if (!$res) {
        throw new moodle_exception('No CheqMate results found to recheck.');
    }
    $result = json_decode($res->json_result, true);
}

$settings = $DB->get_record('assignsubmission_cheqmate', ['assignment' => $submission->assignment]);

if ($settings && !empty($settings->auto_grading_enabled)) {
    // Standard grading logic
    $sql = "SELECT gd.id, gd.method FROM {grading_areas} ga JOIN {grading_definitions} gd ON ga.id = gd.areaid WHERE ga.contextid = ? AND ga.component = 'mod_assign' AND ga.areaname = 'submissions' AND gd.method = 'rubric' AND gd.status = 20";
    $definition = $DB->get_record_sql($sql, [$context->id]);

    if ($definition) {
        $grade = $assignment->get_user_grade($submission->userid, true);
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
                    $duedate = $assign_rec->duedate;

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

            // Apply fillings
            foreach ($criteria_to_grade as $item) {
                $crit = $item['criteria'];
                $lvls = $item['levels'];
                $score = $item['score'];
                $remark = $item['remark'];

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

            // Auto-submit grade if toggle enabled or on manual recheck
            $DB->set_field('grading_instances', 'status', 1, ['id' => $instance->id]);
            
            $sql_grade_calc = "SELECT SUM(gl.score) 
                               FROM {gradingform_rubric_fillings} gf
                               JOIN {gradingform_rubric_levels} gl ON gf.levelid = gl.id
                               WHERE gf.instanceid = ?";
            $rubric_sum = $DB->get_field_sql($sql_grade_calc, [$instance->id]);
            if ($rubric_sum !== false && $rubric_sum >= 0) {
                $grade->grade = (float)$rubric_sum;
                $grade->grader = -1;
                $grade->timemodified = time();
                $assignment->update_grade($grade);

                // Release marking workflow if enabled
                if ($assignment->get_instance()->markingworkflow) {
                    $flags = $assignment->get_user_flags($submission->userid, true);
                    $flags->workflowstate = 'released';
                    $assignment->update_user_flags($flags);
                }
            }
            
            // Finalize submission status
            $submission->status = 'submitted';
            $DB->update_record('assign_submission', $submission);
        }
    }
}

redirect(new moodle_url('/mod/assign/view.php', ['id' => $cm->id, 'action' => 'grading']), 'Rubric grades successfully recalculated.', null, \core\output\notification::NOTIFY_SUCCESS);
