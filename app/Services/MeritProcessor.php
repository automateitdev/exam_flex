<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\FacadesLog;
use Illuminate\Support\Facades\Log;

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

        // DEBUG: Log incoming data
        Log::info('=== MERIT PROCESS START ===');
        Log::info('Total students received: ' . $results->count());
        Log::info('Merit type: ' . $meritType);
        Log::info('Group by fields: ' . json_encode($groupBy));

        // DEBUG: Check unique sections in incoming data
        $sections = $results->map(function ($student) use ($academicDetails) {
            $stdId = $student['student_id'];
            return $academicDetails[$stdId]['section'] ?? 'unknown';
        })->unique()->values()->toArray();
        Log::info('Sections in data: ' . json_encode($sections));

        // DEBUG: Log first 5 students before sorting
        Log::info('First 5 students BEFORE sorting:', $results->take(5)->map(function ($s) use ($academicDetails) {
            return [
                'id' => $s['student_id'],
                'section' => $academicDetails[$s['student_id']]['section'] ?? null,
                'roll' => $academicDetails[$s['student_id']]['class_roll'] ?? null,
                'gpa' => $s['gpa_with_optional'] ?? $s['gpa'] ?? 0,
            ];
        })->toArray());

        // CRITICAL: Sort ALL students together first (entire class)
        $allSorted = $this->sortStudents($results, $meritType, $academicDetails);

        // DEBUG: Log first 10 students after sorting
        Log::info('First 10 students AFTER sorting:', $allSorted->take(10)->map(function ($s) use ($academicDetails) {
            return [
                'id' => $s['student_id'],
                'section' => $academicDetails[$s['student_id']]['section'] ?? null,
                'roll' => $academicDetails[$s['student_id']]['class_roll'] ?? null,
                'gpa' => $s['gpa_with_optional'] ?? $s['gpa'] ?? 0,
                'total_mark' => $this->getTotalMark($s),
            ];
        })->toArray());

        // CRITICAL: Assign ranks to ALL students together (class-wise ranking)
        $allRanked = $this->assignRanks($allSorted, $meritType, $academicDetails, $studentDetails);

        // DEBUG: Log first 10 students after ranking
        Log::info('First 10 students AFTER ranking:', collect($allRanked)->take(10)->map(function ($s) {
            return [
                'id' => $s['student_id'],
                'section' => $s['section'],
                'roll' => $s['roll'],
                'gpa' => $s['gpa'],
                'total_mark' => $s['total_mark'],
                'rank' => $s['merit_position'],
            ];
        })->toArray());

        // Now we have class-wise ranking (1,2,3... for entire class)
        $finalMerit = $allRanked;

        // DEBUG: Check for duplicate rank 1
        $rank1Students = collect($finalMerit)->where('merit_position', 1)->values();
        if ($rank1Students->count() > 1) {
            Log::error('PROBLEM: Multiple students with rank 1!', $rank1Students->toArray());
        } else {
            Log::info('OK: Only one student with rank 1', $rank1Students->toArray());
        }

        // Create different views from the same ranked data
        $all = collect($finalMerit);

        Log::info('=== MERIT PROCESS END ===');

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

        $sorted = $students->sort(function ($a, $b) use ($isGradePoint, $academicDetails) {
            $aId = $a['student_id'];
            $bId = $b['student_id'];

            // Pass first, Fail later
            if ($a['result_status'] !== $b['result_status']) {
                return ($a['result_status'] === 'Pass') ? -1 : 1;
            }

            // Compare GPA
            $aGpa = (float) ($a['gpa_with_optional'] ?? $a['gpa'] ?? 0);
            $bGpa = (float) ($b['gpa_with_optional'] ?? $b['gpa'] ?? 0);

            if ($aGpa !== $bGpa) {
                return $bGpa <=> $aGpa; // Higher GPA first
            }

            // GPA is same - check total marks for Grade Point mode
            if ($isGradePoint) {
                $aTM = $this->getTotalMark($a);
                $bTM = $this->getTotalMark($b);

                if ($aTM !== $bTM) {
                    return $bTM <=> $aTM; // Higher total mark first
                }
            }

            // Final tie-breaker: roll number (lower roll first)
            $aRoll = $academicDetails->get($aId)['class_roll'] ?? PHP_INT_MAX;
            $bRoll = $academicDetails->get($bId)['class_roll'] ?? PHP_INT_MAX;

            return $aRoll <=> $bRoll;
        });

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
                // Sequential mode: every student gets unique rank based on their position
                $currentRank = $index + 1;
            } else {
                // Non-sequential: students with same primary metric share rank
                if ($index === 0) {
                    $currentRank = 1;
                } else {
                    $prevStudent = $ranked[$index - 1];
                    $prevPrimary = $prevStudent['merit_primary'];

                    if ($primary < $prevPrimary) {
                        $currentRank = $index + 1;
                    } else {
                        $currentRank = $prevStudent['merit_position'];
                    }
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
