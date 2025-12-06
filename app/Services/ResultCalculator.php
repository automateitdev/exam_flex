<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ResultCalculator
{
    public function calculate($payload)
    {
        $mark_configs = $payload['mark_configs'] ?? [];
        $gradeRules = collect($payload['grade_rules'] ?? [])->sortByDesc('from_mark');

        if ($gradeRules->isEmpty()) {
            return [
                'results' => [],
                'highest_marks' => [],
                'total_students' => 0,
                'error' => 'Grade rules missing'
            ];
        }

        $students = $payload['students'] ?? [];
        if (empty($students)) {
            return ['results' => [], 'highest_marks' => [], 'total_students' => 0];
        }

        $results = [];
        $highestCollection = collect();

        foreach ($students as $student) {
            $result = $this->processStudent($student, $gradeRules, $mark_configs);
            $results[] = $result;

            foreach ($result['subjects'] as $s) {
                if (isset($s['combined_name']) && isset($s['parts'])) {
                    foreach ($s['parts'] as $part) {
                        $this->updateHighest($highestCollection, $part);
                    }
                    continue;
                }

                if (isset($s['subject_id']) && is_numeric($s['subject_id']) && !($s['is_combined'] ?? false)) {
                    $this->updateHighest($highestCollection, $s);
                }
            }
        }

        return [
            'has_combined' => $payload['has_combined'] ?? false,
            'exam_name' => $payload['exam_name'],
            'results' => $results,
            'highest_marks' => $highestCollection->values()->toArray(),
            'total_students' => count($results),
        ];
    }

    private function updateHighest(Collection $collection, array $subject)
    {
        $id = $subject['subject_id'] ?? null;
        if (!$id || !is_numeric($id)) return;

        $name = $subject['subject_name'];
        $mark = $subject['final_mark'] ?? 0;
        $key = "single_{$id}";

        if (!$collection->has($key)) {
            $collection->put($key, [
                'subject_id' => (int) $id,
                'subject_name' => $name,
                'highest_mark' => $mark
            ]);
        } else {
            $current = $collection->get($key);
            if ($mark > $current['highest_mark']) {
                $collection->put($key, [
                    'subject_id' => (int) $id,
                    'subject_name' => $name,
                    'highest_mark' => $mark
                ]);
            }
        }
    }

    // private function processStudent($student, $gradeRules, $mark_configs)
    // {
    //     $marks = $student['marks'] ?? [];
    //     $optionalId = $student['optional_subject_id'] ?? null;
    //     $groups = collect($marks)->groupBy(fn($m) => $m['combined_id'] ?? $m['subject_id']);

    //     $merged = [];
    //     $totalGP = 0;
    //     $subjectCount = 0;
    //     $failed = false;

    //     foreach ($groups as $groupId => $group) {
    //         if ($group->isEmpty()) continue;
    //         $first = $group->first();

    //         if ($first['is_combined']) {
    //             $combinedResult = $this->processCombinedGroup($group, $gradeRules, $mark_configs);
    //             $merged[] = $combinedResult;

    //             if (!($combinedResult['is_uncountable'] ?? false)) {
    //                 $totalGP += $combinedResult['combined_grade_point'];
    //                 $subjectCount++;
    //             }

    //             if ($combinedResult['combined_status'] === 'Fail') {
    //                 $failed = true;
    //             }
    //         } else {
    //             $single = $this->processSingle($first, $gradeRules, $optionalId);
    //             $single['is_optional'] = ($first['subject_id'] == $optionalId);
    //             $merged[] = $single;

    //             if (!$single['is_uncountable']) {
    //                 $totalGP += $single['grade_point'];
    //                 $subjectCount++;
    //             }

    //             if ($single['grade'] === 'F') {
    //                 $failed = true;
    //             }
    //         }
    //     }

    //     // === 4th Subject Bonus ===
    //     $optionalBonus = 0;
    //     $bonusGP = 0;
    //     if ($optionalId && isset($marks[$optionalId])) {
    //         $opt = $marks[$optionalId];
    //         $optMark = $opt['final_mark'] ?? 0;
    //         $optionalCalMark = ($optMark * 40) / 100;
    //         $recalcGP = $this->markToGradePoint($optMark, $gradeRules);

    //         if ($recalcGP > 2) {
    //             $optionalBonus = $optionalCalMark;
    //             $bonusGP = $recalcGP - 2;
    //         }
    //     }

    //     $finalGP = $failed ? 0 : ($totalGP + $bonusGP);
    //     $gpaWithoutOptional = $subjectCount > 0 ? round($totalGP / $subjectCount, 2) : 0;
    //     $finalGpa = $subjectCount > 0 ? round($finalGP / $subjectCount, 2) : 0;

    //     $status = $failed ? 'Fail' : 'Pass';

    //     $letterGradeWithoutOptional = $this->gpaToLetterGrade($gpaWithoutOptional, $gradeRules);
    //     $letterGrade = $this->gpaToLetterGrade($finalGpa, $gradeRules);

    //     return [
    //         'student_id' => $student['student_id'] ?? 0,
    //         'student_name' => $student['student_name'] ?? 'N/A',
    //         'roll' => $student['roll'] ?? 'N/A',
    //         'subjects' => $merged,

    //         'gpa_without_optional' => $gpaWithoutOptional,
    //         'letter_grade_without_optional' => $letterGradeWithoutOptional,

    //         'gpa' => $finalGpa,
    //         'letter_grade' => $letterGrade,

    //         'result_status' => $status,
    //         'optional_bonus' => $optionalBonus,
    //     ];
    // }
    private function processStudent($student, $gradeRules, $mark_configs)
    {
        $marks = $student['marks'] ?? [];
        $optionalId = $student['optional_subject_id'] ?? null;

        $groups = collect($marks)->groupBy(fn($m) => $m['combined_id'] ?? $m['subject_id']);

        $merged = [];
        $totalGP = 0;
        $subjectCount = 0;
        $failed = false;

        $totalMarkWithoutOptional = 0.0;  // নতুন: মূল মার্ক (৪র্থ ছাড়া)
        $totalMarkWithOptional    = 0.0;  // নতুন: ৪০% বোনাস সহ মার্ক

        foreach ($groups as $groupId => $group) {
            if ($group->isEmpty()) continue;
            $first = $group->first();

            if ($first['is_combined']) {
                $combinedResult = $this->processCombinedGroup($group, $gradeRules, $mark_configs);
                $merged[] = $combinedResult;

                if (!($combinedResult['is_uncountable'] ?? false)) {
                    $totalGP += $combinedResult['combined_grade_point'];
                    $subjectCount++;
                }
                if ($combinedResult['combined_status'] === 'Fail') {
                    $failed = true;
                }

                // মার্ক যোগ (কম্বাইন্ডের জন্য)
                $totalMarkWithoutOptional += $combinedResult['combined_final_mark'];
            } else {
                $single = $this->processSingle($first, $gradeRules, $mark_configs);
                $single['is_optional'] = ($first['subject_id'] == $optionalId);
                $merged[] = $single;

                if (!$single['is_uncountable']) {
                    $totalGP += $single['grade_point'];
                    $subjectCount++;
                }
                if ($single['grade'] === 'F') {
                    $failed = true;
                }

                if (!$single['is_optional']) {
                    $totalMarkWithoutOptional += $single['final_mark'];
                }
            }
        }

        // === 4th Subject Bonus (দুটো আলাদা আলাদা) ===
        $bonusMarkFromOptional = 0.0;  // ৪০% মার্ক বোনাস
        $bonusGPFromOptional   = 0.0;  // GP-2 বোনাস

        if ($optionalId && isset($marks[$optionalId])) {
            $opt = $marks[$optionalId];
            $optMark = $opt['final_mark'] ?? 0;
            $optGP   = $opt['grade_point'] ?? 0;

            if ($optGP >= 2.00) {
                // ৪০% মার্ক বোনাস
                $bonusMarkFromOptional = round($optMark * 40 / 100, 2);

                // GP থেকে ২ বাদ দিয়ে বোনাস
                $bonusGPFromOptional = max(0, $optGP - 2.00);
            }
        }

        // মোট মার্ক (বোনাস সহ)
        $totalMarkWithOptional = $totalMarkWithoutOptional + $bonusMarkFromOptional;

        // GPA হিসাব
        $finalGP = $failed ? 0 : ($totalGP + $bonusGPFromOptional);
        $gpaWithoutOptional = $subjectCount > 0 ? round($totalGP / $subjectCount, 2) : 0;
        $gpaWithOptional    = $subjectCount > 0 ? round($finalGP / $subjectCount, 2) : 0;

        $status = $failed ? 'Fail' : 'Pass';

        $letterGradeWithout = $this->gpaToLetterGrade($gpaWithoutOptional, $gradeRules);
        $letterGradeWith     = $this->gpaToLetterGrade($gpaWithOptional, $gradeRules);

        return [
            'student_id' => $student['student_id'] ?? 0,
            'student_name' => $student['student_name'] ?? 'N/A',
            'roll' => $student['roll'] ?? 'N/A',
            'subjects' => $merged,

            // মূল হিসাব (৪র্থ ছাড়া)
            'total_mark_without_optional' => round($totalMarkWithoutOptional, 2),
            'gpa_without_optional'        => $gpaWithoutOptional,
            'letter_grade_without_optional' => $letterGradeWithout,

            // বোনাস সহ হিসাব
            'total_mark_with_optional'    => round($totalMarkWithOptional, 2),
            'gpa_with_optional'           => $gpaWithOptional,
            'letter_grade_with_optional'  => $letterGradeWith,

            'result_status' => $status,

            // বোনাস ডিটেইলস
            'optional_bonus_mark' => $bonusMarkFromOptional,
            'optional_bonus_gp'   => $bonusGPFromOptional,
        ];
    }

    private function processCombinedGroup($group, $gradeRules, $mark_configs)
    {
        $combinedId = $group->first()['combined_id'];
        $combinedName = $group->first()['combined_subject_name'];

        $totalMaxMark = 0;
        $parts = $group->map(function ($mark) use ($mark_configs, &$totalMaxMark) {
            $subjectId = $mark['subject_id'];
            $config = $mark_configs[$subjectId] ?? null;
            $partMarks = $mark['part_marks'] ?? [];
            $convertedMark = 0;

            foreach ($partMarks as $code => $obtained) {
                $conversion = $config['conversion'][$code] ?? 100;
                $convertedMark += $obtained * ($conversion / 100);
            }
            $totalMaxMark += $convertedMark;

            return [
                'subject_id' => $subjectId,
                'subject_name' => $mark['subject_name'],
                'final_mark' => $mark['final_mark'],
                'grade_point' => $mark['grade_point'],
                'grade' => $mark['grade'],
                'grace_mark' => $mark['grace_mark'],
                'part_marks' => $mark['part_marks'] ?? [],
                'pass_marks' => $config['pass_marks'] ?? [],
                'overall_required' => $config['overall_required'],
                'max_mark' => $convertedMark,
            ];
        })->values()->toArray();

        $combinedFinalMark = $group->sum('final_mark');
        $percentage = $totalMaxMark > 0 ? ($combinedFinalMark / $totalMaxMark) * 100 : 0;
        $combinedGradePoint = $this->getGradePoint($percentage, $gradeRules);
        $combinedGrade = $this->getGrade($percentage, $gradeRules);

        $sampleSubjectId = $group->first()['subject_id'];
        $overallRequiredPercent = $mark_configs[$sampleSubjectId]['overall_required'];

        $requiredMark = ($overallRequiredPercent / 100) * $totalMaxMark;
        $combinedStatus = $combinedFinalMark >= $requiredMark ? 'Pass' : 'Fail';

        return [
            'combined_id' => $combinedId,
            'is_combined' => true,
            'combined_name' => $combinedName,
            'combined_final_mark' => $combinedFinalMark,
            'combined_grade_point' => $combinedGradePoint,
            'combined_grade' => $combinedGrade,
            'combined_status' => $combinedStatus,
            'is_uncountable' => false,
            'parts' => $parts,
            'total_max_mark' => $totalMaxMark,
            'percentage' => round($percentage, 2),
            'fail_reason' => $combinedStatus === 'Fail' ? 'Below required mark' : null,
        ];
    }

    // private function processSingle($subj, $gradeRules)
    // {
    //     Log::channel('exam_flex_log')->info($subj);

    //     $mark = $subj['final_mark'] ?? 0;
    //     $config = $subj['mark_config'] ?? null;

    //     $partMarks = $subj['part_marks'] ?? [];

    //     $convertedMark = 0;
    //     foreach ($partMarks as $code => $obtained) {
    //         $conversion = $config['conversion'][$code] ?? 100;
    //         $convertedMark += $obtained * ($conversion / 100);
    //     }

    //     $percentage = $convertedMark > 0 ? ($convertedMark / $convertedMark) * 100 : 0; // 100%

    //     return [
    //         'subject_id' => $subj['subject_id'],
    //         'subject_name' => $subj['subject_name'],
    //         'final_mark' => (float) $mark,
    //         'grade_point' => $this->getGradePoint($percentage, $gradeRules),
    //         'grade' => $this->getGrade($percentage, $gradeRules),
    //         'grace_mark' => (float) ($subj['grace_mark'] ?? 0),
    //         'is_uncountable' => ($subj['subject_type'] ?? '') === 'Uncountable',
    //         'is_combined' => false,
    //         'percentage' => round($percentage, 2),
    //     ];
    // }
    private function processSingle($subj, $gradeRules, $mark_configs)
    {
        $subjectId = $subj['subject_id'];
        $config = $mark_configs[$subjectId] ?? [];
        $partMarks = $subj['part_marks'] ?? [];

        // Conversion দিয়ে সঠিক মার্ক বের করি
        $convertedMark = 0;
        foreach ($partMarks as $code => $obtained) {
            $conversion = $config['conversion'][$code] ?? 100;
            $convertedMark += $obtained * ($conversion / 100);
        }

        $percentage = $convertedMark > 0 ? 100 : 0;

        return [
            'subject_id'     => $subjectId,
            'subject_name'   => $subj['subject_name'],
            'final_mark'     => round($convertedMark, 2),  // এখানে converted mark
            'grade_point'    => $this->getGradePoint($percentage, $gradeRules),
            'grade'          => $this->getGrade($percentage, $gradeRules),
            'grace_mark'     => $subj['grace_mark'] ?? 0,
            'is_uncountable' => ($subj['subject_type'] ?? '') === 'Uncountable',
            'is_combined'    => false,
            'percentage'     => 100.00,
        ];
    }

    // === GPA থেকে Letter Grade (SSC/HSC) ===
    private function gpaToLetterGrade($gpa, $gradeRules)
    {
        // if ($gpa >= 5.00) return 'A+';
        // if ($gpa >= 4.50) return 'A';
        // if ($gpa >= 4.00) return 'A-';
        // if ($gpa >= 3.50) return 'B';
        // if ($gpa >= 3.00) return 'C';
        // if ($gpa >= 2.00) return 'D';
        // return 'F';
        foreach ($gradeRules->sortByDesc('grade_point') as $rule) {
            if ($gpa >= $rule['grade_point']) {
                return $rule['grade'];
            }
        }
    }

    private function getGradePoint($percentage, $gradeRules)
    {
        foreach ($gradeRules as $rule) {
            if ($percentage >= ($rule['from_mark']) && $percentage <= ($rule['to_mark'])) {
                return $rule['grade_point'];
            }
        }
        return 0.0;
    }

    private function getGrade($percentage, $gradeRules)
    {
        foreach ($gradeRules as $rule) {
            if ($percentage >= ($rule['from_mark']) && $percentage <= ($rule['to_mark'])) {
                return $rule['grade'];
            }
        }
        return 'F';
    }

    // Legacy - for single subjects with 100 marks
    private function markToGradePoint($mark, $gradeRules)
    {
        return $this->getGradePoint($mark, $gradeRules);
    }

    private function gpToGrade($mark, $gradeRules)


    {
        return $this->getGrade($mark, $gradeRules);
    }
}
