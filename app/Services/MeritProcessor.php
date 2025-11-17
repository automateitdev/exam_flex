<?php

namespace App\Services;

use Illuminate\Support\Collection;

class MeritProcessor
{
    public function process($payload)
    {
        $results = collect($payload['results']['results'] ?? []);
        $examConfig = $payload['exam_config'] ?? null;
        $academicDetails = collect($payload['academic_details'] ?? []);
        $studentDetails = collect($payload['student_details'] ?? []);
        $classDetailsMap = collect($payload['class_details_map'] ?? []);

        if ($results->isEmpty() || !$examConfig) {
            return ['status' => 'error', 'message' => 'Invalid payload'];
        }

        $meritType = $examConfig['merit_process_type'] ?? 'total_mark_sequential';
        $groupBy = $this->getGroupByFields($examConfig);

        // Build grouping key for each student
        $grouped = $results->groupBy(function ($student) use ($groupBy, $academicDetails, $classDetailsMap, $studentDetails) {
            $studentId = $student['student_id'];
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
            $withRank = $this->assignRanks($sorted, $meritType);
            $finalMerit = array_merge($finalMerit, $withRank);
        }

        return [
            'status' => 'success',
            'data' => [
                'merit_list' => $finalMerit,
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
        return !empty($fields) ? $fields : ['section']; // default
    }

    private function getGroupValue($field, $academic, $classDetailsMap, $studentDetails)
    {
        $pivotId = $academic['combinations_pivot_id'] ?? null;
        $classDetail = $classDetailsMap->get($pivotId);

        switch ($field) {
            case 'shift':
                return $classDetail['shift_name'] ?? null;
            case 'section':
                return $classDetail['section_name'] ?? null;
            case 'group':
                return $classDetail['group_name'] ?? null;
            case 'gender':
                $studentDetail = $studentDetails->firstWhere('student_id', $academic['student_id']);
                return $studentDetail['student_gender'] ?? null;
            case 'religion':
                $studentDetail = $studentDetails->firstWhere('student_id', $academic['student_id']);
                return $studentDetail['student_religion'] ?? null;
            default:
                return null;
        }
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

            // Primary sort
            if (str_contains($meritType, 'total_mark')) {
                if ($aTotal !== $bTotal) return $bTotal <=> $aTotal;
            } else {
                if ($aGpa !== $bGpa) return $bGpa <=> $aGpa;
            }

            // Sequential tie-breaker
            if (str_contains($meritType, 'sequential')) {
                if (str_contains($meritType, 'total_mark')) {
                    if ($aGpa !== $bGpa) return $bGpa <=> $aGpa;
                } else {
                    if ($aTotal !== $bTotal) return $bTotal <=> $aTotal;
                }
            }

            // Final tie: class_roll (smaller first)
            return $aRoll <=> $bRoll;
        })->values();
    }

    private function getTotalMark($student)
    {
        $bonus = $student['optional_bonus'] ?? 0;
        $subjectsTotal = collect($student['subjects'] ?? [])->sum(function ($s) {
            return $s['combined_final_mark'] ?? $s['final_mark'] ?? 0;
        });
        return $subjectsTotal + $bonus;
    }

    private function assignRanks($sorted, $meritType)
    {
        $rank = 1;
        $position = 1;
        $prevValue = null;
        $sameCount = 0;

        return $sorted->map(function ($student, $index) use (&$rank, &$position, &$prevValue, &$sameCount, $meritType) {
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

            $student['merit_position'] = $position;

            if (!str_contains($meritType, 'non_sequential') || $currentValue !== $prevValue) {
                $rank += $sameCount;
            }

            $prevValue = $currentValue;
            return $student;
        })->values()->toArray();
    }
}
