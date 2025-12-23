<?php

namespace App\Services;

use Illuminate\Support\Collection;

class MeritProcessor
{
    // public function process(array $payload): array
    // {
    //     $results = $this->normalizeResults($payload['results'] ?? []);
    //     if ($results->isEmpty()) {
    //         return ['status' => 'error', 'message' => 'No results found'];
    //     }

    //     $examConfig      = $payload['exam_config'] ?? [];
    //     $academicDetails = collect($payload['academic_details'] ?? []);
    //     $studentDetails  = collect($payload['student_details'] ?? []);
    //     $meritType       = $examConfig['merit_process_type'] ?? 'total_mark_sequential';
    //     $groupBy         = $this->getGroupByFields($examConfig);

    //     // প্রথমে সবাইকে একসাথে সর্ট করি (Pass আগে, Fail পরে)
    //     $allSorted = $this->sortStudents($results, $meritType, $academicDetails);

    //     // তারপর র‍্যাঙ্ক দিই (একবারই, পুরো ক্লাসের জন্য)
    //     $allRanked = $this->assignRanks($allSorted, $meritType, $academicDetails, $studentDetails);

    //     // এখন group-wise আলাদা করে দিই (শুধু দেখানোর জন্য)
    //     $grouped = collect($allRanked)->groupBy(function ($student) use ($groupBy, $academicDetails, $studentDetails) {
    //         $stdId = $student['student_id'];
    //         $acad  = $academicDetails[$stdId] ?? null;
    //         $std   = $studentDetails[$stdId] ?? null;
    //         if (!$acad) return 'unknown';

    //         $keys = [];
    //         foreach ($groupBy as $field) {
    //             $value = match ($field) {
    //                 'shift'    => $acad['shift'] ?? null,
    //                 'section'  => $acad['section'] ?? null,
    //                 'group'    => $acad['group'] ?? null,
    //                 'gender'   => $std['student_gender'] ?? null,
    //                 'religion' => $std['student_religion'] ?? null,
    //                 default    => null,
    //             };
    //             $keys[] = $value ?? 'unknown';
    //         }
    //         return implode('|', $keys);
    //     });

    //     $finalMerit = [];
    //     foreach ($grouped as $groupStudents) {
    //         $finalMerit = array_merge($finalMerit, $groupStudents->toArray());
    //     }

    //     $all = collect($finalMerit);

    //     return [
    //         'total_students' => $results->count(),
    //         'merit_type'     => $meritType,
    //         'grouped_by'     => $groupBy,
    //         'data'           => [
    //             'all_students'   => $finalMerit,
    //             'section_wise'   => $all->groupBy('section')->map->values()->toArray(),
    //             'shift_wise'     => $all->groupBy('shift')->map->values()->toArray(),
    //             'group_wise'     => $all->groupBy('group')->map->values()->toArray(),
    //             'gender_wise'    => $all->groupBy('gender')->map->values()->toArray(),
    //             'religion_wise'  => $all->groupBy('religion')->map->values()->toArray(),
    //         ]
    //     ];
    // }
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

        // CRITICAL: Sort ALL students together first (entire class)
        $allSorted = $this->sortStudents($results, $meritType, $academicDetails);

        // CRITICAL: Assign ranks to ALL students together (class-wise ranking)
        $allRanked = $this->assignRanks($allSorted, $meritType, $academicDetails, $studentDetails);

        // Now we have class-wise ranking (1,2,3... for entire class)
        // Don't regroup and merge - just use the ranked array as is
        $finalMerit = $allRanked;

        // Create different views from the same ranked data
        $all = collect($finalMerit);

