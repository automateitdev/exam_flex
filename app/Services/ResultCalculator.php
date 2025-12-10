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

                // $totalMarkWithoutOptional += $combinedResult['combined_final_mark'];
            } else {
                $single = $this->processSingle($first, $gradeRules, $mark_configs);
                $single['is_optional'] = ($first['subject_id'] == $optionalId);
                $merged[] = $single;

                if (!$single['is_uncountable'] && !$single['is_optional']) {
                    $totalGP += $single['grade_point'];
                    $subjectCount++;
                }
                if ($single['grade'] === 'F' && !$single['is_uncountable'] && !$single['is_optional']) {
                    $failed = true;
                    $totalGP = 0;
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

        $totalMarkWithOptional = $totalMarkWithoutOptional + ($optionalFullMark * 0.40);

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
            'gpa_without_optional' => $failed ? 0 : $gpaWithoutOptional,
            'letter_grade_without_optional' => $failed ? 'F' : $letterGradeWithout,
            'total_mark_with_optional' => ceil($totalMarkWithOptional),
            'gpa_with_optional' => $gpaWithOptional,
            'letter_grade_with_optional' => $letterGradeWith,
            'result_status' => $status,
            'optional_bonus_gp' => $bonusGPFromOptional,
        ];
    }

    private function processSingle($subj, $gradeRules, $mark_configs)
    {
        $subjectId = $subj['subject_id'];
        $config = $mark_configs[$subjectId] ?? [];
        $partMarks = $subj['part_marks'] ?? [];

        // শুধু ডিসপ্লের জন্য total_marks এবং converted_mark হিসাব করছি
        // গ্রেড বা পার্সেন্টেজ হিসাব করছি না — Mark Entry থেকে নিচ্ছি
        $totalMarks = collect($partMarks)->sum();

        $convertedMark = 0;
        $totalMaxConverted = 0;

        foreach ($partMarks as $code => $obtained) {
            $conversion = $config['conversion'][$code] ?? 100;
            $totalPart = $config['total_marks'][$code] ?? 100;

            $convertedMark += $obtained * ($conversion / 100);
            $totalMaxConverted += $totalPart; // PR সহ সব পার্ট যোগ হবে
        }

        $grace = $subj['grace_mark'] ?? 0;
        $finalMark = $convertedMark + $grace;

        // পার্সেন্টেজ হিসাব করছি শুধু ডিসপ্লের জন্য — কিন্তু গ্রেড এর জন্য ব্যবহার করছি না!
        $percentage = $totalMaxConverted > 0 ? ($finalMark / $totalMaxConverted) * 100 : 0;

        return [
            'subject_id'     => $subjectId,
            'subject_name'   => $subj['subject_name'],
            'part_marks'     => $partMarks,
            'total_marks'    => $totalMarks,
            'converted_mark' => round($convertedMark, 2),
            'final_mark'     => round($finalMark, 2),
            'grace_mark'     => $grace,
            'percentage'     => round($percentage, 2),

            // গুরুত্বপূর্ণ: গ্রেড ও গ্রেড পয়েন্ট Mark Entry থেকে নিচ্ছি — নতুন হিসাব করছি না!
            'grade_point'    => $subj['grade_point'],
            'grade'          => $subj['grade'],

            'is_uncountable' => ($subj['subject_type'] ?? '') === 'Uncountable',
            'is_combined'    => false,
        ];
    }

    private function processCombinedGroup($group, $gradeRules, $mark_configs)
    {
        $combinedId   = $group->first()['combined_id'];
        $combinedName = $group->first()['combined_subject_name'];

        $totalMarks = 0;
        $totalConverted = 0;
        $totalGrace = 0;

        $parts = $group->map(function ($mark) use ($mark_configs, &$totalMarks, &$totalConverted, &$totalGrace) {
            $subjectId = $mark['subject_id'];
            $partMarks = $mark['part_marks'] ?? [];
            $config = $mark_configs[$subjectId] ?? [];

            $convertedMark = 0;
            $totalPartMarks = collect($partMarks)->sum();

            foreach ($partMarks as $code => $obtained) {
                $conversion = $config['conversion'][$code] ?? 100;
                $convertedMark += $obtained * ($conversion / 100);
            }

            $grace = $mark['grace_mark'] ?? 0;
            $finalMark = $convertedMark + $grace;

            $totalMarks += $totalPartMarks;
            $totalConverted += $convertedMark;
            $totalGrace += $grace;

            return [
                'subject_id'     => $subjectId,
                'subject_name'   => $mark['subject_name'],
                'part_marks'     => $partMarks,
                'total_marks'    => $totalPartMarks,
                'converted_mark' => round($convertedMark, 2),
                'final_mark'     => round($finalMark, 2),
                'grace_mark'     => $grace,
                'grade_point'    => $mark['grade_point'] ?? 0,
                'grade'          => $mark['grade'] ?? 'F',
            ];
        })->values()->toArray();

        // Average grade point for combined
        $combinedGradePoint = round(collect($parts)->avg('grade_point'), 2);
        // $combinedGrade      = $this->gpToGrade($combinedGradePoint, $gradeRules);
        $combinedGrade = $this->gpaToLetterGrade($combinedGradePoint, $gradeRules);

        return [
            'combined_id'          => $combinedId,
            'is_combined'          => true,
            'combined_name'        => $combinedName,
            'total_marks'          => $totalMarks,
            'converted_mark'       => round($totalConverted, 2),
            'final_mark'           => round($totalConverted + $totalGrace, 2),
            'grace_mark'           => $totalGrace,
            'combined_grade_point' => $combinedGradePoint,
            'combined_grade'       => $combinedGrade,
            'combined_status'      => $combinedGrade === 'F' ? 'Fail' : 'Pass',
            'parts'                => $parts,
            'is_uncountable'       => false,
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
