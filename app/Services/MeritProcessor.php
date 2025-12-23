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
        $academicDetails = collect($payload['academic_details'] ?? []);
        $studentDetails  = collect($payload['student_details'] ?? []);
        $meritType       = $examConfig['merit_process_type'] ?? 'total_mark_sequential';
        $groupBy         = $this->getGroupByFields($examConfig);

        // প্রথমে সবাইকে একসাথে সর্ট করি (Pass আগে, Fail পরে)
        $allSorted = $this->sortStudents($results, $meritType, $academicDetails);

        // তারপর র‍্যাঙ্ক দিই (একবারই, পুরো ক্লাসের জন্য)
        $allRanked = $this->assignRanks($allSorted, $meritType, $academicDetails, $studentDetails);

        // এখন group-wise আলাদা করে দিই (শুধু দেখানোর জন্য)
        $grouped = collect($allRanked)->groupBy(function ($student) use ($groupBy, $academicDetails, $studentDetails) {
            $stdId = $student['student_id'];
            $acad  = $academicDetails[$stdId] ?? null;
            $std   = $studentDetails[$stdId] ?? null;
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
        foreach ($grouped as $groupStudents) {
            $finalMerit = array_merge($finalMerit, $groupStudents->toArray());
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
    //     $meritTypeLower = strtolower($meritType);
    //     $useGpa = str_contains($meritTypeLower, 'grade point') || str_contains($meritTypeLower, 'gpa');

    //     return $students->sort(function ($a, $b) use ($useGpa, $academicDetails) {
    //         $aId = $a['student_id'];
    //         $bId = $b['student_id'];

    //         // Pass first, Fail later
    //         if ($a['result_status'] !== $b['result_status']) {
    //             return ($a['result_status'] === 'Pass') ? -1 : 1;
    //         }

    //         if ($useGpa) {
    //             // GPA ranking primary
    //             $aVal = (float) ($a['gpa_with_optional'] ?? $a['gpa'] ?? 0);
    //             $bVal = (float) ($b['gpa_with_optional'] ?? $b['gpa'] ?? 0);

    //             if ($aVal !== $bVal) {
    //                 return $bVal <=> $aVal; // higher GPA first
    //             }

    //             // Tie → Total Mark
    //             $aTM = $this->getTotalMark($a);
    //             $bTM = $this->getTotalMark($b);
    //             if ($aTM !== $bTM) {
    //                 return $bTM <=> $aTM;
    //             }
    //         } else {
    //             // Total mark ranking primary
    //             $aTM = $this->getTotalMark($a);
    //             $bTM = $this->getTotalMark($b);
    //             if ($aTM !== $bTM) {
    //                 return $bTM <=> $aTM; // higher total mark first
    //             }

    //             // Tie → GPA
    //             $aVal = (float) ($a['gpa_with_optional'] ?? $a['gpa'] ?? 0);
    //             $bVal = (float) ($b['gpa_with_optional'] ?? $b['gpa'] ?? 0);
    //             if ($aVal !== $bVal) {
    //                 return $bVal <=> $aVal;
    //             }
    //         }

    //         // Last tie-breaker → roll
    //         $aRoll = $academicDetails[$aId]['class_roll'] ?? 0;
    //         $bRoll = $academicDetails[$bId]['class_roll'] ?? 0;

    //         return $aRoll <=> $bRoll;
    //     })->values();
    // }


    private function getTotalMark($student): float
    {
        $bonus = $student['optional_bonus'] ?? 0;
        return $bonus + collect($student['subjects'] ?? [])
            ->sum(fn($s) => $s['combined_final_mark'] ?? $s['final_mark'] ?? 0);
    }

    // private function assignRanks(
    //     Collection $sorted,
    //     string $meritType,
    //     Collection $academicDetails,
    //     Collection $studentDetails
    // ): array {

    //     $isSequential = str_contains(strtolower($meritType), 'sequential');
    //     $useGpa = str_contains(strtolower($meritType), 'grade point') || str_contains(strtolower($meritType), 'gpa');

    //     $ranked = [];
    //     $rank = 1;

    //     foreach ($sorted as $index => $student) {
    //         $stdId = $student['student_id'];
    //         $acad  = $academicDetails[$stdId] ?? [];
    //         $std   = $studentDetails[$stdId] ?? [];

    //         $totalMark = $this->getTotalMark($student);
    //         $gpa       = (float) ($student['gpa_with_optional'] ?? $student['gpa'] ?? 0);
    //         $roll      = $acad['class_roll'] ?? 0;

    //         if ($isSequential) {
    //             // Sequential: tie-breaker logic
    //             if ($useGpa) {
    //                 // Grade Point Sequential → primary = GPA, secondary = Total Mark
    //                 $primary   = $gpa;
    //                 $secondary = $totalMark;
    //             } else {
    //                 // Total Mark Sequential → primary = Total Mark, secondary = GPA
    //                 $primary   = $totalMark;
    //                 $secondary = $gpa;
    //             }

    //             if ($index === 0) {
    //                 $currentRank = $rank;
    //             } else {
    //                 $prevStudent = $ranked[$index - 1];
    //                 $prevPrimary   = $prevStudent['merit_primary'];
    //                 $prevSecondary = $prevStudent['merit_secondary'];
    //                 $prevRoll      = $prevStudent['roll'];

    //                 // Check primary tie
    //                 if ($primary < $prevPrimary) {
    //                     $rank++;
    //                     $currentRank = $rank;
    //                 } elseif ($primary == $prevPrimary) {
    //                     // Check secondary tie
    //                     if ($secondary < $prevSecondary) {
    //                         $rank++;
    //                         $currentRank = $rank;
    //                     } elseif ($secondary == $prevSecondary) {
    //                         // Finally, roll number
    //                         if ($roll > $prevRoll) {
    //                             $rank++;
    //                             $currentRank = $rank;
    //                         } else {
    //                             // smaller roll comes first → same rank as previous? NO, must increment
    //                             $rank++;
    //                             $currentRank = $rank;
    //                         }
    //                     } else {
    //                         $currentRank = $rank;
    //                     }
    //                 } else {
    //                     $currentRank = $rank;
    //                 }
    //             }
    //         } else {
    //             // Non-sequential: duplicate allowed
    //             if ($index === 0) {
    //                 $currentRank = $rank;
    //             } else {
    //                 $prevStudent = $ranked[$index - 1];
    //                 $prevPrimary = $useGpa
    //                     ? ($prevStudent['gpa'] ?? 0)
    //                     : ($prevStudent['total_mark'] ?? 0);
    //                 $primary = $useGpa ? $gpa : $totalMark;

    //                 $currentRank = ($primary == $prevPrimary)
    //                     ? $prevStudent['merit_position']
    //                     : ++$rank;
    //             }
    //         }

    //         $ranked[] = [
    //             'student_id'           => $stdId,
    //             'student_name'         => $student['student_name'],
    //             'roll'                 => $roll,
    //             'total_mark'           => $totalMark,
    //             'gpa'                  => round($gpa, 2),
    //             'gpa_without_optional' => round($student['gpa_without_optional'] ?? 0, 2),
    //             'letter_grade'         => $student['letter_grade_with_optional'] ?? $student['letter_grade'] ?? 'F',
    //             'result_status'        => $student['result_status'],
    //             'merit_position'       => $currentRank,
    //             // helper fields for sequential tie-breaking
    //             'merit_primary'        => $primary,
    //             'merit_secondary'      => $secondary ?? 0,
    //             'shift'                => $acad['shift'] ?? null,
    //             'section'              => $acad['section'] ?? null,
    //             'group'                => $acad['group'] ?? null,
    //             'gender'               => $std['student_gender'] ?? null,
    //             'religion'             => $std['student_religion'] ?? null,
    //         ];
    //     }

    //     return $ranked;
    // }

    private function sortStudents(Collection $students, string $meritType, Collection $academicDetails): Collection
    {
        $meritTypeLower = strtolower($meritType);
        $isGradePoint = str_contains($meritTypeLower, 'grade point') || str_contains($meritTypeLower, 'gpa');

        return $students->sort(function ($a, $b) use ($isGradePoint, $academicDetails) {
            $aId = $a['student_id'];
            $bId = $b['student_id'];

            // 1. Pass first, Fail later
            if ($a['result_status'] !== $b['result_status']) {
                return ($a['result_status'] === 'Pass') ? -1 : 1;
            }

            // 2. GPA (with optional) descending
            $aGpa = (float) ($a['gpa_with_optional'] ?? $a['gpa'] ?? 0);
            $bGpa = (float) ($b['gpa_with_optional'] ?? $b['gpa'] ?? 0);

            if ($aGpa !== $bGpa) {
                return $bGpa <=> $aGpa; // higher GPA first
            }

            // 3. If GPA same → lower class roll first (ONLY if Grade Point mode)
            // In Total Mark mode, we might still want other tie-breakers, but not here
            if ($isGradePoint) {
                $aRoll = $academicDetails[$aId]['class_roll'] ?? PHP_INT_MAX;
                $bRoll = $academicDetails[$bId]['class_roll'] ?? PHP_INT_MAX;
                return $aRoll <=> $bRoll; // lower roll = better position
            }

            // Fallback for non-Grade-Point modes (if needed in future)
            // e.g., total mark tie-breaker
            $aTM = $this->getTotalMark($a);
            $bTM = $this->getTotalMark($b);
            if ($aTM !== $bTM) {
                return $bTM <=> $aTM;
            }

            // Final fallback: roll
            $aRoll = $academicDetails[$aId]['class_roll'] ?? PHP_INT_MAX;
            $bRoll = $academicDetails[$bId]['class_roll'] ?? PHP_INT_MAX;
            return $aRoll <=> $bRoll;
        })->values();
    }
    private function assignRanks(
        Collection $sorted,
        string $meritType,
        Collection $academicDetails,
        Collection $studentDetails
    ): array {
        $meritTypeLower = strtolower($meritType);
        $isSequential = str_contains($meritTypeLower, 'sequential');
        $isGradePoint = str_contains($meritTypeLower, 'grade point') || str_contains($meritTypeLower, 'gpa');

        $ranked = [];
        $rank = 1;

        foreach ($sorted as $student) {
            $stdId = $student['student_id'];
            $acad  = $academicDetails[$stdId] ?? [];
            $std   = $studentDetails[$stdId] ?? [];
            $totalMark = $this->getTotalMark($student);
            $gpa       = (float) ($student['gpa_with_optional'] ?? $student['gpa'] ?? 0);
            $roll      = $acad['class_roll'] ?? PHP_INT_MAX;

            // For sequential: always next rank
            $currentRank = $isSequential ? $rank++ : $rank; // will increment later if non-seq

            // Only increment rank counter for non-sequential when primary differs
            if (!$isSequential && count($ranked) > 0) {
                $prev = $ranked[count($ranked) - 1];
                $prevPrimary = $isGradePoint ? $prev['gpa'] : $prev['total_mark'];
                $currentPrimary = $isGradePoint ? $gpa : $totalMark;

                if ($currentPrimary != $prevPrimary) {
                    $rank = $prev['merit_position'] + 1;
                }
                $currentRank = $rank;
            }

            $ranked[] = [
                'student_id'            => $stdId,
                'student_name'          => $student['student_name'],
                'roll'                  => $roll,
                'total_mark'            => $totalMark,
                'gpa'                   => round($gpa, 2),
                'gpa_without_optional'  => round($student['gpa_without_optional'] ?? 0, 2),
                'letter_grade'          => $student['letter_grade_with_optional'] ?? $student['letter_grade'] ?? 'F',
                'result_status'         => $student['result_status'],
                'merit_position'        => $currentRank,
                // Remove merit_primary/secondary if not needed, or keep for debugging
                'shift'                 => $acad['shift'] ?? null,
                'section'               => $acad['section'] ?? null,
                'group'                 => $acad['group'] ?? null,
                'gender'                => $std['student_gender'] ?? null,
                'religion'              => $std['student_religion'] ?? null,
            ];
        }

        // For non-sequential, rank is already handled above
        // For sequential, we already did $rank++

        return $ranked;
    }
}
