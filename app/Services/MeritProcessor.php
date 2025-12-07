<?php

namespace App\Services;

use Illuminate\Support\Collection;

class MeritProcessor
{
    public function process(array $payload): array
    {
        $results = collect($payload['results'] ?? []);
        if ($results->isEmpty()) {
            return ['status' => 'error', 'message' => 'No results found'];
        }

        $examConfig      = $payload['exam_config'] ?? [];
        $academicDetails = collect($payload['academic_details'] ?? []);
        $studentDetails  = collect($payload['student_details'] ?? []);
        $meritType       = $examConfig['merit_process_type'] ?? 'total_mark_sequential';
        $groupByFields   = $this->getGroupByFields($examConfig);

        // প্রথমে সবাইকে একসাথে সর্ট করি (Pass আগে, Fail পরে)
        $allSorted = $this->sortStudents($results, $meritType, $academicDetails);

        // পুরো ক্লাসের জন্য একবারই র‍্যাঙ্ক দিই
        $allRanked = $this->assignMainRank($allSorted, $meritType, $academicDetails);

        // এখন বিভিন্ন গ্রুপে (shift/section/group/gender/religion) আলাদা র‍্যাঙ্ক দিই
        $groupedData = $this->assignGroupRanks($allRanked, $groupByFields, $meritType, $academicDetails);

        return [
            'total_students' => $results->count(),
            'merit_type'     => $meritType,
            'grouped_by'     => $groupByFields,
            'data'           => [
                'all_students'   => $allRanked,
                'section_wise'   => $groupedData['section'],
                'shift_wise'     => $groupedData['shift'],
                'group_wise'     => $groupedData['group'],
                'gender_wise'    => $groupedData['gender'],
                'religion_wise'  => $groupedData['religion'],
            ]
        ];
    }

    private function sortStudents(Collection $students, string $meritType, Collection $academicDetails): Collection
    {
        $isGpaBased = str_contains(strtolower($meritType), 'gpa') || str_contains($meritType, 'grade_point');

        return $students->sort(function ($a, $b) use ($isGpaBased, $academicDetails) {
            $aId = $a['student_id'];
            $bId = $b['student_id'];

            // ১. Pass আগে, Fail পরে
            $aStatus = $a['result_status'] ?? 'Fail';
            $bStatus = $b['result_status'] ?? 'Fail';
            if ($aStatus !== $bStatus) {
                return ($aStatus === 'Pass') ? -1 : 1;
            }

            // ২. GPA বা মার্ক দিয়ে
            if ($aStatus === 'Pass') {
                $aVal = $isGpaBased ? ($a['gpa_with_optional'] ?? $a['gpa'] ?? 0) : $this->getTotalMark($a);
                $bVal = $isGpaBased ? ($b['gpa_with_optional'] ?? $b['gpa'] ?? 0) : $this->getTotalMark($b);
                if ($aVal !== $bVal) return $bVal <=> $aVal;
            } else {
                // Fail হলে মার্ক দিয়ে
                $aMark = $this->getTotalMark($a);
                $bMark = $this->getTotalMark($b);
                if ($aMark !== $bMark) return $bMark <=> $aMark;
            }

            // ৩. রোল দিয়ে
            $aRoll = $academicDetails[$aId]['class_roll'] ?? 999999;
            $bRoll = $academicDetails[$bId]['class_roll'] ?? 999999;
            return $aRoll <=> $bRoll;
        })->values();
    }

    private function assignMainRank(Collection $sorted, string $meritType, Collection $academicDetails): array
    {
        $isGpaBased = str_contains(strtolower($meritType), 'gpa') || str_contains($meritType, 'grade_point');
        $isSequential = str_contains(strtolower($meritType), 'sequential');

        $rank = 1;
        $prevKey = null;

        return $sorted->map(function ($student, $index) use (&$rank, &$prevKey, $isGpaBased, $isSequential, $academicDetails) {
            $value = $isGpaBased
                ? ($student['gpa_with_optional'] ?? $student['gpa'] ?? 0)
                : $this->getTotalMark($student);

            $roll = $academicDetails[$student['student_id']]['class_roll'] ?? 999999;
            $currentKey = $value . '|' . $roll;

            if ($index === 0 || $currentKey !== $prevKey) {
                $currentRank = $rank++;
            } else {
                $currentRank = $isSequential ? $rank++ : ($prevRank ?? $rank - 1);
            }

            $prevKey = $currentKey;
            $prevRank = $currentRank;

            $student['merit_position'] = $currentRank;
            return $student;
        })->toArray();
    }

