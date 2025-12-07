<?php

namespace App\Services;

use Illuminate\Support\Collection;

class MeritProcessor
{
    public function process(array $payload): array
    {
        $results = $this->normalizeResults($payload['results'] ?? []);
        if ($results->isEmpty()) {
            return ['status' => 'error', 'message' => 'No results found'];
        }

        $examConfig      = $payload['exam_config'] ?? [];
        $academicDetails = collect($payload['academic_details'] ?? []);   // student_id => [shift, section, group, class_roll]
        $studentDetails  = collect($payload['student_details'] ?? []);    // student_id => [gender, religion]

        $meritType = $examConfig['merit_process_type'] ?? 'total_mark_sequential';
        $groupBy   = $this->getGroupByFields($examConfig);

        // Group by selected fields (shift|section|group etc.)
        $grouped = $results->groupBy(function ($student) use ($groupBy, $academicDetails, $studentDetails) {
            $stdId   = $student['student_id'] ?? null;
            $acad    = $academicDetails[$stdId] ?? null;
            $std     = $studentDetails[$stdId] ?? null;

            if (!$acad) return 'unknown';

            $keys = [];
            foreach ($groupBy as $field) {
                $value = match ($field) {
                    'shift'    => $acad['shift'] ?? null,
                    'section'  => $acad['section'] ?? null,
                    'group'    => $acad['group'] ?? null,
                    'gender'   => $std['student_gender'] ?? null,
                    'religion' => $std['student_religion'] ?? null,
                    default    => null,
                };
                $keys[] = $value ?? 'unknown';
            }
            return implode('|', $keys);
        });

        $finalMerit = [];
        foreach ($grouped as $students) {
            $sorted = $this->sortStudents($students, $meritType, $academicDetails);
            $ranked = $this->assignRanks($sorted, $meritType, $academicDetails, $studentDetails);
            $finalMerit = array_merge($finalMerit, $ranked);
        }

        $all = collect($finalMerit);

        return [
            'total_students' => $results->count(),
            'merit_type'     => $meritType,
            'grouped_by'     => $groupBy,
            'data'           => [
                'all_students'   => $finalMerit,
                'section_wise'   => $all->groupBy('section')->map->values()->toArray(),
                'shift_wise'     => $all->groupBy('shift')->map->values()->toArray(),
                'group_wise'     => $all->groupBy('group')->map->values()->toArray(),
                'gender_wise'    => $all->groupBy('gender')->map->values()->toArray(),
                'religion_wise'  => $all->groupBy('religion')->map->values()->toArray(),
            ]
        ];
    }

    private function normalizeResults($raw): Collection
    {
        if (isset($raw['results']) && is_array($raw['results'])) {
            return collect($raw['results']);
        }
        return collect(is_array($raw) ? $raw : []);
    }

    private function getGroupByFields(array $config): array
    {
        $fields = [];
        if ($config['group_by_shift'] ?? false)    $fields[] = 'shift';
        if ($config['group_by_section'] ?? false)  $fields[] = 'section';
        if ($config['group_by_group'] ?? false)    $fields[] = 'group';
        if ($config['group_by_gender'] ?? false)   $fields[] = 'gender';
        if ($config['group_by_religion'] ?? false) $fields[] = 'religion';

        return empty($fields) ? [] : $fields; // ← [] মানে পুরো ক্লাস একসাথে
    }

    // private function sortStudents(Collection $students, string $meritType, Collection $academicDetails): Collection
    // {
    //     $isGpa = str_contains(strtolower($meritType), 'gpa') || str_contains($meritType, 'grade_point');

    //     return $students->sort(function ($a, $b) use ($isGpa, $academicDetails) {
    //         $aId = $a['student_id'];
    //         $bId = $b['student_id'];

    //         $aRoll = $academicDetails[$aId]['class_roll'] ?? 999999;
    //         $bRoll = $academicDetails[$bId]['class_roll'] ?? 999999;

    //         $aVal = $isGpa ? ($a['gpa'] ?? 0) : $this->getTotalMark($a);
    //         $bVal = $isGpa ? ($b['gpa'] ?? 0) : $this->getTotalMark($b);

    //         if ($aVal !== $bVal) return $bVal <=> $aVal;
    //         return $aRoll <=> $bRoll;
    //     })->values();
    // }
    private function sortStudents(Collection $students, string $meritType, Collection $academicDetails): Collection
    {
        $isGpaBased = str_contains(strtolower($meritType), 'gpa') || str_contains($meritType, 'grade_point');

        return $students->sort(function ($a, $b) use ($isGpaBased, $academicDetails) {
            $aId = $a['student_id'];
            $bId = $b['student_id'];

            // ১. প্রথমে Result Status দিয়ে সর্ট — Pass আগে, Fail পরে
            $aStatus = $a['result_status'] ?? 'Fail';
            $bStatus = $b['result_status'] ?? 'Fail';

            if ($aStatus !== $bStatus) {
                return ($aStatus === 'Pass') ? -1 : 1; // Pass আগে
            }

            // ২. Pass হলে GPA/মার্ক দিয়ে সর্ট
            if ($aStatus === 'Pass') {
                $aVal = $isGpaBased
                    ? ($a['gpa_with_optional'] ?? $a['gpa'] ?? 0)
                    : $this->getTotalMark($a);
                $bVal = $isGpaBased
                    ? ($b['gpa_with_optional'] ?? $b['gpa'] ?? 0)
                    : $this->getTotalMark($b);

                if ($aVal !== $bVal) {
                    return $bVal <=> $aVal; // বেশি থেকে কম
                }
            }

            // ৩. Fail হলে টোটাল মার্ক দিয়ে সর্ট (বেশি মার্ক আগে)
            $aMark = $this->getTotalMark($a);
            $bMark = $this->getTotalMark($b);
            if ($aMark !== $bMark) {
                return $bMark <=> $aMark;
            }

            // ৪. শেষে রোল নম্বর দিয়ে
            $aRoll = $academicDetails[$aId]['class_roll'];
            $bRoll = $academicDetails[$bId]['class_roll'];

            return $aRoll <=> $bRoll;
        })->values();
    }

