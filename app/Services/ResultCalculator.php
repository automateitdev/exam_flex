<?php

namespace App\Services;

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
        $highest = [];

        foreach ($students as $student) {
            $result = $this->processStudent($student, $gradeRules);
            $results[] = $result;

            foreach ($result['subjects'] as $s) {
                $id = $s['subject_id'] ?? null;
                $mark = $s['final_mark'] ?? 0;
                if ($id !== null) {
                    $highest[$id] = max($highest[$id] ?? 0, $mark);
                }
            }
        }

        return [
            'results' => $results,
            'highest_marks' => $highest,
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
            'subject_id' => $subj['subject_id'] ?? $subj['subject_name'] ?? 'unknown',
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
                    'subject_id' => $group->keys()->implode('_'),
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
            'subject_id' => $group->keys()->implode('_'),
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
