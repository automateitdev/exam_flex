<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

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
        $subject = $payload['subjects'][0];
        $details = collect($subject['exam_config']);
        $method = $subject['method_of_evaluation'] ?? 'At Actual';
        $graceMark = $subject['grace_mark'] ?? 0;
        $examName = $subject['exam_name'] ?? 'Semester Exam';
        $subjectName = $subject['subject_name'] ?? null;
        $attendanceRequired = $subject['attendance_required'] ?? false;
        $highestFail = $subject['highest_fail_mark'] ?? 0;
        $gradePoints = $payload['grade_points'] ?? [];

        $studentId = $student['student_id'];
        $partMarks = $student['part_marks'];
        $isAbsent = $attendanceRequired && strtolower($student['attendance_status'] ?? 'absent') === 'absent';

        if ($isAbsent) {
            return $this->absentResult($studentId, $partMarks, $examName, $subjectName);
        }

        // 1. obtained_mark = যোগ (CQ + MCQ)
        $obtainedMark = $details->sum(fn($d) => $partMarks[$d['exam_code_title']] ?? 0);

        // 2. Individual Pass (is_individual = true)
        $individualPass = true;
        foreach ($details->where('is_individual', true) as $d) {
            $mark = $partMarks[$d['exam_code_title']] ?? 0;
            if ($mark < ($d['pass_mark'] ?? 0)) {
                $individualPass = false;
                break;
            }
        }

        // 3. Overall Pass
        $overallPass = true;
        $overallCalc = 0;
        $overallDetails = $details->where('is_overall', true);
        $overallMarkRequired = $overallDetails->first()->overall_mark ?? $overallDetails->sum('pass_mark');

        if ($overallDetails->isNotEmpty() && $overallMarkRequired > 0) {
            foreach ($overallDetails as $d) {
                $mark = $partMarks[$d['exam_code_title']] ?? 0;
                $percent = ($d['conversion'] ?? 100) / 100;
                $overallCalc += $mark * $percent;
            }
            $finalOverall = roundMark($overallCalc, $method);
            $overallPass = $finalOverall >= $overallMarkRequired;
        }

        // 4. Fail Threshold
        $failThreshold = $highestFail > 0 ? $highestFail + 0.01 : 33;

        // 5. Final Pass (before grace)
        $finalMark = $obtainedMark;
        $pass = $individualPass && $overallPass && ($finalMark >= $failThreshold);
        $remark = $this->getRemark($pass, $individualPass, $overallPass);

        // 6. Grace Mark
        $appliedGrace = 0;
        if (!$pass && $graceMark > 0 && $finalMark < $failThreshold) {
            $needed = ceil($failThreshold - $finalMark);
            $appliedGrace = min($needed, $graceMark);
            $finalMark += $appliedGrace;
            if ($finalMark >= $failThreshold) {
                $pass = true;
                $remark = "Pass by Grace ($appliedGrace marks)";
            }
        }

        // 7. Grade
        $gradeInfo = $this->getGrade($finalMark, $gradePoints);

        return [
            'student_id' => $studentId,
            'obtained_mark' => (float) $obtainedMark,
            'final_mark' => (float) $finalMark,
            'grace_mark' => (float) $appliedGrace,
            'result_status' => $pass ? 'Pass' : 'Fail',
            'remark' => $remark,
            'part_marks' => $partMarks,
            'exam_name' => $examName,
            'subject_name' => $subjectName,
            'grade' => $gradeInfo['grade'],
            'grade_point' => $gradeInfo['grade_point'],
            'attendance_status' => $student['attendance_status'] ?? null
        ];
    }

    private function absentResult($studentId, $partMarks, $examName, $subjectName)
    {
        return [
            'student_id' => $studentId,
            'obtained_mark' => 0,
            'final_mark' => 0,
            'grace_mark' => 0,
            'result_status' => 'Fail',
            'remark' => 'Absent',
            'part_marks' => $partMarks,
            'exam_name' => $examName,
            'subject_name' => $subjectName,
            'grade' => 'F',
            'grade_point' => '0.00',
            'attendance_status' => 'absent'
        ];
    }

    private function getRemark($pass, $individualPass, $overallPass)
    {
        if ($pass) return '';
        return $individualPass
            ? ($overallPass ? 'Below Threshold' : 'Failed Overall')
            : 'Failed Individual';
    }


    private function getGrade($finalMark, $gradePoints)
    {
        $grade = collect($gradePoints)->first(function ($g) use ($finalMark) {
            return $finalMark >= $g['from_mark'] && $finalMark <= $g['to_mark'];
        });

        return [
            'grade' => $grade['grade'] ?? 'F',
            'grade_point' => $grade['grade_point'] ?? '0.00'
        ];
    }
}
