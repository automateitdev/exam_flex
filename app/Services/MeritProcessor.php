<?php

namespace App\Services;

use Illuminate\Support\Collection;

class MeritProcessor
{
    public function process($payload)
    {
        $rawResults = $payload['results'] ?? [];

        if (isset($rawResults['results']) && is_array($rawResults['results'])) {
            $results = collect($rawResults['results']);
        } elseif (is_array($rawResults)) {
            $results = collect($rawResults);
        } else {
            return ['status' => 'error', 'message' => 'No results found'];
        }

        $examConfig = $payload['exam_config'] ?? null;
        $academicDetails = collect($payload['academic_details'] ?? []);
        $studentDetails = collect($payload['student_details'] ?? []);
        $classDetailsMap = collect($payload['class_details_map'] ?? []);

        if ($results->isEmpty() || !$examConfig) {
            return ['status' => 'error', 'message' => 'Invalid payload'];
        }

        $meritType = $examConfig['merit_process_type'] ?? 'total_mark_sequential';
        $groupBy = $this->getGroupByFields($examConfig);

        $grouped = $results->groupBy(function ($student) use ($groupBy, $academicDetails, $classDetailsMap, $studentDetails) {
            $studentId = $student['student_id'] ?? null;
            if (!$studentId) return 'unknown';

            $academic = $academicDetails->firstWhere('student_id', $studentId);
            if (!$academic) return 'unknown';

            $keys = [];
            foreach ($groupBy as $field) {
                $value = $this->getGroupValue($field, $academic, $classDetailsMap, $studentDetails);
                $keys[] = $value ?? 'unknown';
            }
            return implode('|', $keys);
        });

        $finalMerit = [];

        foreach ($grouped as $groupKey => $students) {
            $sorted = $this->sortStudents($students, $meritType, $academicDetails);
            $withRank = $this->assignRanks($sorted, $meritType, $groupBy, $academicDetails, $classDetailsMap, $studentDetails);
            $finalMerit = array_merge($finalMerit, $withRank);
        }

        return [
            'status' => 'success',
            'data' => [
                'merit_list' => array_values($finalMerit),
                'total_students' => $results->count(),
                'merit_type' => $meritType,
                'grouped_by' => $groupBy,
            ]
        ];
    }

    private function getGroupByFields($examConfig)
    {
        $fields = [];
        if ($examConfig['group_by_shift'] ?? false) $fields[] = 'shift';
        if ($examConfig['group_by_section'] ?? false) $fields[] = 'section';
        if ($examConfig['group_by_group'] ?? false) $fields[] = 'group';
        if ($examConfig['group_by_gender'] ?? false) $fields[] = 'gender';
        if ($examConfig['group_by_religion'] ?? false) $fields[] = 'religion';
        return !empty($fields) ? $fields : ['section'];
    }

    private function getGroupValue($field, $academic, $classDetailsMap, $studentDetails)
    {
        $pivotId = $academic['combinations_pivot_id'] ?? null;
        $classDetail = $classDetailsMap->get($pivotId);

        return match ($field) {
            'shift'    => $classDetail['shift_name'] ?? null,
            'section'  => $classDetail['section_name'] ?? null,
            'group'    => $classDetail['group_name'] ?? null,
            'gender'   => $studentDetails->firstWhere('student_id', $academic['student_id'])['student_gender'] ?? null,
            'religion' => $studentDetails->firstWhere('student_id', $academic['student_id'])['student_religion'] ?? null,
            default    => null,
        };
    }

    private function sortStudents($students, $meritType, $academicDetails)
    {
        return $students->sort(function ($a, $b) use ($meritType, $academicDetails) {
            $aId = $a['student_id'];
            $bId = $b['student_id'];
            $aRoll = $academicDetails->firstWhere('student_id', $aId)['class_roll'] ?? 999999;
            $bRoll = $academicDetails->firstWhere('student_id', $bId)['class_roll'] ?? 999999;

            $aTotal = $this->getTotalMark($a);
            $bTotal = $this->getTotalMark($b);
            $aGpa = $a['gpa'] ?? 0;
            $bGpa = $b['gpa'] ?? 0;

            if (str_contains($meritType, 'total_mark')) {
                if ($aTotal !== $bTotal) return $bTotal <=> $aTotal;
            } else {
                if ($aGpa !== $bGpa) return $bGpa <=> $aGpa;
            }

            if (str_contains($meritType, 'sequential')) {
                if (str_contains($meritType, 'total_mark')) {
                    if ($aGpa !== $bGpa) return $bGpa <=> $aGpa;
                } else {
                    if ($aTotal !== $bTotal) return $bTotal <=> $aTotal;
                }
            }

            return $aRoll <=> $bRoll;
        })->values();
    }

    private function getTotalMark($student)
    {
        $bonus = $student['optional_bonus'] ?? 0;
        $subjectsTotal = collect($student['subjects'] ?? [])->sum(fn($s) =>
            $s['combined_final_mark'] ?? $s['final_mark'] ?? 0
        );
        return $subjectsTotal + $bonus;
    }

    private function assignRanks($sorted, $meritType, $groupBy, $academicDetails, $classDetailsMap, $studentDetails)
    {
        $rank = 1;
        $position = 1;
        $prevValue = null;
        $sameCount = 0;

        return $sorted->map(function ($student, $index) use (&$rank, &$position, &$prevValue, &$sameCount, $meritType, $groupBy, $academicDetails, $classDetailsMap, $studentDetails) {
            $studentId = $student['student_id'];
            $academic = $academicDetails->firstWhere('student_id', $studentId);
            $pivotId = $academic['combinations_pivot_id'] ?? null;
            $classDetail = $classDetailsMap->get($pivotId);
            $stdDetail = $studentDetails->firstWhere('student_id', $studentId);

            $currentValue = str_contains($meritType, 'total_mark')
                ? $this->getTotalMark($student)
                : ($student['gpa'] ?? 0);

            if ($index === 0) {
                $prevValue = $currentValue;
                $position = 1;
                $sameCount = 1;
            } elseif ($currentValue === $prevValue) {
                $sameCount++;
            } else {
                $position = $rank;
                $sameCount = 1;
            }

            // Clean student – NO SUBJECTS, NO BIG DATA
            $cleanStudent = [
                'student_id' => $student['student_id'],
                'student_name' => $student['student_name'] ?? 'Unknown',
                'roll' => $student['roll'] ?? $academic['class_roll'] ?? null,
                'gpa' => round($student['gpa'] ?? 0, 2),
                'gpa_without_optional' => round($student['gpa_without_optional'] ?? 0, 2),
                'letter_grade' => $student['letter_grade'] ?? 'F',
                'result_status' => $student['result_status'],
                'total_mark' => $this->getTotalMark($student),
                'merit_position' => $position,

                // Grouping info
                'shift' => $classDetail['shift_name'] ?? null,
                'section' => $classDetail['section_name'] ?? null,
                'group' => $classDetail['group_name'] ?? null,
                'gender' => $stdDetail['student_gender'] ?? null,
                'religion' => $stdDetail['student_religion'] ?? null,
            ];

            if (!str_contains($meritType, 'non_sequential') || $currentValue !== $prevValue) {
                $rank += $sameCount;
            }

            $prevValue = $currentValue;
            return $cleanStudent;
        })->values()->toArray();
    }
}
