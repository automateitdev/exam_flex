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


        // 1. obtained_mark = যোগ (CQ + MCQ)
        $obtainedMark = $details->sum(fn($d) => $partMarks[$d['exam_code_title']] ?? 0);

        if ($isAbsent) {
            return $this->absentResult($studentId, $partMarks, $examName, $subjectName, $obtainedMark);
        }

        // 2. Individual Pass (pass_mark দিয়ে)
        $individualPass = true;
        foreach ($details->where('is_individual', true) as $d) {
            $mark = $partMarks[$d['exam_code_title']] ?? 0;
            if ($mark < ($d['pass_mark'] ?? 0)) {
                $individualPass = false;
                break;
            }
        }

        // 3. Overall Pass (converted sum ≥ overall_mark)
        $overallPass = true;
        $overallCalc = 0;
        $overallDetails = $details->where('is_overall', true);

        if ($overallDetails->isNotEmpty()) {
            foreach ($overallDetails as $d) {
                $mark = $partMarks[$d['exam_code_title']] ?? 0;
                $percent = ($d['conversion'] ?? 100) / 100;
                $overallCalc += $mark * $percent;
            }

            $overallMarkRequired = $overallDetails->first()->overall_mark ?? 0;
            if ($overallMarkRequired > 0) {
                $overallPass = $overallCalc >= $overallMarkRequired;
            }
        }

        // 4. Fail Threshold
        $failThreshold = $highestFail > 0 ? $highestFail + 0.01 : 33;

        // 5. Pass Before Grace
        $passBeforeGrace = $individualPass && $overallPass && ($obtainedMark >= $failThreshold);
        $finalMark = $passBeforeGrace ? $obtainedMark : 0;

        // 6. Grace Mark
        $appliedGrace = 0;
        $pass = $passBeforeGrace;
        $remark = $passBeforeGrace ? '' : $this->getRemark($passBeforeGrace, $individualPass, $overallPass);

        if (!$passBeforeGrace && $graceMark > 0 && $obtainedMark < $failThreshold) {
            $needed = ceil($failThreshold - $obtainedMark);
            $appliedGrace = min($needed, $graceMark);
            $finalMark = $obtainedMark + $appliedGrace;
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

    private function absentResult($studentId, $partMarks, $examName, $subjectName, $obtainedMark)
    {
        return [
            'student_id' => $studentId,
            'obtained_mark' => $obtainedMark,
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
        if (!$individualPass) return 'Failed Individual';
        if (!$overallPass) return 'Failed Overall';
        return 'Below Threshold';
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
