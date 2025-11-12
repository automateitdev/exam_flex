<?php

namespace App\Services;

class ResultCalculator
{
    private $gradeRules;

    public function __construct($payload)
    {
        $this->gradeRules = collect($payload['grade_rules'] ?? [])
            ->sortByDesc('from_mark');
    }

    public function calculate($payload)
    {
        $results = [];
        $highest = [];

        foreach ($payload['students'] as $student) {
            $result = $this->processStudent($student);
            $results[] = $result;

            foreach ($result['subjects'] as $s) {
                $id = $s['subject_id'];
                $mark = $s['final_mark'];
                $highest[$id] = max($highest[$id] ?? 0, $mark);
            }
        }

        return [
            'results' => $results,
            'highest_marks' => $highest,
            'total_students' => count($results),
        ];
    }

    private function processStudent($student)
    {
        $marks = $student['marks'];
        $optionalId = $student['optional_subject_id'];

        $groups = collect($marks)->groupBy(fn($m, $id) => $m['combined_id'] ?? $id);

        $merged = [];
        $totalGP = 0;
        $subjectCount = 0;
        $failed = false;

        foreach ($groups as $groupId => $group) {
            $mergedSub = count($group) > 1
                ? $this->mergeCombined($group)
                : $this->processSingle($group->first());

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
            $recalcGP = $this->markToGradePoint($opt['final_mark']);
            if ($opt['final_mark'] >= 40 && $recalcGP >= 2) {
                $optionalBonus = $opt['final_mark'] >= 53 ? 13 : ($opt['final_mark'] >= 41 ? 1 : 0);
                $deductGP = 2;
            }
        }

        $finalGP = $failed ? 0 : max(0, $totalGP - $deductGP);
        $gpa = $subjectCount > 0 ? round($finalGP / $subjectCount, 2) : 0;
        $status = $failed ? 'Fail' : ($gpa >= 2.00 ? 'Pass' : 'Fail');
        $letterGrade = $failed ? 'F' : $this->gpToGrade($gpa * 20); // 5.00 = 100

        return [
            'student_id' => $student['student_id'],
            'student_name' => $student['student_name'],
            'roll' => $student['roll'],
            'subjects' => $merged,
            'gpa_without_optional' => $subjectCount > 0 ? round($totalGP / $subjectCount, 2) : 0,
            'gpa' => $gpa,
            'result_status' => $status,
            'letter_grade' => $letterGrade,
            'optional_bonus' => $optionalBonus,
        ];
    }

    private function processSingle($subj)
    {
        $gp = $this->markToGradePoint($subj['final_mark']);
        $grade = $this->gpToGrade($subj['final_mark']);

        return [
            'subject_id' => $subj['subject_id'] ?? $subj['subject_name'],
            'subject_name' => $subj['subject_name'],
            'final_mark' => $subj['final_mark'],
            'grade_point' => $gp,
            'grade' => $grade,
            'grace_mark' => $subj['grace_mark'],
            'is_uncountable' => $subj['subject_type'] === 'Uncountable',
        ];
    }

    private function mergeCombined($group)
    {
        $totalMark = 0;
        $name = [];

        foreach ($group as $subj) {
            $totalMark += $subj['final_mark'];
            $name[] = $subj['subject_name'];
            if ($this->gpToGrade($subj['final_mark']) === 'F') {
                return [
                    'subject_id' => $group->keys()->implode('_'),
                    'subject_name' => implode(' + ', $name),
                    'final_mark' => $totalMark,
                    'grade_point' => 0,
                    'grade' => 'F',
                    'grace_mark' => collect($group)->sum('grace_mark'),
                    'is_uncountable' => false,
                ];
            }
        }

        $avgMark = $totalMark / count($group);
        $grade = $this->gpToGrade($avgMark);
        $gp = $this->markToGradePoint($avgMark);

        return [
            'subject_id' => $group->keys()->implode('_'),
            'subject_name' => implode(' + ', $name),
            'final_mark' => round($avgMark, 2),
            'grade_point' => $gp,
            'grade' => $grade,
            'grace_mark' => collect($group)->sum('grace_mark'),
            'is_uncountable' => false,
        ];
    }

    private function gpToGrade($mark)
    {
        foreach ($this->gradeRules as $rule) {
            if ($mark >= $rule['from_mark'] && $mark <= $rule['to_mark']) {
                return $rule['grade'];
            }
        }
        return 'F';
    }

    private function markToGradePoint($mark)
    {
        foreach ($this->gradeRules as $rule) {
            if ($mark >= $rule['from_mark'] && $mark <= $rule['to_mark']) {
                return $rule['grade_point'];
            }
        }
        return 0.0;
    }
}
