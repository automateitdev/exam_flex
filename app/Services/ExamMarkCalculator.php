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
        $gradePoints    = $payload['grade_points'] ?? [];
        $studentId      = $student['student_id'];
        $partMarks      = $student['part_marks'] ?? [];
        $examName       = $subject['exam_name'] ?? 'Semester Exam';
        $subjectName    = $subject['subject_name'] ?? null;
        $attendanceReq  = $subject['attendance_required'] ?? false;

        $isAbsent = $attendanceReq && strtolower($student['attendance_status'] ?? 'absent') === 'absent';
        if ($isAbsent) {
            return $this->absentResult($studentId, $partMarks, $examName, $subjectName);
        }

        // ১. টোটাল অবটেইনড মার্ক
        $obtainedMark = $details->sum(fn($d) => $partMarks[$d['exam_code_title']] ?? 0);

        // ২. টোটাল ম্যাক্স মার্ক (converted) — পার্সেন্টেজের জন্য
        $totalMaxMark = $details->sum(function ($d) {
            $total = $d['total_mark'] ?? 100;
            $conversion = ($d['conversion'] ?? 100) / 100;
            return $total * $conversion;
        });

        // ৩. Individual Pass চেক
        $individualPass = true;
        $failedParts = [];
        foreach ($details->where('pass_mark', '>', 0) as $d) {
            $code = $d['exam_code_title'];
            $got  = $partMarks[$code] ?? 0;
            if ($got < $d['pass_mark']) {
                $individualPass = false;
                $failedParts[] = "$code ($got < {$d['pass_mark']})";
            }
        }

        // ৪. Overall Pass চেক
        $overallDetail = $details->where('is_overall', true)->first();
        $hasOverallCheck = $overallDetail && ($overallDetail['overall_mark'] ?? 0) > 0;

        $overallPass = true;
        $overallCalc = 0;
        $overallRequired = 0;

        if ($hasOverallCheck) {
            foreach ($details as $d) {
                $mark = $partMarks[$d['exam_code_title']] ?? 0;
                $percent = ($d['conversion'] ?? 100) / 100;
                $overallCalc += $mark * $percent;
            }
            $overallRequired = $overallDetail['overall_mark'];
            $overallPass = $overallCalc >= $overallRequired;
        }

        // ৫. Threshold চেক (highest_fail_mark)
        $failThreshold = $highestFail > 0 ? $highestFail + 0.01 : PHP_INT_MAX;
        $thresholdPass = $obtainedMark >= $failThreshold;

        // ৬. ফাইনাল পাস/ফেল
        $passBeforeGrace = $individualPass && $overallPass && $thresholdPass;

        $finalMark = $obtainedMark;
        $appliedGrace = 0;
        $pass = $passBeforeGrace;
        $remark = '';

        if (!$passBeforeGrace) {
            $reasons = [];
            if (!$individualPass) $reasons[] = 'Failed Individual: ' . implode(', ', $failedParts);
            if (!$overallPass) $reasons[] = "Overall";
            if (!$thresholdPass) $reasons[] = "Below threshold";
            $remark = implode(' | ', $reasons);
        }

        // ৭. গ্রেস মার্ক — শুধু পাস হলে যোগ হবে
        if (!$passBeforeGrace && $graceMark > 0 && $obtainedMark < $failThreshold) {
            $needed = ceil($failThreshold - $obtainedMark);
            $possibleGrace = min($needed, $graceMark);
            $tempMark = $obtainedMark + $possibleGrace;

            if ($tempMark >= $failThreshold) {
                $appliedGrace = $possibleGrace;
                $finalMark = $tempMark;
                $pass = true;
                $remark = "Pass by Grace (+{$appliedGrace} marks)";
            }
        }

        // ৮. পার্সেন্টেজ বের করুন
        $percentage = $totalMaxMark > 0 ? ($finalMark / $totalMaxMark) * 100 : 0;

        // ৯. গ্রেড পার্সেন্টেজ দিয়ে
        $gradeInfo = $this->getGradeByPercentage($percentage, $gradePoints);

        return [
            'student_id'        => $studentId,
            'obtained_mark'     => (float) $obtainedMark,
            'final_mark'        => (float) $finalMark,
            'grace_mark'        => (float) $appliedGrace,
            'result_status'     => $pass ? 'Pass' : 'Fail',
            'remark'            => $remark,
            'percentage'        => round($percentage, 2),
            'part_marks'        => $partMarks,
            'exam_name'         => $examName,
            'subject_name'      => $subjectName,
            'grade'             => $gradeInfo['grade'],
            'grade_point'       => $gradeInfo['grade_point'],
            'attendance_status' => $student['attendance_status'] ?? null
        ];
    }

    private function getGradeByPercentage($percentage, $gradePoints)
    {
        $grade = collect($gradePoints)->first(function ($g) use ($percentage) {
            return $percentage >= $g['from_mark'] && $percentage <= $g['to_mark'];
        });

        return [
            'grade'       => $grade['grade'] ?? 'F',
            'grade_point' => $grade['grade_point'] ?? '0.00'
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
