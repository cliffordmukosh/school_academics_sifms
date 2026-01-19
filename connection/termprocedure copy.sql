DELIMITER $$

DROP PROCEDURE IF EXISTS sp_generate_term_subject_aggregates$$

CREATE PROCEDURE sp_generate_term_subject_aggregates(
    IN p_school_id INT,
    IN p_class_id INT,
    IN p_term VARCHAR(50)
)
BEGIN
    DECLARE v_min_subjects INT DEFAULT 0;
    DECLARE v_year YEAR;

    -- Determine the year from the exams (assuming consistent across the term)
    SELECT YEAR(created_at), MIN(min_subjects)
    INTO v_year, v_min_subjects
    FROM exams
    WHERE school_id = p_school_id
      AND term = p_term
      AND class_id = p_class_id
      AND status = 'closed'
    LIMIT 1;

    -- Check if exams exist and min_subjects is valid
    IF v_min_subjects = 0 OR NOT EXISTS (
        SELECT 1
        FROM exams
        WHERE school_id = p_school_id
          AND term = p_term
          AND class_id = p_class_id
          AND status = 'closed'
          AND EXISTS (
              SELECT 1 
              FROM results r 
              WHERE r.exam_id = exams.exam_id 
                AND r.status = 'confirmed'
          )
    ) THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'No confirmed exams found for the given school, class, and term, or invalid min_subjects';
    END IF;

    -- Step 1: Temporary table for subject-level scores per exam
    DROP TEMPORARY TABLE IF EXISTS tmp_term_subject_scores;
    CREATE TEMPORARY TABLE tmp_term_subject_scores (
        student_id INT,
        subject_id INT,
        exam_id INT,
        class_id INT,
        stream_id INT,
        year YEAR,
        subject_score DECIMAL(7,2),
        grading_system_id INT,
        subject_teacher_id INT,
        min_subjects INT
    );

    INSERT INTO tmp_term_subject_scores (student_id, subject_id, exam_id, class_id, stream_id, year, subject_score, grading_system_id, subject_teacher_id, min_subjects)
    SELECT 
        r.student_id,
        r.subject_id,
        r.exam_id,
        r.class_id,
        r.stream_id,
        YEAR(e.created_at) AS year,
        CASE 
            WHEN es.use_papers = 0 THEN r.score
            ELSE SUM((r.score / sp.max_score) * sp.contribution_percentage)
        END AS subject_score,
        e.grading_system_id,
        ts.user_id AS subject_teacher_id,
        e.min_subjects
    FROM results r
    JOIN exam_subjects es 
        ON r.exam_id = es.exam_id AND r.subject_id = es.subject_id
    JOIN exams e
        ON r.exam_id = e.exam_id
    LEFT JOIN subject_papers sp 
        ON r.subject_id = sp.subject_id AND r.paper_id = sp.paper_id
    LEFT JOIN teacher_subjects ts
        ON r.subject_id = ts.subject_id
        AND r.class_id = ts.class_id
        AND (r.stream_id = ts.stream_id OR ts.stream_id IS NULL)
        AND ts.school_id = p_school_id
        AND (ts.academic_year = YEAR(e.created_at) OR ts.academic_year IS NULL)
    WHERE e.school_id = p_school_id
      AND e.term = p_term
      AND e.class_id = p_class_id
      AND e.status = 'closed'
      AND r.status = 'confirmed'
    GROUP BY r.student_id, r.subject_id, r.exam_id, r.class_id, r.stream_id, YEAR(e.created_at), es.use_papers, e.grading_system_id, ts.user_id, e.min_subjects;

    -- Step 2: Insert raw per-exam subject scores
    INSERT INTO term_subject_aggregates (
        school_id, student_id, subject_id, exam_id, class_id, stream_id, term, year, subject_score
    )
    SELECT 
        p_school_id,
        ts.student_id,
        ts.subject_id,
        ts.exam_id,
        ts.class_id,
        ts.stream_id,
        p_term,
        ts.year,
        ts.subject_score
    FROM tmp_term_subject_scores ts
    ON DUPLICATE KEY UPDATE
        subject_score = VALUES(subject_score);

    -- Step 3: Insert per-subject totals & means across all exams in that term
    INSERT INTO term_subject_totals (
        school_id, student_id, class_id, stream_id, term, year, subject_id,
        subject_total, subject_mean, exam_count, grading_system_id, subject_teacher_id
    )
    SELECT 
        p_school_id,
        ts.student_id,
        ts.class_id,
        ts.stream_id,
        p_term,
        ts.year,
        ts.subject_id,
        SUM(ts.subject_score) AS subject_total,
        ROUND(AVG(ts.subject_score),2) AS subject_mean,
        COUNT(DISTINCT ts.exam_id) AS exam_count,
        MIN(ts.grading_system_id) AS grading_system_id,
        MIN(ts.subject_teacher_id) AS subject_teacher_id
    FROM tmp_term_subject_scores ts
    GROUP BY ts.student_id, ts.class_id, ts.stream_id, ts.year, ts.subject_id
    HAVING COUNT(DISTINCT ts.grading_system_id) = 1
    ON DUPLICATE KEY UPDATE
        subject_total = VALUES(subject_total),
        subject_mean = VALUES(subject_mean),
        exam_count = VALUES(exam_count),
        grading_system_id = VALUES(grading_system_id),
        subject_teacher_id = VALUES(subject_teacher_id);

    -- Step 3.1: Update subject grades based on floored subject_mean
    UPDATE term_subject_totals tst
    SET tst.subject_grade = (
        SELECT gr.grade
        FROM grading_rules gr
        WHERE gr.grading_system_id = tst.grading_system_id
          AND gr.min_score <= FLOOR(tst.subject_mean) 
          AND FLOOR(tst.subject_mean) <= gr.max_score
        LIMIT 1
    )
    WHERE tst.school_id = p_school_id
      AND tst.term = p_term
      AND tst.class_id = p_class_id;

    -- Step 3.2: Update subject teacher remarks based on the calculated grade
    UPDATE term_subject_totals tst
    SET tst.subject_teacher_remark_text = (
        SELECT rr.remark_text
        FROM remarks_rules rr
        WHERE rr.category = 'subject_teacher'
          AND (rr.school_id = tst.school_id OR rr.school_id IS NULL)
          AND rr.grade = tst.subject_grade
        ORDER BY rr.school_id DESC
        LIMIT 1
    )
    WHERE tst.school_id = p_school_id
      AND tst.term = p_term
      AND tst.class_id = p_class_id
      AND tst.subject_grade IS NOT NULL;

    -- Step 3.3: Update subject_points based on subject_grade
    UPDATE term_subject_totals tst
    SET tst.subject_points = (
        SELECT gr.points
        FROM grading_rules gr
        WHERE gr.grading_system_id = tst.grading_system_id
          AND gr.grade = tst.subject_grade
        LIMIT 1
    )
    WHERE tst.school_id = p_school_id
      AND tst.term = p_term
      AND tst.class_id = p_class_id
      AND tst.subject_grade IS NOT NULL;

    -- Step 4: Generate student-level term aggregates from term_subject_totals
    INSERT INTO student_term_results_aggregates (
        school_id, student_id, class_id, stream_id, term, year,
        total_marks, average, total_points, min_subjects, grading_system_id,
        kcpe_score, kcpe_grade
    )
    SELECT 
        tst.school_id,
        tst.student_id,
        tst.class_id,
        tst.stream_id,
        tst.term,
        tst.year,
        SUM(tst.subject_mean) AS total_marks,
        ROUND(SUM(tst.subject_mean) / v_min_subjects, 2) AS average,
        ROUND(SUM(COALESCE(tst.subject_points, 0)) / v_min_subjects, 3) AS total_points,
        v_min_subjects,
        MIN(tst.grading_system_id) AS grading_system_id,
        s.kcpe_score,
        s.kcpe_grade
    FROM term_subject_totals tst
    JOIN students s ON tst.student_id = s.student_id
    WHERE tst.school_id = p_school_id
      AND tst.term = p_term
      AND tst.class_id = p_class_id
      AND tst.year = v_year
    GROUP BY tst.student_id, tst.class_id, tst.stream_id, tst.term, tst.year, s.kcpe_score, s.kcpe_grade
    ON DUPLICATE KEY UPDATE
        total_marks = VALUES(total_marks),
        average = VALUES(average),
        total_points = VALUES(total_points),
        min_subjects = VALUES(min_subjects),
        grading_system_id = VALUES(grading_system_id),
        kcpe_score = VALUES(kcpe_score),
        kcpe_grade = VALUES(kcpe_grade);

    -- Step 4.1: Update student grades based on floored average
    UPDATE student_term_results_aggregates stra
    SET stra.grade = (
        SELECT gr.grade
        FROM grading_rules gr
        WHERE gr.grading_system_id = stra.grading_system_id
          AND gr.min_score <= FLOOR(stra.average) 
          AND FLOOR(stra.average) <= gr.max_score
        LIMIT 1
    )
    WHERE stra.school_id = p_school_id
      AND stra.term = p_term
      AND stra.class_id = p_class_id
      AND stra.year = v_year;

    -- Step 4.2: Update class teacher remarks based on the calculated grade
    UPDATE student_term_results_aggregates stra
    SET stra.class_teacher_remark_text = (
        SELECT rr.remark_text
        FROM remarks_rules rr
        WHERE rr.category = 'class_teacher'
          AND (rr.school_id = stra.school_id OR rr.school_id IS NULL)
          AND rr.grade = stra.grade
        ORDER BY rr.school_id DESC
        LIMIT 1
    )
    WHERE stra.school_id = p_school_id
      AND stra.term = p_term
      AND stra.class_id = p_class_id
      AND stra.year = v_year
      AND stra.grade IS NOT NULL;

    -- Step 4.3: Update principal remarks based on the calculated grade
    UPDATE student_term_results_aggregates stra
    SET stra.principal_remark_text = (
        SELECT rr.remark_text
        FROM remarks_rules rr
        WHERE rr.category = 'principal'
          AND (rr.school_id = stra.school_id OR rr.school_id IS NULL)
          AND rr.grade = stra.grade
        ORDER BY rr.school_id DESC
        LIMIT 1
    )
    WHERE stra.school_id = p_school_id
      AND stra.term = p_term
      AND stra.class_id = p_class_id
      AND stra.year = v_year
      AND stra.grade IS NOT NULL;

    -- Step 4.4: Calculate class and stream positions based on total_marks DESC
    -- Temp table for class ranking
    DROP TEMPORARY TABLE IF EXISTS tmp_class_ranking;
    CREATE TEMPORARY TABLE tmp_class_ranking AS
    SELECT 
        stra.id,
        stra.total_marks,
        ROW_NUMBER() OVER (ORDER BY stra.total_marks DESC) AS class_pos
    FROM student_term_results_aggregates stra
    WHERE stra.school_id = p_school_id
      AND stra.term = p_term
      AND stra.class_id = p_class_id
      AND stra.year = v_year;

    -- Temp table for stream ranking (only for records with stream_id)
    DROP TEMPORARY TABLE IF EXISTS tmp_stream_ranking;
    CREATE TEMPORARY TABLE tmp_stream_ranking AS
    SELECT 
        stra.id,
        stra.total_marks,
        ROW_NUMBER() OVER (PARTITION BY stra.stream_id ORDER BY stra.total_marks DESC) AS stream_pos
    FROM student_term_results_aggregates stra
    WHERE stra.school_id = p_school_id
      AND stra.term = p_term
      AND stra.class_id = p_class_id
      AND stra.year = v_year
      AND stra.stream_id IS NOT NULL;

    -- Update class positions
    UPDATE student_term_results_aggregates stra
    JOIN tmp_class_ranking tcr ON stra.id = tcr.id
    SET stra.class_position = tcr.class_pos
    WHERE stra.school_id = p_school_id
      AND stra.term = p_term
      AND stra.class_id = p_class_id
      AND stra.year = v_year;

    -- Update stream positions (only if stream_id is not null)
    UPDATE student_term_results_aggregates stra
    JOIN tmp_stream_ranking tsr ON stra.id = tsr.id
    SET stra.stream_position = tsr.stream_pos
    WHERE stra.school_id = p_school_id
      AND stra.term = p_term
      AND stra.class_id = p_class_id
      AND stra.year = v_year
      AND stra.stream_id IS NOT NULL;

    -- Step 4.5: Update class_total_students and stream_total_students
    -- Update class_total_students (total distinct students in the class for the term/year)
    UPDATE student_term_results_aggregates stra
    SET stra.class_total_students = (
        SELECT COUNT(DISTINCT sub.student_id)
        FROM student_term_results_aggregates sub
        WHERE sub.school_id = stra.school_id
          AND sub.term = stra.term
          AND sub.class_id = stra.class_id
          AND sub.year = stra.year
    )
    WHERE stra.school_id = p_school_id
      AND stra.term = p_term
      AND stra.class_id = p_class_id
      AND stra.year = v_year;

    -- Update stream_total_students (total distinct students in the stream for the term/year, only if stream_id is not null)
    UPDATE student_term_results_aggregates stra
    SET stra.stream_total_students = (
        SELECT COUNT(DISTINCT sub.student_id)
        FROM student_term_results_aggregates sub
        WHERE sub.school_id = stra.school_id
          AND sub.term = stra.term
          AND sub.class_id = stra.class_id
          AND sub.year = stra.year
          AND sub.stream_id = stra.stream_id
    )
    WHERE stra.school_id = p_school_id
      AND stra.term = p_term
      AND stra.class_id = p_class_id
      AND stra.year = v_year
      AND stra.stream_id IS NOT NULL;

    -- Clean up temp tables
    DROP TEMPORARY TABLE IF EXISTS tmp_class_ranking;
    DROP TEMPORARY TABLE IF EXISTS tmp_stream_ranking;

    -- Clean up
    DROP TEMPORARY TABLE IF EXISTS tmp_term_subject_scores;
END$$

DELIMITER ;