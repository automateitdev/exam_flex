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

            // === Collect highest marks from parts & single subjects ===
            foreach ($result['subjects'] as $s) {
                // Combined subject with parts
                if (isset($s['combined_name']) && isset($s['parts'])) {
                    foreach ($s['parts'] as $part) {
                        $this->updateHighest($highestCollection, $part);
                    }
                    continue;
                }

                // Single subject
                if (isset($s['subject_id']) && is_numeric($s['subject_id']) && !$s['is_combined']) {
                    $this->updateHighest($highestCollection, $s);
                }
            }
        }

        return [
            'results' => $results,
            'highest_marks' => $highestCollection->values()->toArray(),
            'total_students' => count($results),
        ];
    }

    private function updateHighest(Collection $collection, array $subject)
    {
        $id = $subject['subject_id'] ?? null;
        if (!$id || !is_numeric($id)) return;

        $name = $subject['subject_name'] ?? 'Unknown';
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

            // === Combined Subject ===
            if ($first['is_combined']) {
                $combinedResult = $this->processCombinedGroup($group, $gradeRules);
                $merged[] = $combinedResult;

                if (!$combinedResult['is_uncountable'] ?? false) {
                    $totalGP += $combinedResult['combined_grade_point'];
                    $subjectCount++;
                }
                if ($combinedResult['combined_status'] === 'Fail') {
                    $failed = true;
                }
            }
            // === Single Subject ===
            else {
                $single = $this->processSingle($first, $gradeRules);
                $merged[] = $single;

                if (!$single['is_uncountable']) {
                    $totalGP += $single['grade_point'];
                    $subjectCount++;
                }
                if ($single['grade'] === 'F') {
                    $failed = true;
                }
            }
        }

        // === 4th Subject Bonus ===
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

    private function processCombinedGroup($group, $gradeRules)
    {
        $totalMark = 0;
        $graceTotal = 0;
        $failed = false;
        $combinedName = $group->first()['combined_subject_name'] ?? 'Unknown Combined';
        $parts = [];

        foreach ($group as $subj) {
            $mark = $subj['final_mark'] ?? 0;
            $grace = $subj['grace_mark'] ?? 0;
            $totalMark += $mark;
            $graceTotal += $grace;

            $gp = $this->markToGradePoint($mark, $gradeRules);
            $grade = $this->gpToGrade($mark, $gradeRules);

            $parts[] = [
                'subject_id'   => $subj['subject_id'],
                'subject_name' => $subj['subject_name'],
                'final_mark'   => (float) $mark,
                'grade_point'  => $gp,
                'grade'        => $grade,
                'grace_mark'   => (float) $grace,
                'is_uncountable' => ($subj['subject_type'] ?? '') === 'Uncountable',
                'is_combined'  => true,
            ];

            if ($grade === 'F') {
                $failed = true;
            }
        }

        $avgMark = $totalMark / count($group);
        $avgMarkRounded = round($avgMark, 2);

        $combinedGP = $failed ? 0 : $this->markToGradePoint($avgMark, $gradeRules);
        $combinedGrade = $failed ? 'F' : $this->gpToGrade($avgMark, $gradeRules);
        $status = $failed ? 'Fail' : 'Pass';

        return [
            'combined_name'         => $combinedName,
            'combined_final_mark'   => $avgMarkRounded,
            'combined_grade_point'  => $combinedGP,
            'combined_grade'        => $combinedGrade,
            'combined_grace_mark'   => (float) $graceTotal,
            'combined_status'       => $status,
            'is_uncountable'        => false,
            'parts'                 => $parts,
        ];
    }

    private function processSingle($subj, $gradeRules)
    {
        $mark = $subj['final_mark'] ?? 0;
        return [
            'subject_id'      => $subj['subject_id'],
            'subject_name'    => $subj['subject_name'],
            'final_mark'      => (float) $mark,
            'grade_point'     => $this->markToGradePoint($mark, $gradeRules),
            'grade'           => $this->gpToGrade($mark, $gradeRules),
            'grace_mark'      => (float) ($subj['grace_mark'] ?? 0),
            'is_uncountable'  => ($subj['subject_type'] ?? '') === 'Uncountable',
            'is_combined'     => false,
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