    private function getTotalMark($student): float
    {
        $bonus = $student['optional_bonus'] ?? 0;
        return $bonus + collect($student['subjects'] ?? [])
            ->sum(fn($s) => $s['combined_final_mark'] ?? $s['final_mark'] ?? 0);
    }

    // private function assignRanks(Collection $sorted, string $meritType, Collection $academicDetails, Collection $studentDetails): array
    // {
    //     $isSequential = str_contains(strtolower($meritType), 'sequential');
    //     $isGpaBased   = str_contains(strtolower($meritType), 'gpa') || str_contains($meritType, 'grade_point');

    //     $rank = 1;

    //     return $sorted->map(function ($student, $index) use (&$rank, $isSequential, $isGpaBased, $sorted, $academicDetails, $studentDetails) {
    //         $stdId   = $student['student_id'];
    //         $acad    = $academicDetails[$stdId] ?? [];
    //         $std     = $studentDetails[$stdId] ?? [];

    //         if ($index === 0) {
    //             $currentRank = 1;
    //         } else {
    //             $prev = $sorted[$index - 1];
    //             $currVal = $isGpaBased ? ($student['gpa'] ?? 0) : $this->getTotalMark($student);
    //             $prevVal = $isGpaBased ? ($prev['gpa'] ?? 0) : $this->getTotalMark($prev);

    //             $currentRank = ($isSequential || $currVal != $prevVal) ? $rank++ : $prev['merit_position'];
    //         }

    //         return [
    //             'student_id'           => $stdId,
    //             'student_name'         => $student['student_name'] ?? 'Unknown',
    //             'roll'                 => $student['roll'] ?? $acad['class_roll'] ?? null,
    //             'total_mark'           => $this->getTotalMark($student),
    //             'gpa'                  => round($student['gpa'] ?? 0, 2),
    //             'gpa_without_optional' => round($student['gpa_without_optional'] ?? 0, 2),
    //             'letter_grade'         => $student['letter_grade'] ?? 'F',
    //             'result_status'        => $student['result_status'] ?? 'Unknown',
    //             'merit_position'       => $currentRank,
    //             'shift'                => $acad['shift'] ?? null,
    //             'section'              => $acad['section'] ?? null,
    //             'group'                => $acad['group'] ?? null,
    //             'gender'               => $std['student_gender'] ?? null,
    //             'religion'             => $std['student_religion'] ?? null,
    //         ];
    //     })->values()->toArray();
    // }
    private function assignRanks(Collection $sorted, string $meritType, Collection $academicDetails, Collection $studentDetails): array
    {
        $isSequential = str_contains(strtolower($meritType), 'sequential');
        $rank = 1;
        $previousValue = null;

        return $sorted->map(function ($student, $index) use (&$rank, &$previousValue, $isSequential, $academicDetails, $studentDetails) {
            $stdId = $student['student_id'];
            $acad  = $academicDetails[$stdId] ?? [];
            $std   = $studentDetails[$stdId] ?? [];

            // Pass এবং Fail আলাদা র‍্যাঙ্কিং
            $currentValue = ($student['result_status'] ?? 'Fail') === 'Pass'
                ? ($student['gpa_with_optional'] ?? $student['gpa'] ?? 0)
                : ($this->getTotalMark($student) * -1); // Fail এর জন্য negative (যাতে আলাদা থাকে)

            if ($index === 0 || $currentValue != $previousValue) {
                $currentRank = $rank++;
            } else {
                $currentRank = $isSequential ? $rank++ : $previousRank ?? $rank - 1;
            }

            $previousValue = $currentValue;

            return [
                'student_id'            => $stdId,
                'student_name'          => $student['student_name'] ?? 'Unknown',
                'roll'                  => $student['roll'] ?? $acad['class_roll'] ?? null,
                'total_mark'            => $this->getTotalMark($student),
                'gpa'                   => round($student['gpa_with_optional'] ?? $student['gpa'] ?? 0, 2),
                'gpa_without_optional'  => round($student['gpa_without_optional'] ?? 0, 2),
                'letter_grade'          => $student['letter_grade_with_optional'] ?? $student['letter_grade'] ?? 'F',
                'result_status'         => $student['result_status'] ?? 'Unknown',
                'merit_position'        => $currentRank,
                'shift'                 => $acad['shift'] ?? null,
                'section'               => $acad['section'] ?? null,
                'group'                 => $acad['group'] ?? null,
                'gender'                => $std['student_gender'] ?? null,
                'religion'              => $std['student_religion'] ?? null,
            ];
        })->values()->toArray();
    }
}
