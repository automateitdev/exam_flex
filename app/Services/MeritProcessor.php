<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\FacadesLog;
use Illuminate\Support\Facades\Log;

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

        $all = collect($finalMerit);

        return [
            'total_students' => $results->count(),
            'merit_type'     => $meritType,
            'grouped_by'     => $groupBy,
            'data' => [
                // CLASS WISE (already correct)
                'all_students' => $finalMerit,

                // SECTION WISE
                'section_wise' => $this->rankByField(
                    $all,
                    'section',
                    $meritType,
                    $academicDetails,
                    $studentDetails
                ),

                // SHIFT WISE
                'shift_wise' => $this->rankByField(
                    $all,
                    'shift',
                    $meritType,
                    $academicDetails,
                    $studentDetails
                ),

                // GROUP WISE
                'group_wise' => $this->rankByField(
                    $all,
                    'group',
                    $meritType,
                    $academicDetails,
                    $studentDetails
                ),

                // GENDER WISE
                'gender_wise' => $this->rankByField(
                    $all,
                    'gender',
                    $meritType,
                    $academicDetails,
                    $studentDetails
                ),

                // RELIGION WISE
                'religion_wise' => $this->rankByField(
                    $all,
                    'religion',
                    $meritType,
                    $academicDetails,
                    $studentDetails
                ),
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


    private function getTotalMark($student): float
    {
        // $bonus = $student['optional_bonus'] ?? 0;
        // return collect($student['subjects'] ?? [])
        //     ->sum(fn($s) => $s['combined_final_mark'] ?? $s['final_mark'] ?? 0);

        return collect($student['subjects'] ?? [])
            ->filter(fn($s) => $s['is_uncountable'] === false)
            ->sum(fn($s) => $s['final_mark'] ?? 0);
    }

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

    // private function assignRanks(
    //     Collection $sorted,
    //     string $meritType,
    //     Collection $academicDetails,
    //     Collection $studentDetails
    // ): array {
    //     $isSequential = str_contains(strtolower($meritType), 'sequential');
    //     $useGpa = str_contains(strtolower($meritType), 'grade point') || str_contains(strtolower($meritType), 'gpa');

    //     $ranked = [];

    //     foreach ($sorted as $index => $student) {
    //         $stdId = $student['student_id'];
    //         $acad  = $academicDetails[$stdId] ?? [];
    //         $std   = $studentDetails[$stdId] ?? [];

    //         $totalMark = $this->getTotalMark($student);
    //         $gpa       = (float) ($student['gpa_with_optional'] ?? $student['gpa'] ?? 0);
    //         $roll      = $acad['class_roll'] ?? 0;

    //         $primary   = $useGpa ? $gpa : $totalMark;
    //         $secondary = $useGpa ? $totalMark : $gpa;

    //         if ($isSequential) {
    //             // Sequential mode: every student gets unique rank based on their position
    //             $currentRank = $index + 1;
    //         } else {
    //             // Non-sequential: students with same primary metric share rank
    //             if ($index === 0) {
    //                 $currentRank = 1;
    //             } else {
    //                 $prevStudent = $ranked[$index - 1];
    //                 $prevPrimary = $prevStudent['merit_primary'];

    //                 if ($primary < $prevPrimary) {
    //                     $currentRank = $index + 1;
    //                 } else {
    //                     $currentRank = $prevStudent['merit_position'];
    //                 }
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
    //             'merit_primary'        => $primary,
    //             'merit_secondary'      => $secondary,
    //             'shift'                => $acad['shift'] ?? null,
    //             'section'              => $acad['section'] ?? null,
    //             'group'                => $acad['group'] ?? null,
    //             'gender'               => $std['student_gender'] ?? null,
    //             'religion'             => $std['student_religion'] ?? null,
    //         ];
    //     }

    //     return $ranked;
    // }
    private function assignRanks(
        Collection $sorted,
        string $meritType,
        Collection $academicDetails,
        Collection $studentDetails,
        string $groupField = null // optional group like 'section'
    ): array {
        $isSequential = str_contains(strtolower($meritType), 'sequential');
        $useGpa = str_contains(strtolower($meritType), 'grade point') || str_contains(strtolower($meritType), 'gpa');

        $ranked = [];
        $currentRank = 0;

        foreach ($sorted as $index => $student) {
            $stdId = $student['student_id'];
            $acad  = $academicDetails[$stdId] ?? [];
            $std   = $studentDetails[$stdId] ?? [];

            $totalMark = $this->getTotalMark($student);
            $gpa       = (float) ($student['gpa_with_optional'] ?? $student['gpa'] ?? 0);
            $roll      = $acad['class_roll'] ?? 0;

            $primary   = $useGpa ? $gpa : $totalMark;
            $secondary = $useGpa ? $totalMark : $gpa;

            if ($index === 0) {
                $currentRank = 1;
            } else {
                $prevStudent = $ranked[$index - 1];

                // For sequential, always increment
                if ($isSequential) {
                    $currentRank = $index + 1;
                } else {
                    // Non-sequential: compare primary
                    if ($primary < $prevStudent['merit_primary']) {
                        $currentRank = $index + 1;
                    } elseif ($primary === $prevStudent['merit_primary']) {
                        // Primary same → check secondary
                        if ($secondary < $prevStudent['merit_secondary']) {
                            $currentRank = $index + 1;
                        } elseif ($secondary === $prevStudent['merit_secondary']) {
                            // Secondary same → tie-breaker: roll number
                            $currentRank = ($roll > $prevStudent['roll']) ? $index + 1 : $prevStudent['merit_position'];
                        } else {
                            $currentRank = $prevStudent['merit_position'];
                        }
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


    private function rankByField(
        Collection $results,
        string $field,
        string $meritType,
        Collection $academicDetails,
        Collection $studentDetails
    ): array {
        return $results
            ->groupBy(fn($s) => $s[$field] ?? 'unknown')
            ->flatMap(function ($groupStudents) use ($meritType, $academicDetails, $studentDetails) {
                $sorted = $this->sortStudents(
                    collect($groupStudents),
                    $meritType,
                    $academicDetails
                );

                return $this->assignRanks(
                    $sorted,
                    $meritType,
                    $academicDetails,
                    $studentDetails
                );
            })
            ->values()
            ->toArray();
    }
}