        return [
            'total_students' => $results->count(),
            'merit_type'     => $meritType,
            'grouped_by'     => $groupBy,
            'data'           => [
                // Class-wise: all students with their class ranks (1,2,3,4...)
                'all_students'   => $finalMerit,

                // Grouped views: same data, just organized differently
                // Students keep their CLASS rank, not section/shift rank
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

        $sorted = $students->sort(function ($a, $b) use ($isGradePoint, $academicDetails) {
            $aId = $a['student_id'];
            $bId = $b['student_id'];

            if ($a['result_status'] !== $b['result_status']) {
                return ($a['result_status'] === 'Pass') ? -1 : 1;
            }

            $aGpa = (float) ($a['gpa_with_optional'] ?? $a['gpa'] ?? 0);
            $bGpa = (float) ($b['gpa_with_optional'] ?? $b['gpa'] ?? 0);

            if ($aGpa !== $bGpa) {
                return $bGpa <=> $aGpa;
            }

            // FORCE roll sorting in Grade Point mode
            $aRoll = $academicDetails->get($aId)['class_roll'] ?? PHP_INT_MAX;
            $bRoll = $academicDetails->get($bId)['class_roll'] ?? PHP_INT_MAX;

            // Add debug: remove later
            // if ($aGpa == 5.00 && $bGpa == 5.00) {
            //     \Log::info("Comparing rolls: A={$aId} roll={$aRoll}, B={$bId} roll={$bRoll}, returning " . ($aRoll <=> $bRoll));
            // }

            if ($isGradePoint) {
                return $aRoll <=> $bRoll;  // lower roll first
            }

            // Only reach here if NOT grade point mode
            $aTM = $this->getTotalMark($a);
            $bTM = $this->getTotalMark($b);
            if ($aTM !== $bTM) {
                return $bTM <=> $aTM;
            }

            return $aRoll <=> $bRoll;
        });

        // Debug: check final order of top students
        // \Log::info('Top 5 after sort:', $sorted->take(5)->pluck('student_id', 'class_roll')->toArray());

        return $sorted->values();
    }
    private function assignRanks(
        Collection $sorted,
        string $meritType,
        Collection $academicDetails,
        Collection $studentDetails
    ): array {
        $isSequential = str_contains(strtolower($meritType), 'sequential');
        $useGpa = str_contains(strtolower($meritType), 'grade point') || str_contains(strtolower($meritType), 'gpa');

        $ranked = [];
        $rank = 1;

        foreach ($sorted as $index => $student) {
            $stdId = $student['student_id'];
            $acad  = $academicDetails[$stdId] ?? [];
            $std   = $studentDetails[$stdId] ?? [];

            $totalMark = $this->getTotalMark($student);
            $gpa       = (float) ($student['gpa_with_optional'] ?? $student['gpa'] ?? 0);
            $roll      = $acad['class_roll'] ?? 0;

            $primary   = $useGpa ? $gpa : $totalMark;
            $secondary = $useGpa ? $totalMark : $gpa;

            if ($isSequential) {
                // Sequential mode: every student gets unique rank 1,2,3...
                // Rank = position in sorted array
                $currentRank = $index + 1;
            } else {
                // Non-sequential: students with same primary metric share rank
                if ($index === 0) {
                    $currentRank = $rank;
                } else {
                    $prevStudent = $ranked[$index - 1];
                    $prevPrimary = $prevStudent['merit_primary'];

                    if ($primary < $prevPrimary) {
                        $rank = $index + 1; // Jump to current position
                    }
                    $currentRank = $rank;
                }
            }

            $ranked[] = [
                'student_id'           => $stdId,
                'student_name'         => $student['student_name'],
                'roll'                 => $roll,
                'total_mark'           => $totalMark,
                'gpa'                  => round($gpa, 2),
                'gpa_without_optional' => round($student['gpa_without_optional'] ?? 0, 2),
                'letter_grade'         => $student['letter_grade_with_optional'] ?? $student['letter_grade'] ?? 'F',
                'result_status'        => $student['result_status'],
                'merit_position'       => $currentRank,
                'merit_primary'        => $primary,
                'merit_secondary'      => $secondary,
                'shift'                => $acad['shift'] ?? null,
                'section'              => $acad['section'] ?? null,
                'group'                => $acad['group'] ?? null,
                'gender'               => $std['student_gender'] ?? null,
                'religion'             => $std['student_religion'] ?? null,
            ];
        }

        return $ranked;
    }
}
