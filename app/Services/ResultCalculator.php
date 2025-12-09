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
        Log::channel('exam_flex_log')->info('subject: ' . json_encode($subj));
        Log::channel('exam_flex_log')->info('mark_configs: ' . json_encode($mark_configs));
        Log::channel('exam_flex_log')->info('gradeRules: ' . json_encode($gradeRules));


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

    // private function processCombinedGroup($group, $gradeRules, $mark_configs)
    // {
    //     $combinedId   = $group->first()['combined_id'];
    //     $combinedName = $group->first()['combined_subject_name'];

    //     $totalMarks       = 0;      // CQ + MCQ + PR (অরিজিনাল)
    //     $convertedMark    = 0;      // conversion পরে
    //     $totalMaxConverted = 0;     // সর্বোচ্চ converted মার্ক
    //     $totalGrace       = 0;

    //     $parts = $group->map(function ($mark) use ($mark_configs, &$totalMarks, &$convertedMark, &$totalMaxConverted, &$totalGrace) {
    //         $subjectId = $mark['subject_id'];
    //         $config    = $mark_configs[$subjectId] ?? [];
    //         $partMarks = $mark['part_marks'] ?? [];

    //         // অরিজিনাল মার্ক যোগ
    //         $partTotal = collect($partMarks)->sum();
    //         $totalMarks += $partTotal;

    //         $convMark = 0;
    //         $maxConv  = 0;

    //         foreach ($partMarks as $code => $obtained) {
    //             $conv       = $config['conversion'][$code] ?? 100;
    //             $totalPart  = $config['total_marks'][$code] ?? 100;

    //             $convMark += $obtained * ($conv / 100);
    //             $maxConv  += $totalPart;
    //         }

    //         $grace = $mark['grace_mark'] ?? 0;
    //         $totalGrace += $grace;

    //         $convertedMark    += $convMark;
    //         $totalMaxConverted += $maxConv;

    //         return [
    //             'subject_id'      => $subjectId,
    //             'subject_name'    => $mark['subject_name'],
    //             'total_marks'     => $partTotal,
    //             'converted_mark'  => round($convMark, 2),
    //             'final_mark'      => round($convMark + $grace, 2),
    //             'grace_mark'      => $grace,
    //             'percentage'      => $maxConv > 0 ? round((($convMark + $grace) / $maxConv) * 100, 2) : 0,
    //             'grade'           => $mark['grade'],
    //             'grade_point'     => $mark['grade_point'],
    //             'part_marks'      => $partMarks,
    //         ];
    //     })->values()->toArray();

    //     // কম্বাইন্ডের ফাইনাল মার্ক (গ্রেস সহ)
    //     $combinedFinalMark = $convertedMark + $totalGrace;

    //     // পার্সেন্টেজ
    //     $percentage = $totalMaxConverted > 0 ? ($combinedFinalMark / $totalMaxConverted) * 100 : 0;

    //     // গ্রেড এবং পয়েন্ট (পার্সেন্টেজ দিয়ে)
    //     $combinedGradePoint = $this->getGradePoint($percentage, $gradeRules);
    //     $combinedGrade      = $this->getGrade($percentage, $gradeRules);

    //     // Overall Pass/Fail চেক
    //     $sampleSubjectId = (string) $group->first()['subject_id'];
    //     $overallReqPercent = $mark_configs[$sampleSubjectId]['overall_required'];
    //     $requiredMark = ($overallReqPercent / 100) * $totalMaxConverted;
    //     $combinedStatus = $combinedFinalMark >= $requiredMark ? 'Pass' : 'Fail';

    //     return [
    //         'combined_id'           => $combinedId,
    //         'is_combined'           => true,
    //         'combined_name'         => $combinedName,

    //         // আপনার চাওয়া সব ফিল্ড
    //         'total_marks'           => $totalMarks,
    //         'converted_mark'        => round($convertedMark, 2),
    //         'final_mark'            => round($combinedFinalMark, 2),
    //         'grace_mark'            => $totalGrace,
    //         'percentage'            => round($percentage, 2),

    //         // কম্বাইন্ডের গ্রেড
    //         'combined_final_mark'   => round($combinedFinalMark, 2),
    //         'combined_grade_point'  => $combinedGradePoint,
    //         'combined_grade'        => $combinedGrade,
    //         'combined_status'       => $combinedStatus,

    //         'is_uncountable'        => false,
    //         'parts'                 => $parts,
    //         'total_max_mark'        => round($totalMaxConverted, 2),
    //         'fail_reason'           => $combinedStatus === 'Fail' ? 'Below required mark' : null,
    //     ];
    // }

    // private function processCombinedGroup($group, $gradeRules, $mark_configs)
    // {
    //     $combinedId   = $group->first()['combined_id'];
    //     $combinedName = $group->first()['combined_subject_name'];

    //     $totalMarks       = 0;
    //     $convertedMark    = 0;
    //     $totalGrace       = 0;

    //     // ❗ We will not calculate wrong max from part configs.
    //     // Instead: max mark = number_of_parts × 100 (because frontend uses 100 scale)
    //     $totalMaxConverted = $group->count() * 100;

    //     $parts = $group->map(function ($mark) use (&$totalMarks, &$convertedMark, &$totalGrace) {

    //         $partTotal      = $mark['total_marks'] ?? collect($mark['part_marks'])->sum();
    //         $finalMark      = $mark['final_mark'];
    //         $converted      = $mark['final_mark'] - ($mark['grace_mark'] ?? 0);
    //         $grace          = $mark['grace_mark'] ?? 0;

    //         $totalMarks    += $partTotal;
    //         $convertedMark += $converted;
    //         $totalGrace    += $grace;

    //         // ❗ percentage must be based on 100 because FE uses 100-scale conversion
    //         $percentage = round(($finalMark / 100) * 100, 2);

    //         return [
    //             'subject_id'      => $mark['subject_id'],
    //             'subject_name'    => $mark['subject_name'],
    //             'total_marks'     => $partTotal,
    //             'converted_mark'  => $converted,
    //             'final_mark'      => $finalMark,
    //             'grace_mark'      => $grace,
    //             'percentage'      => $percentage,
    //             'grade'           => $mark['grade'],         // ✔ Use frontend grade
    //             'grade_point'     => $mark['grade_point'],   // ✔ Use frontend grade_point
    //             'part_marks'      => $mark['part_marks'],
    //         ];
    //     })->values()->toArray();

    //     // -------------------------
    //     // 💡 Combined Calculations
    //     // -------------------------

    //     $combinedFinalMark = $convertedMark + $totalGrace;

    //     // ❗ BASED ON 100-SCALE — correct logic
    //     $percentage = round(($combinedFinalMark / $totalMaxConverted) * 100, 2);

    //     // ❗ DO NOT CALCULATE GRADE FROM % — use average grade_point
    //     $combinedGradePoint = round(collect($parts)->avg('grade_point'), 2);
    //     $combinedGrade      = $this->gpToGrade($combinedGradePoint, $gradeRules);

    //     // -------------------------
    //     // Pass/Fail rule
    //     // -------------------------

    //     // overall_required is % (like 33)
    //     $sampleSubjectId = (string) $group->first()['subject_id'];
    //     $overallReqPercent = $mark_configs[$sampleSubjectId]['overall_required'] ?? 33;

    //     $requiredMark = ($overallReqPercent / 100) * $totalMaxConverted;

    //     if ($combinedGrade === 'F') {
    //         $combinedStatus = 'Fail';
    //     } else {
    //         $combinedStatus = $combinedFinalMark >= $requiredMark ? 'Pass' : 'Fail';
    //     }

    //     return [
    //         'combined_id'           => $combinedId,
    //         'is_combined'           => true,
    //         'combined_name'         => $combinedName,
    //         'total_marks'           => $totalMarks,
    //         'converted_mark'        => round($convertedMark, 2),
    //         'final_mark'            => round($combinedFinalMark, 2),
    //         'grace_mark'            => $totalGrace,
    //         'percentage'            => $percentage,
    //         'combined_final_mark'   => round($combinedFinalMark, 2),
    //         'combined_grade_point'  => $combinedGradePoint,
    //         'combined_grade'        => $combinedGrade,
    //         'combined_status'       => $combinedStatus,
    //         'is_uncountable'        => false,
    //         'parts'                 => $parts,

    //         // ✔ SAFE: FE expects total_max_mark → send 100 × parts_count
    //         'total_max_mark'        => $totalMaxConverted,

    //         'fail_reason'           => $combinedStatus === 'Fail' ? 'Below required mark' : null,
    //     ];
    // }

    
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
        $combinedGrade      = $this->gpToGrade($combinedGradePoint, $gradeRules);

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
