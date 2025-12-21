<?php

namespace App\Services;

use Illuminate\Support\Collection;

class ExamMarkCalculator
{
    public function calculate($payload)
    {
        $results = [];
        foreach ($payload['students'] as $student) {
            $results[] = $this->calculateStudent($student, $payload);
        }

        return [
            'results' => $results,
            'institute_id' => $payload['institute_id'],
            'exam_type' => 'semester',
            'exam_name' => $payload['subjects'][0]['exam_name'] ?? 'Semester Exam',
            'subject_name' => $payload['subjects'][0]['subject_name'] ?? null,
        ];
    }

    private function calculateStudent($student, $payload)
    {
        $subject        = $payload['subjects'][0];
        $details        = collect($subject['exam_config']);
        $graceMark      = $subject['grace_mark'] ?? 0;
        $highestFail    = $subject['highest_fail_mark'] ?? 0;
        $gradePoints    = collect($payload['grade_points'] ?? []);
        $studentId      = $student['student_id'];
        $partMarks      = $student['part_marks'] ?? [];
        $examName       = $subject['exam_name'] ?? 'Semester Exam';
        $subjectName    = $subject['subject_name'] ?? null;
        $attendanceReq  = $subject['attendance_required'] ?? false;

        /* =====================================================
         | ABSENT CHECK (UNCHANGED)
         ===================================================== */
        $isAbsent = $attendanceReq && strtolower($student['attendance_status'] ?? 'absent') === 'absent';
        if ($isAbsent) {
            return $this->absentResult($studentId, $partMarks, $examName, $subjectName);
        }

        /* =====================================================
         | 1. CONVERTED MARK CALCULATION (UNCHANGED)
         ===================================================== */
        $obtainedMark = 0;
        $totalMaxMark = 0;

        foreach ($details as $d) {
            $got = $partMarks[$d['exam_code_title']] ?? 0;
            $conversion = ($d['conversion'] ?? 100) / 100;
            $total = $d['total_mark'] ?? 0;

            $obtainedMark += $got * $conversion;
            $totalMaxMark += $total * $conversion;
        }

        /* =====================================================
         | 2. CONFIG PRESENCE FLAGS  ✅ NEW
         ===================================================== */
        $hasPassMark = $details->where('pass_mark', '>', 0)->count() > 0;

        $overallDetail = $details->where('is_overall', true)->first();
        $hasOverall = $overallDetail && ($overallDetail['overall_mark'] ?? 0) > 0;

        /* =====================================================
         | 3. SHORT CODE PASS CHECK  🔄 UPDATED
         ===================================================== */
        $individualPass = true;
        $failedParts = [];

        if ($hasPassMark) {
            foreach ($details->where('pass_mark', '>', 0) as $d) {
                $got = $partMarks[$d['exam_code_title']] ?? 0;
                if ($got < $d['pass_mark']) {
                    $individualPass = false;
                    $failedParts[] = "{$d['exam_code_title']} ($got < {$d['pass_mark']})";
                }
            }
        }

        /* =====================================================
         | 4. OVERALL PASS CHECK  🔄 UPDATED
         ===================================================== */
        $overallPass = true;
        $overallCalc = 0;
        $overallRequired = 0;

        if ($hasOverall) {
            foreach ($details as $d) {
                $overallCalc += ($partMarks[$d['exam_code_title']] ?? 0)
                    * (($d['conversion'] ?? 100) / 100);
            }

            $overallRequired = $overallDetail['overall_mark'];
            $overallPass = $overallCalc >= $overallRequired;
        }

        /* =====================================================
         | 5. FINAL PASS DECISION (CORE LOGIC)  ✅ FIXED
         ===================================================== */
        if ($hasPassMark && $hasOverall) {
            // both present → must pass both
            $pass = $individualPass && $overallPass;

        } elseif (!$hasPassMark && $hasOverall) {
            // only overall
            $pass = $overallPass;

        } elseif ($hasPassMark && !$hasOverall) {
            // only pass mark
            $pass = $individualPass;

        } else {
            $pass = true;
        }

        /* =====================================================
         | 6. REMARK BUILDING  🔄 UPDATED
         ===================================================== */
        $remark = '';

        if (!$pass) {
            $reasons = [];

            if ($hasPassMark && !$individualPass) {
                $reasons[] = 'Failed Individual: ' . implode(', ', $failedParts);
            }

            if ($hasOverall && !$overallPass) {
                $reasons[] = "Overall: $overallCalc < $overallRequired";
            }

            $remark = implode(' | ', $reasons);
        }

        /* =====================================================
         | 7. PERCENTAGE (UNCHANGED)
         ===================================================== */
        $percentage = ($totalMaxMark > 0)
            ? ($obtainedMark / $totalMaxMark) * 100
            : 0;

        /* =====================================================
         | 8. GRADE RESOLUTION (CONFIG DRIVEN)  ✅ FIXED
         ===================================================== */
        if ($pass) {
            $gradeInfo = $gradePoints->first(fn ($g) =>
                $percentage >= $g['from_mark'] &&
                $percentage <= $g['to_mark']
            );
        } else {
            $gradeInfo = $gradePoints->first(fn ($g) =>
                $g['result'] === 'Fail'
            );
        }

        /* =====================================================
         | 9. RESPONSE (UNCHANGED)
         ===================================================== */
        return [
            'student_id'        => $studentId,
            'obtained_mark'     => (float) $obtainedMark,
            'final_mark'        => (float) $obtainedMark,
            'grace_mark'        => 0,
            'result_status'     => $pass ? 'Pass' : 'Fail',
            'remark'            => $remark,
            'percentage'        => round($percentage, 2),
            'part_marks'        => $partMarks,
            'exam_name'         => $examName,
            'subject_name'      => $subjectName,
            'grade'             => $gradeInfo['grade'],
            'grade_point'       => $gradeInfo['grade_point'],
            'attendance_status' => $student['attendance_status']
        ];
    }

    private function absentResult($studentId, $partMarks, $examName, $subjectName)
    {
        return [
            'student_id'        => $studentId,
            'obtained_mark'     => 0,
            'final_mark'        => 0,
            'grace_mark'        => 0,
            'result_status'     => 'Fail',
            'remark'            => 'Absent',
            'percentage'        => 0,
            'part_marks'        => $partMarks,
            'exam_name'         => $examName,
            'subject_name'      => $subjectName,
            'grade'             => 'F',
            'grade_point'       => '0.00',
            'attendance_status' => 'absent'
        ];
    }
}
