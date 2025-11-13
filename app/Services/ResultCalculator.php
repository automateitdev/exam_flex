<?php

namespace App\Services;

use Illuminate\Support\Collection;

class ResultCalculator
{
    public function calculate($payload)
    {
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
            return [
                'results' => [],
                'highest_marks' => [],
                'total_students' => 0
            ];
        }

        $results = [];
        $highestCollection = collect(); // ← subject_id + name + highest_mark

        foreach ($students as $student) {
            $result = $this->processStudent($student, $gradeRules);
            $results[] = $result;

            // শুধু একক সাবজেক্ট থেকে highest_marks সংগ্রহ
            foreach ($result['subjects'] as $s) {
                $subjectId = $s['subject_id'] ?? null;
                $subjectName = $s['subject_name'] ?? 'Unknown';
                $mark = $s['final_mark'] ?? 0;

                // শুধু যদি subject_id সংখ্যা হয় (একক সাবজেক্ট)
                if (is_numeric($subjectId) && $subjectId > 0) {
                    $key = "id_{$subjectId}"; // unique key

                    if (!$highestCollection->has($key)) {
                        $highestCollection->put($key, [
                            'subject_id' => (int) $subjectId,
                            'subject_name' => $subjectName,
                            'highest_mark' => $mark
                        ]);
                    } else {
                        $current = $highestCollection->get($key);
                        if ($mark > $current['highest_mark']) {
                            $highestCollection->put($key, [
                                'subject_id' => (int) $subjectId,
                                'subject_name' => $subjectName,
                                'highest_mark' => $mark
                            ]);
                        }
                    }
                }
                // কম্বাইন্ড সাবজেক্ট → skip
            }
        }

        return [
            'results' => $results,
            'highest_marks' => $highestCollection->values()->toArray(), // ← array of objects
            'total_students' => count($results),
        ];
    }

    private function processStudent($student, $gradeRules)
    {
        $marks = $student['marks'] ?? [];
        $optionalId = $student['optional_subject_id'] ?? null;

        $groups = collect($marks)->groupBy(fn($m, $id) => $m['combined_id'] ?? $id);

        $merged = [];
        $totalGP = 0;
        $subjectCount = 0;
        $failed = false;

        foreach ($groups as $group) {
            if ($group->isEmpty()) continue;

            $mergedSub = $group->count() > 1
                ? $this->mergeCombined($group, $gradeRules)
                : $this->processSingle($group->first(), $gradeRules);

            if ($mergedSub['grade'] === 'F') {
                $failed = true;
            }

            if (!$mergedSub['is_uncountable']) {
                $totalGP += $mergedSub['grade_point'];
                $subjectCount++;
            }

            $merged[] = $mergedSub;
        }

        // 4th Subject Rule
        $optionalBonus = 0;
        $deductGP = 0;
        if ($optionalId && isset($marks[$optionalId])) {
            $opt = $marks[$optionalId];
            $optMark = $opt['final_mark'] ?? 0;
            $recalcGP = $this->markToGradePoint($optMark, $gradeRules);

            if ($optMark >= 40 && $recalcGP >= 2) {
                $optionalBonus = $optMark >= 53 ? 13 : ($optMark >= 41 ? 1 : 0);
                $deductGP = 2;
            }
        }

        $finalGP = $failed ? 0 : max(0, $totalGP - $deductGP);
        $gpa = $subjectCount > 0 ? round($finalGP / $subjectCount, 2) : 0;
        $status = $failed ? 'Fail' : ($gpa >= 2.00 ? 'Pass' : 'Fail');
        $letterGrade = $failed ? 'F' : $this->gpToGrade($gpa * 20, $gradeRules);

        return [
            'student_id' => $student['student_id'] ?? 0,
            'student_name' => $student['student_name'] ?? 'N/A',
            'roll' => $student['roll'] ?? 'N/A',
            'subjects' => $merged,
            'gpa_without_optional' => $subjectCount > 0 ? round($totalGP / $subjectCount, 2) : 0,
            'gpa' => $gpa,
            'result_status' => $status,
            'letter_grade' => $letterGrade,
            'optional_bonus' => $optionalBonus,
        ];
    }

    private function processSingle($subj, $gradeRules)
    {
        $mark = $subj['final_mark'] ?? 0;
        $subjectId = $subj['subject_id'] ?? null;

        return [
            'subject_id' => $subjectId, // ← সংখ্যা হলে highest_marks এ যাবে
            'subject_name' => $subj['subject_name'] ?? 'Unknown',
            'final_mark' => (float) $mark,
            'grade_point' => $this->markToGradePoint($mark, $gradeRules),
            'grade' => $this->gpToGrade($mark, $gradeRules),
            'grace_mark' => (float) ($subj['grace_mark'] ?? 0),
            'is_uncountable' => ($subj['subject_type'] ?? '') === 'Uncountable',
        ];
    }

    private function mergeCombined($group, $gradeRules)
    {
        $totalMark = 0;
        $names = [];

        foreach ($group as $subj) {
            $mark = $subj['final_mark'] ?? 0;
            $totalMark += $mark;
            $names[] = $subj['subject_name'] ?? 'Unknown';

            if ($this->gpToGrade($mark, $gradeRules) === 'F') {
                return [
                    'subject_id' => 'combined_' . $group->keys()->implode('_'), // ← string
                    'subject_name' => implode(' + ', $names),
                    'final_mark' => $totalMark,
                    'grade_point' => 0,
                    'grade' => 'F',
                    'grace_mark' => collect($group)->sum('grace_mark'),
                    'is_uncountable' => false,
                ];
            }
        }

        $avgMark = $totalMark / count($group);
        return [
            'subject_id' => 'combined_' . $group->keys()->implode('_'), // ← string
            'subject_name' => implode(' + ', $names),
            'final_mark' => round($avgMark, 2),
            'grade_point' => $this->markToGradePoint($avgMark, $gradeRules),
            'grade' => $this->gpToGrade($avgMark, $gradeRules),
            'grace_mark' => collect($group)->sum('grace_mark'),
            'is_uncountable' => false,
        ];
    }

    private function gpToGrade($mark, $gradeRules)
    {
        foreach ($gradeRules as $rule) {
            if ($mark >= ($rule['from_mark'] ?? 0) && $mark <= ($rule['to_mark'] ?? 0)) {
                return $rule['grade'] ?? 'F';
            }
        }
        return 'F';
    }

    private function markToGradePoint($mark, $gradeRules)
    {
        foreach ($gradeRules as $rule) {
            if ($mark >= ($rule['from_mark'] ?? 0) && $mark <= ($rule['to_mark'] ?? 0)) {
                return $rule['grade_point'] ?? 0.0;
            }
        }
        return 0.0;
    }
}
