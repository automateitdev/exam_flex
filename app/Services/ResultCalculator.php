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

    private function processStudent($student, $gradeRules, $mark_configs)
    {
        $marks = $student['marks'] ?? [];
        $optionalId = $student['optional_subject_id'] ?? null;

        $groups = collect($marks)->groupBy(fn($m) => $m['combined_id'] ?? $m['subject_id']);

        $merged = [];
        $totalGP = 0;
        $subjectCount = 0;
        $failed = false;

        $totalMarkWithoutOptional = 0.0;  // মূল মার্ক (৪র্থ ছাড়া)
        $totalMarkWithOptional    = 0.0;  // ৪র্থ পুরো মার্ক যোগ

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
                    // $failed = true;
                    if (!($combinedResult['is_uncountable'] ?? false)) {
                        $failed = true;
                    }
                }

                $totalMarkWithoutOptional += $combinedResult['combined_final_mark'];
            } else {
                $single = $this->processSingle($first, $gradeRules, $mark_configs);
                $single['is_optional'] = ($first['subject_id'] == $optionalId);
                $merged[] = $single;

                if (!$single['is_uncountable']) {
                    $totalGP += $single['grade_point'];
                    $subjectCount++;
                }
                if ($single['grade'] === 'F' && !$single['is_uncountable']) {
                    $failed = true;
                }

                if (!$single['is_optional'] && !$single['is_uncountable']) {
                    $totalMarkWithoutOptional += $single['final_mark'];
                }
            }
        }

        // === 4th Subject Bonus ===
        $optionalFullMark = 0.0;
        $bonusGPFromOptional = 0.0;

        if ($optionalId && isset($marks[$optionalId])) {
            $opt = $marks[$optionalId];
            $optionalFullMark = $opt['final_mark'] ?? 0;
            $optGP = $opt['grade_point'] ?? 0;

            if ($optGP >= 2.00) {
                $bonusGPFromOptional = max(0, $optGP - 2.00);
            }
        }

        $totalMarkWithOptional = $totalMarkWithoutOptional + $optionalFullMark;

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
            'total_mark_without_optional' => round($totalMarkWithoutOptional, 2),
            'gpa_without_optional' => $gpaWithoutOptional,
            'letter_grade_without_optional' => $letterGradeWithout,
            'total_mark_with_optional' => round($totalMarkWithOptional, 2),
            'gpa_with_optional' => $gpaWithOptional,
            'letter_grade_with_optional' => $letterGradeWith,
            'result_status' => $status,
            'optional_bonus_gp' => $bonusGPFromOptional,
        ];
    }

    // private function processCombinedGroup($group, $gradeRules, $mark_configs)
    // {
    //     $combinedId = $group->first()['combined_id'];
    //     $combinedName = $group->first()['combined_subject_name'];

    //     $totalConvertedMark = 0;
    //     $totalMaxMark = 0; // এটা যোগ করুন

    //     $parts = $group->map(function ($mark) use ($mark_configs, &$totalConvertedMark, &$totalMaxMark) {
    //         $subjectId = $mark['subject_id'];
    //         $config = $mark_configs[$subjectId] ?? [];
    //         $partMarks = $mark['part_marks'] ?? [];

    //         $convertedMark = 0;
    //         $maxMarkForSubject = 0;

    //         foreach ($partMarks as $code => $obtained) {
    //             $conversion = $config['conversion'][$code] ?? 100;
    //             $totalMarkForPart = $config['total_marks'][$code] ?? 100; // এটা ব্যবহার করুন

    //             $convertedMark += $obtained * ($conversion / 100);
    //             $maxMarkForSubject += $totalMarkForPart * ($conversion / 100);
    //         }

    //         $totalConvertedMark += $convertedMark;
    //         $totalMaxMark += $maxMarkForSubject;

    //         return [
    //             'subject_id'       => $subjectId,
    //             'subject_name'     => $mark['subject_name'],
    //             'final_mark'       => round($convertedMark, 2),
    //             'grade_point'      => $mark['grade_point'],
    //             'grade'            => $mark['grade'],
    //             'grace_mark'       => $mark['grace_mark'],
    //             'part_marks'       => $partMarks,
    //             'pass_marks'       => $config['pass_marks'] ?? [],
    //             'overall_required' => $config['overall_required'] ?? 33.0,
    //         ];
    //     })->values()->toArray();

    //     $combinedFinalMark = $totalConvertedMark;

    //     // এটাই সঠিক পার্সেন্টেজ
    //     $percentage = $totalMaxMark > 0 ? ($combinedFinalMark / $totalMaxMark) * 100 : 0;

    //     $combinedGradePoint = $this->getGradePoint($percentage, $gradeRules);
    //     $combinedGrade = $this->getGrade($percentage, $gradeRules);

    //     $sampleSubjectId = $group->first()['subject_id'];
    //     $overallRequiredPercent = $mark_configs[$sampleSubjectId]['overall_required'] ?? 33.0;
    //     $requiredMark = ($overallRequiredPercent / 100) * $totalMaxMark;
    //     $combinedStatus = $combinedFinalMark >= $requiredMark ? 'Pass' : 'Fail';

    //     return [
    //         'combined_id'           => $combinedId,
    //         'is_combined'           => true,
    //         'combined_name'         => $combinedName,
    //         'combined_final_mark'   => round($combinedFinalMark, 2),
    //         'combined_grade_point'  => $combinedGradePoint,
    //         'combined_grade'        => $combinedGrade,
    //         'combined_status'       => $combinedStatus,
    //         'is_uncountable'        => false,
    //         'parts'                 => $parts,
    //         'total_max_mark'        => round($totalMaxMark, 2),
    //         'percentage'            => round($percentage, 2),
    //         'fail_reason'           => $combinedStatus === 'Fail' ? 'Below required mark' : null,
    //     ];
    // }

    // private function processSingle($subj, $gradeRules, $mark_configs)
    // {
    //     $subjectId = $subj['subject_id'];
    //     $config = $mark_configs[$subjectId] ?? [];
    //     $partMarks = $subj['part_marks'] ?? [];

    //     $convertedMark = 0;
    //     foreach ($partMarks as $code => $obtained) {
    //         $conversion = $config['conversion'][$code] ?? 100;
    //         $convertedMark += $obtained * ($conversion / 100);
    //     }

    //     $percentage = $convertedMark;

    //     return [
    //         'subject_id' => $subjectId,
    //         'subject_name' => $subj['subject_name'],
    //         'final_mark' => round($convertedMark, 2),
    //         'part_marks'     => $subj['part_marks'] ?? [],
    //         'grade_point' => $this->getGradePoint($percentage, $gradeRules),
    //         'grade' => $this->getGrade($percentage, $gradeRules),
    //         'grace_mark' => $subj['grace_mark'] ?? 0,
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

        // ১. total_marks = সব পার্টের অরিজিনাল মার্ক যোগ
        $totalMarks = collect($partMarks)->sum();

        // ২. converted_mark = conversion অনুযায়ী
        $convertedMark = 0;
        $totalMaxConverted = 0;

        foreach ($partMarks as $code => $obtained) {
            $conversion = $config['conversion'][$code] ?? 100;
            $totalMarkForPart = $config['total_marks'][$code] ?? 100;

            $convertedMark += $obtained * ($conversion / 100);
            $totalMaxConverted += $totalMarkForPart * ($conversion / 100);
        }

        // ৩. grace_mark যোগ (যদি থাকে)
        $grace = $subj['grace_mark'] ?? 0;
        $finalMark = $convertedMark + $grace;

        // ৪. পার্সেন্টেজ
        $percentage = $totalMaxConverted > 0 ? ($finalMark / $totalMaxConverted) * 100 : 0;

        // ৫. গ্রেড পার্সেন্টেজ দিয়ে
        $gradePoint = $this->getGradePoint($percentage, $gradeRules);
        $grade = $this->getGrade($percentage, $gradeRules);

        return [
            'subject_id'     => $subjectId,
            'subject_name'   => $subj['subject_name'],
            'total_marks'    => $totalMarks,                    // নতুন
            'converted_mark' => round($convertedMark, 2),       // নতুন
            'final_mark'     => round($finalMark, 2),           // গ্রেস যোগ হয়েছে
            'grace_mark'     => $grace,
            'percentage'     => round($percentage, 2),
            'grade_point'    => $gradePoint,
            'grade'          => $grade,
            'is_uncountable' => ($subj['subject_type'] ?? '') === 'Uncountable',
            'is_combined'    => false,
        ];
    }

    private function processCombinedGroup($group, $gradeRules, $mark_configs)
    {
        $combinedId   = $group->first()['combined_id'];
        $combinedName = $group->first()['combined_subject_name'];

        $totalMarks       = 0;
        $convertedMark    = 0;
        $totalMaxConverted = 0;

        $parts = $group->map(function ($mark) use ($mark_configs, &$totalMarks, &$convertedMark, &$totalMaxConverted) {
            $subjectId = $mark['subject_id'];
            $config    = $mark_configs[$subjectId] ?? [];
            $partMarks = $mark['part_marks'] ?? [];

            $partTotal = collect($partMarks)->sum();

            $convMark = 0;
            $maxConv  = 0;

            foreach ($partMarks as $code => $obtained) {
                $conv = $config['conversion'][$code] ?? 100;
                $totalPart = $config['total_marks'][$code] ?? 100;

                $convMark += $obtained * ($conv / 100);
                $maxConv  += $totalPart * ($conv / 100);
            }

            $totalMarks       += $partTotal;
            $convertedMark    += $convMark;
            $totalMaxConverted += $maxConv;

            return [
                'subject_id'   => $subjectId,
                'subject_name' => $mark['subject_name'],
                'total_marks'  => $partTotal,
                'converted_mark' => round($convMark, 2),
                'final_mark'   => round($convMark + ($mark['grace_mark'] ?? 0), 2),
                'grace_mark'   => $mark['grace_mark'] ?? 0,
                'grade_point'  => $mark['grade_point'],
                'grade'        => $mark['grade'],
            ];
        })->values()->toArray();

        $graceTotal = $group->sum('grace_mark');
        $finalCombinedMark = $convertedMark + $graceTotal;

        $percentage = $totalMaxConverted > 0 ? ($finalCombinedMark / $totalMaxConverted) * 100 : 0;

        $gradePoint = $this->getGradePoint($percentage, $gradeRules);
        $grade      = $this->getGrade($percentage, $gradeRules);

        $overallReqPercent = $mark_configs[$group->first()['subject_id']]['overall_required'] ?? 33;
        $requiredMark = ($overallReqPercent / 100) * $totalMaxConverted;
        $status = $finalCombinedMark >= $requiredMark ? 'Pass' : 'Fail';

        return [
            'combined_id'           => $combinedId,
            'is_combined'           => true,
            'combined_name'         => $combinedName,
            'total_marks'           => $totalMarks,
            'converted_mark'        => round($convertedMark, 2),
            'final_mark'            => round($finalCombinedMark, 2),
            'grace_mark'            => $graceTotal,
            'percentage'            => round($percentage, 2),
            'combined_grade_point'  => $gradePoint,
            'combined_grade'        => $grade,
            'combined_status'       => $status,
            'is_uncountable'        => false,
            'parts'                 => $parts,
        ];
    }
    // === GPA থেকে Letter Grade (SSC/HSC) ===
    private function gpaToLetterGrade($gpa, $gradeRules)
    {
        foreach ($gradeRules->sortByDesc('grade_point') as $rule) {
            if ($gpa >= $rule['grade_point']) {
                return $rule['grade'];
            }
        }
    }

    private function getGradePoint($percentage, $gradeRules)
    {
        foreach ($gradeRules as $rule) {
            if ($percentage >= $rule['from_mark'] && $percentage <= $rule['to_mark']) {
                return $rule['grade_point'];
            }
        }
        return 0.0;
    }

    private function getGrade($percentage, $gradeRules)
    {
        foreach ($gradeRules as $rule) {
            if ($percentage >= $rule['from_mark'] && $percentage <= $rule['to_mark']) {
                return $rule['grade'];
            }
        }
        return 'F';
    }

    private function markToGradePoint($mark, $gradeRules)
    {
        return $this->getGradePoint($mark, $gradeRules);
    }

    private function gpToGrade($mark, $gradeRules)
    {
        return $this->getGrade($mark, $gradeRules);
    }
}