    private function assignGroupRanks(array $allRanked, array $groupByFields, string $meritType, Collection $academicDetails): array
    {
        $isGpaBased = str_contains(strtolower($meritType), 'gpa') || str_contains($meritType, 'grade_point');
        $isSequential = str_contains(strtolower($meritType), 'sequential');

        $groups = [
            'shift'    => collect(),
            'section'  => collect(),
            'group'    => collect(),
            'gender'   => collect(),
            'religion' => collect(),
        ];

        foreach ($allRanked as $student) {
            $stdId = $student['student_id'];
            $acad = $academicDetails[$stdId] ?? [];
            $std  = $student['student_details'] ?? [];

            foreach ($groupByFields as $field) {
                $value = match ($field) {
                    'shift'    => $acad['shift'] ?? 'unknown',
                    'section'  => $acad['section'] ?? 'unknown',
                    'group'    => $acad['group'] ?? 'unknown',
                    'gender'   => $std['student_gender'] ?? 'unknown',
                    'religion' => $std['student_religion'] ?? 'unknown',
                    default    => 'unknown',
                };

                $groups[$field]->push((object) array_merge($student, [
                    'group_key' => $value
                ]));
            }
        }

        $final = [];
        foreach ($groups as $type => $group) {
            if ($group->isEmpty()) {
                $final[$type] = [];
                continue;
            }

            $grouped = $group->groupBy('group_key');
            $ranked = [];

            foreach ($grouped as $students) {
                $sorted = $students->sort(function ($a, $b) use ($isGpaBased) {
                    $aVal = $isGpaBased ? ($a['gpa_with_optional'] ?? $a['gpa'] ?? 0) : $this->getTotalMark($a);
                    $bVal = $isGpaBased ? ($b['gpa_with_optional'] ?? $b['gpa'] ?? 0) : $this->getTotalMark($b);
                    if ($aVal !== $bVal) return $bVal <=> $aVal;

                    $aMark = $this->getTotalMark($a);
                    $bMark = $this->getTotalMark($b);
                    if ($aMark !== $bMark) return $bMark <=> $aMark;

                    $aRoll = $a['roll'] ?? 999999;
                    $bRoll = $b['roll'] ?? 999999;
                    return $aRoll <=> $bRoll;
                });

                $rank = 1;
                $prevKey = null;
                foreach ($sorted as $index => $std) {
                    $value = $isGpaBased ? ($std['gpa_with_optional'] ?? $std['gpa'] ?? 0) : $this->getTotalMark($std);
                    $mark  = $this->getTotalMark($std);
                    $roll  = $std['roll'] ?? 999999;
                    $key   = $value . '|' . $mark . '|' . $roll;

                    if ($index === 0 || $key !== $prevKey) {
                        $currentRank = $rank++;
                    } else {
                        $currentRank = $isSequential ? $rank++ : ($prevRank ?? $rank - 1);
                    }

                    $prevKey = $key;
                    $prevRank = $currentRank;

                    $stdArray = (array) $std;
                    $stdArray["{$type}_merit_position"] = $currentRank;
                    $ranked[] = $stdArray;
                }
            }

            $final[$type] = $ranked;
        }

        return $final;
    }

    private function getTotalMark($student): float
    {
        $total = collect($student['subjects'] ?? [])
            ->sum(fn($s) => $s['combined_final_mark'] ?? $s['final_mark'] ?? 0);

        $bonus = $student['optional_bonus_mark'] ?? $student['optional_subject_mark'] ?? 0;
        return $total + $bonus;
    }

    private function getGroupByFields(array $config): array
    {
        $fields = [];
        if ($config['group_by_shift'] ?? false)    $fields[] = 'shift';
        if ($config['group_by_section'] ?? false)  $fields[] = 'section';
        if ($config['group_by_group'] ?? false)    $fields[] = 'group';
        if ($config['group_by_gender'] ?? false)   $fields[] = 'gender';
        if ($config['group_by_religion'] ?? false) $fields[] = 'religion';
        return $fields;
    }
}
