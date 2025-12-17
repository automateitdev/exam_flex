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

    private function sortStudents(Collection $students, string $meritType, Collection $academicDetails): Collection
    {
        $meritTypeLower = strtolower($meritType);
        $useGpa = str_contains($meritTypeLower, 'grade point') || str_contains($meritTypeLower, 'gpa');

        return $students->sort(function ($a, $b) use ($useGpa, $academicDetails) {
            $aId = $a['student_id'];
            $bId = $b['student_id'];

            // Pass first, Fail later
            if ($a['result_status'] !== $b['result_status']) {
                return ($a['result_status'] === 'Pass') ? -1 : 1;
            }

            if ($useGpa) {
                // GPA ranking primary
                $aVal = (float) ($a['gpa_with_optional'] ?? $a['gpa'] ?? 0);
                $bVal = (float) ($b['gpa_with_optional'] ?? $b['gpa'] ?? 0);

                if ($aVal !== $bVal) {
                    return $bVal <=> $aVal; // higher GPA first
                }

                // Tie → Total Mark
                $aTM = $this->getTotalMark($a);
                $bTM = $this->getTotalMark($b);
                if ($aTM !== $bTM) {
                    return $bTM <=> $aTM;
                }
            } else {
                // Total mark ranking primary
                $aTM = $this->getTotalMark($a);
                $bTM = $this->getTotalMark($b);
                if ($aTM !== $bTM) {
                    return $bTM <=> $aTM; // higher total mark first
                }

                // Tie → GPA
                $aVal = (float) ($a['gpa_with_optional'] ?? $a['gpa'] ?? 0);
                $bVal = (float) ($b['gpa_with_optional'] ?? $b['gpa'] ?? 0);
                if ($aVal !== $bVal) {
                    return $bVal <=> $aVal;
                }
            }

            // Last tie-breaker → roll
            $aRoll = $academicDetails[$aId]['class_roll'] ?? 0;
            $bRoll = $academicDetails[$bId]['class_roll'] ?? 0;

            return $aRoll <=> $bRoll;
        })->values();
    }


    private function getTotalMark($student): float
    {
        $bonus = $student['optional_bonus'] ?? 0;
        return $bonus + collect($student['subjects'] ?? [])
            ->sum(fn($s) => $s['combined_final_mark'] ?? $s['final_mark'] ?? 0);
    }


    private function assignRanks(Collection $sorted, string $meritType, Collection $academicDetails, Collection $studentDetails): array
    {
        $isSequential = str_contains(strtolower($meritType), 'sequential');
        $useGpa       = str_contains(strtolower($meritType), 'gpa') || str_contains(strtolower($meritType), 'grade_point');

        $ranked = [];
        $rank   = 1;
        $previousValue = null;
        $previousRank  = null;

        foreach ($sorted as $index => $student) {
            $stdId = $student['student_id'];
            $acad  = $academicDetails[$stdId] ?? [];
            $std   = $studentDetails[$stdId] ?? [];

            // Determine primary value for ranking
            $primaryValue = $useGpa
                ? (float) ($student['gpa_with_optional'] ?? $student['gpa'] ?? 0)
                : $this->getTotalMark($student);

            // Determine tie-breaker (secondary value)
            $secondaryValue = $useGpa
                ? $this->getTotalMark($student) // GPA rank tie → higher total mark wins
                : (float) ($student['gpa_with_optional'] ?? $student['gpa'] ?? 0); // Total rank tie → higher GPA wins

            // Roll number tie-breaker
            $roll = $acad['class_roll'] ?? 0;

            if ($index === 0) {
                $currentRank = $rank;
            } else {
                if ($isSequential) {
                    // Sequential: no duplicate ranks
                    if ($primaryValue != $previousValue) {
                        $currentRank = $rank;
                    } else {
                        // tie-breaker: secondaryValue, then roll
                        $prevStudent = $sorted[$index - 1];
                        $prevPrimary = $useGpa
                            ? ($prevStudent['gpa_with_optional'] ?? $prevStudent['gpa'] ?? 0)
                            : $this->getTotalMark($prevStudent);

                        $prevSecondary = $useGpa
                            ? $this->getTotalMark($prevStudent)
                            : ($prevStudent['gpa_with_optional'] ?? $prevStudent['gpa'] ?? 0);

                        $prevRoll = $academicDetails[$prevStudent['student_id']]['class_roll'] ?? 0;

                        if ($secondaryValue != $prevSecondary) {
                            $currentRank = $rank;
                        } elseif ($roll != $prevRoll) {
                            $currentRank = $rank;
                        } else {
                            // exact tie → same rank
                            $currentRank = $rank;
                        }
                    }
                    $rank++;
                } else {
                    // Non-Sequential: duplicate ranks allowed
                    $currentRank = ($primaryValue == $previousValue) ? $previousRank : $rank;
                    $rank++;
                }
            }

            $previousValue = $primaryValue;
            $previousRank  = $currentRank;

            $ranked[] = [
                'student_id'           => $stdId,
                'student_name'         => $student['student_name'],
                'roll'                 => $student['roll'] ?? $acad['class_roll'] ?? null,
                'total_mark'           => $this->getTotalMark($student),
                'gpa'                  => round($student['gpa_with_optional'] ?? $student['gpa'] ?? 0, 2),
                'gpa_without_optional' => round($student['gpa_without_optional'] ?? 0, 2),
                'letter_grade'         => $student['letter_grade_with_optional'] ?? $student['letter_grade'] ?? 'F',
                'result_status'        => $student['result_status'],
                'merit_position'       => $currentRank,
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
