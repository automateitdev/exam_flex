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
            return ['results' => [], 'highest_marks' => [], 'total_students' => 0];
        }

        $results = [];
        $highestCollection = collect();

        foreach ($students as $student) {
            $result = $this->processStudent($student, $gradeRules);
            $results[] = $result;

            // Collect highest marks (only single subjects)
            // calculate() এ
            foreach ($result['subjects'] as $s) {
                if ($s['is_combined'] || empty($s['subject_id'])) continue;

                $id = $s['subject_id'];
                $name = $s['subject_name'];
                $mark = $s['final_mark'];

                $key = "single_{$id}";
                if (!$highestCollection->has($key)) {
                    $highestCollection->put($key, [
                        'subject_id' => $id,
                        'subject_name' => $name,
                        'highest_mark' => $mark
                    ]);
                } else {
                    $current = $highestCollection->get($key);
                    if ($mark > $current['highest_mark']) {
                        $highestCollection->put($key, [
                            'subject_id' => $id,
                            'subject_name' => $name,
                            'highest_mark' => $mark
                        ]);
                    }
                }
            }
        }

        return [
            'results' => $results,
            'highest_marks' => $highestCollection->values()->toArray(),
            'total_students' => count($results),
        ];
    }

    private function processStudent($student, $gradeRules)
    {
        $marks = $student['marks'] ?? [];
        $optionalId = $student['optional_subject_id'] ?? null;

        $groups = collect($marks)->groupBy(fn($m) => $m['combined_id'] ?? $m['subject_id']);

        $merged = [];
        $totalGP = 0;
        $subjectCount = 0;
        $failed = false;

        foreach ($groups as $groupId => $group) {
            if ($group->isEmpty()) continue;

            $first = $group->first();

            // If combined
            if ($first['is_combined']) {
                $mergedSub = $this->processCombined($group, $gradeRules);
            } else {
                $mergedSub = $this->processSingle($first, $gradeRules);
            }

            if ($mergedSub['grade'] === 'F') $failed = true;
            if (!$mergedSub['is_uncountable']) {
                $totalGP += $mergedSub['grade_point'];
                $subjectCount++;
            }

            $merged[] = $mergedSub;
        }

        // 4th Subject
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
        return [
            'subject_id' => $subj['subject_id'],
            'subject_name' => $subj['subject_name'],
            'final_mark' => (float) $mark,
            'grade_point' => $this->markToGradePoint($mark, $gradeRules),
            'grade' => $this->gpToGrade($mark, $gradeRules),
            'grace_mark' => (float) ($subj['grace_mark'] ?? 0),
            'is_uncountable' => ($subj['subject_type'] ?? '') === 'Uncountable',
            'is_combined' => false,
        ];
    }

    private function processCombined($group, $gradeRules)
    {
        $totalMark = 0;
        $combinedName = $group->first()['combined_subject_name'] ?? 'Unknown Combined';

        foreach ($group as $subj) {
            $mark = $subj['final_mark'] ?? 0;
            $totalMark += $mark;

            if ($this->gpToGrade($mark, $gradeRules) === 'F') {
                return [
                    'subject_id' => 'combined_' . implode('_', array_keys($group->toArray())),
                    'subject_name' => $combinedName,
                    'final_mark' => $totalMark,
                    'grade_point' => 0,
                    'grade' => 'F',
                    'grace_mark' => collect($group)->sum('grace_mark'),
                    'is_uncountable' => false,
                    'is_combined' => true,
                ];
            }
        }

        $avgMark = $totalMark / count($group);
        return [
            'subject_id' => 'combined_' . implode('_', array_keys($group->toArray())),
            'subject_name' => $combinedName,
            'final_mark' => round($avgMark, 2),
            'grade_point' => $this->markToGradePoint($avgMark, $gradeRules),
            'grade' => $this->gpToGrade($avgMark, $gradeRules),
            'grace_mark' => collect($group)->sum('grace_mark'),
            'is_uncountable' => false,
            'is_combined' => true,
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
