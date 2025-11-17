<?php

namespace App\Services;

use Illuminate\Support\Collection;

class MeritProcessor
{
    public function process($payload)
    {
        $rawResults = $payload['results'] ?? [];

        $results = collect();
        if (isset($rawResults['results']) && is_array($rawResults['results'])) {
            $results = collect($rawResults['results']);
        } elseif (is_array($rawResults)) {
            $results = collect($rawResults);
        }

        if ($results->isEmpty()) {
            return ['status' => 'error', 'message' => 'No results found'];
        }

        $examConfig       = $payload['exam_config'] ?? null;
        $academicDetails  = collect($payload['academic_details'] ?? []);
        $studentDetails   = collect($payload['student_details'] ?? []);
        $classDetailsMap  = collect($payload['class_details_map'] ?? []);

        if (!$examConfig) {
            return ['status' => 'error', 'message' => 'Exam config missing'];
        }

        $meritType = $examConfig['merit_process_type'] ?? 'total_mark_sequential';
        $groupBy   = $this->getGroupByFields($examConfig);

        // Group students by selected criteria
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
            $ranked = $this->assignRanks($sorted, $meritType, $academicDetails, $classDetailsMap, $studentDetails);
            $finalMerit = array_merge($finalMerit, $ranked);
        }

        $all = collect($finalMerit);

        return [
            'status' => 'success',
            'message' => 'Merit Calculated Successfully',
            'results' => [
                'status' => 'success',
                'data' => [
                    'all_students'      => $finalMerit,
                    'total_students'    => $results->count(),
                    'merit_type'        => $meritType,
                    'grouped_by'        => $groupBy,

                    // Separate Lists
                    'section_wise'   => $all->groupBy('section')->map->values()->toArray(),
                    'shift_wise'     => $all->groupBy('shift')->map->values()->toArray(),
                    'group_wise'     => $all->groupBy('group')->map->values()->toArray(),
                    'gender_wise'    => $all->groupBy('gender')->map->values()->toArray(),
                    'religion_wise'  => $all->groupBy('religion')->map->values()->toArray(),
                ]
            ]
        ];
    }

    private function getGroupByFields($examConfig)
    {
        $fields = [];
        if ($examConfig['group_by_shift'] ?? false)    $fields[] = 'shift';
        if ($examConfig['group_by_section'] ?? false)  $fields[] = 'section';
        if ($examConfig['group_by_group'] ?? false)    $fields[] = 'group';
        if ($examConfig['group_by_gender'] ?? false)   $fields[] = 'gender';
        if ($examConfig['group_by_religion'] ?? false) $fields[] = 'religion';
        return !empty($fields) ? $fields : ['section'];
    }

    private function getGroupValue($field, $academic, $classDetailsMap, $studentDetails)
    {
        $pivotId = $academic['combinations_pivot_id'] ?? null;
        $classDetail = $classDetailsMap->get($pivotId);
        $stdDetail = $studentDetails->firstWhere('student_id', $academic['student_id']);

        return match ($field) {
            'shift'    => $classDetail['shift_name'] ?? null,
            'section'  => $classDetail['section_name'] ?? null,
            'group'    => $classDetail['group_name'] ?? null,
            'gender'   => $stdDetail['student_gender'] ?? null,
            'religion' => $stdDetail['student_religion'] ?? null,
            default    => null,
        };
    }

    private function sortStudents($students, $meritType, $academicDetails)
    {
        $isGpaBased = str_contains($meritType, 'grade_point') || str_contains($meritType, 'GPA');

        return $students->sort(function ($a, $b) use ($isGpaBased, $academicDetails) {
            $aId = $a['student_id'];
            $bId = $b['student_id'];

            $aRoll = $academicDetails->firstWhere('student_id', $aId)['class_roll'] ?? 999999;
            $bRoll = $academicDetails->firstWhere('student_id', $bId)['class_roll'] ?? 999999;

            $aValue = $isGpaBased ? ($a['gpa'] ?? 0) : $this->getTotalMark($a);
            $bValue = $isGpaBased ? ($b['gpa'] ?? 0) : $this->getTotalMark($b);

            if ($aValue !== $bValue) {
                return $bValue <=> $aValue; // Higher first
            }

            return $aRoll <=> $bRoll; // Lower roll first
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

    private function assignRanks($sorted, $meritType, $academicDetails, $classDetailsMap, $studentDetails)
    {
        $isSequential = str_contains($meritType, 'sequential') || str_contains($meritType, 'Sequential');
        $isGpaBased   = str_contains($meritType, 'grade_point') || str_contains($meritType, 'GPA');

        $position = 1;
        $nextSequentialRank = 1;

        return $sorted->map(function ($student, $index) use (
            &$position, &$nextSequentialRank, $isSequential, $isGpaBased,
            $academicDetails, $classDetailsMap, $studentDetails, $sorted
        ) {
            $studentId = $student['student_id'];
            $academic  = $academicDetails->firstWhere('student_id', $studentId);
            $pivotId   = $academic['combinations_pivot_id'] ?? null;
            $classDetail = $classDetailsMap->get($pivotId);
            $stdDetail = $studentDetails->firstWhere('student_id', $studentId);

            if ($index === 0) {
                $meritPosition = 1;
            } else {
                $prev = $sorted[$index - 1];
                $currKey = $isGpaBased ? ($student['gpa'] ?? 0) : $this->getTotalMark($student);
                $prevKey = $isGpaBased ? ($prev['gpa'] ?? 0) : $this->getTotalMark($prev);

                $currRoll = $student['roll'] ?? 999999;
                $prevRoll = $prev['roll'] ?? 999999;

                $sameValue = ($currKey == $prevKey);
                $betterByRoll = ($sameValue && $currRoll < $prevRoll);

                if ($isSequential) {
                    // Sequential: No duplicate, always unique 1,2,3,...
                    $meritPosition = $betterByRoll ? $nextSequentialRank++ : $nextSequentialRank++;
                } else {
                    // Non-Sequential: Same value = Same rank
                    $meritPosition = ($sameValue && !$betterByRoll) ? $prev['merit_position'] : $position++;
                }
            }

            if ($index === 0 || !$isSequential) {
                $position++;
            }

            return [
                'student_id'           => $student['student_id'],
                'student_name'         => $student['student_name'] ?? 'Unknown',
                'roll'                 => $student['roll'] ?? $academic['class_roll'] ?? null,
                'total_mark'           => $this->getTotalMark($student),
                'gpa'                  => round($student['gpa'] ?? 0, 2),
                'gpa_without_optional' => round($student['gpa_without_optional'] ?? 0, 2),
                'letter_grade'         => $student['letter_grade'] ?? 'F',
                'result_status'        => $student['result_status'],
                'merit_position'       => $meritPosition,

                'shift'    => $classDetail['shift_name'] ?? null,
                'section'  => $classDetail['section_name'] ?? null,
                'group'    => $classDetail['group_name'] ?? null,
                'gender'   => $stdDetail['student_gender'] ?? null,
                'religion' => $stdDetail['student_religion'] ?? null,
            ];
        })->values()->toArray();
    }
}
