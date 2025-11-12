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
        $subject = $payload['subjects'][0]; // প্রথম সাবজেক্ট
        $details = collect($subject['exam_config']);
        $method = $subject['method_of_evaluation'] ?? 'At Actual';
        $graceMark = $subject['grace_mark'] ?? 0;
        $examName = $subject['exam_name'] ?? 'Semester Exam';
        $subjectName = $subject['subject_name'] ?? null;
        $attendanceRequired = $subject['attendance_required'] ?? false;
        $highestFail = $subject['highest_fail_mark'] ?? null;
        $overallPassMark = $subject['overall_pass_mark'] ?? null;
        $gradePoints = $payload['grade_points'] ?? [];

        $studentId = $student['student_id'];
        $partMarks = $student['part_marks'];
        $isAbsent = $attendanceRequired &&
            (strtolower($student['attendance_status'] ?? 'absent') === 'absent');

        if ($isAbsent) {
            return $this->absentResult($studentId, $partMarks, $examName, $subjectName);
        }

        $calculated = $this->calculateObtainedMark($details, $partMarks, $method);
        $obtainedMark = roundMark($calculated, $method);

        $individualPass = $this->checkIndividualPass($details, $partMarks, $method);
        $overallPass = $this->checkOverallPass($details, $partMarks, $method, $overallPassMark);

        $failThreshold = $highestFail !== null ? $highestFail + 0.01 : 33;
        $finalMark = $obtainedMark;
        $pass = $individualPass && $overallPass && ($finalMark >= $failThreshold);
        $remark = $this->getRemark($pass, $individualPass, $overallPass);

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

    private function calculateObtainedMark($details, $partMarks, $method)
    {
        $calculated = 0;
        foreach ($details as $d) {
            $code = $d['exam_code_title'];
            $mark = $partMarks[$code] ?? 0;
            $total = $d['total_mark'] ?? 100;
            $conv = $d['conversion'] ?? 100;
            $calculated += ($mark / $total) * $conv;
        }
        return $calculated;
    }

    private function checkIndividualPass($details, $partMarks, $method)
    {
        foreach ($details->where('is_individual', true) as $d) {
            $code = $d['exam_code_title'];
            $mark = $partMarks[$code] ?? 0;
            $total = $d['total_mark'] ?? 100;
            $conv = $d['conversion'] ?? 100;
            $converted = ($mark / $total) * $conv;
            if (roundMark($converted, $method) < ($d['pass_mark'] ?? 0)) {
                return false;
            }
        }
        return true;
    }

    private function checkOverallPass($details, $partMarks, $method, $overallPassMark)
    {
        if ($overallPassMark === null) return true;
        $calc = 0;
        foreach ($details->where('is_overall', true) as $d) {
            $code = $d['exam_code_title'];
            $mark = $partMarks[$code] ?? 0;
            $total = $d['total_mark'] ?? 100;
            $conv = $d['conversion'] ?? 100;
            $calc += ($mark / $total) * $conv;
        }
        return roundMark($calc, $method) >= $overallPassMark;
    }

    private function getFailThreshold($config)
    {
        $highestFail = $config['highest_fail_mark'] ?? null;
        return $highestFail !== null ? $highestFail + 0.01 : 33;
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
        $grade = collect($gradePoints)->first(fn($g) => $finalMark >= $g['from_mark'] && $finalMark <= $g['to_mark']);
        return [
            'grade' => $grade['grade'] ?? 'F',
            'grade_point' => $grade['grade_point'] ?? '0.00'
        ];
    }
}
