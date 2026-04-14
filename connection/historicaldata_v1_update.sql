DELIMITER $$

DROP PROCEDURE IF EXISTS sp_generate_historical_data$$

CREATE PROCEDURE sp_generate_historical_data(
    IN p_school_id INT
)
BEGIN
    -- Step 1: Temporary table for subject-level scores per exam
    DROP TEMPORARY TABLE IF EXISTS tmp_historical_subject_scores;
    CREATE TEMPORARY TABLE tmp_historical_subject_scores (
        student_id        INT,
        subject_id        INT,
        exam_id           INT,
        class_id          INT,
        stream_id         INT,
        term              VARCHAR(50),
        year              YEAR,
        subject_score     DECIMAL(7,2),
        grading_system_id INT,
        min_subjects      INT
    );

    INSERT INTO tmp_historical_subject_scores 
        (student_id, subject_id, exam_id, class_id, stream_id, term, year, 
         subject_score, grading_system_id, min_subjects)
    SELECT
        r.student_id,
        r.subject_id,
        r.exam_id,
        r.class_id,
        r.stream_id,
        e.term,
        YEAR(e.created_at) AS year,
        CASE
            WHEN es.use_papers = 0 THEN MAX(r.score)                                      -- normal score
            ELSE SUM((r.score / sp.max_score) * sp.contribution_percentage)               -- paper-weighted ONLY when use_papers=1
        END AS subject_score,
        e.grading_system_id,
        e.min_subjects
    FROM results r
    JOIN exam_subjects es ON r.exam_id = es.exam_id AND r.subject_id = es.subject_id
    JOIN exams e ON r.exam_id = e.exam_id
    LEFT JOIN subject_papers sp ON r.subject_id = sp.subject_id AND r.paper_id = sp.paper_id
    WHERE e.school_id = p_school_id
      AND e.status = 'closed'
      AND r.status = 'confirmed'
    GROUP BY r.student_id, r.subject_id, r.exam_id, r.class_id, r.stream_id, 
             e.term, YEAR(e.created_at), es.use_papers, e.grading_system_id, e.min_subjects
    HAVING subject_score > 0;

    -- Step 2: Per-subject term totals & means
    DROP TEMPORARY TABLE IF EXISTS tmp_historical_subject_totals;
    CREATE TEMPORARY TABLE tmp_historical_subject_totals (
        school_id         INT,
        student_id        INT,
        class_id          INT,
        stream_id         INT,
        term              VARCHAR(50),
        year              YEAR,
        subject_id        INT,
        subject_total     DECIMAL(10,2),
        subject_mean      DECIMAL(7,2),
        exam_count        INT,
        grading_system_id INT,
        min_subjects      INT,
        subject_grade     VARCHAR(5),
        subject_points    DECIMAL(7,3)
    );

    INSERT INTO tmp_historical_subject_totals 
        (school_id, student_id, class_id, stream_id, term, year, subject_id, 
         subject_total, subject_mean, exam_count, grading_system_id, min_subjects)
    SELECT
        p_school_id,
        ts.student_id,
        MIN(ts.class_id) AS class_id,
        MIN(ts.stream_id) AS stream_id,
        ts.term,
        ts.year,
        ts.subject_id,
        SUM(ts.subject_score) AS subject_total,
        ROUND(AVG(ts.subject_score), 2) AS subject_mean,
        COUNT(DISTINCT ts.exam_id) AS exam_count,
        MIN(ts.grading_system_id) AS grading_system_id,
        MIN(ts.min_subjects) AS min_subjects
    FROM tmp_historical_subject_scores ts
    GROUP BY ts.student_id, ts.term, ts.year, ts.subject_id;

    -- Step 2.1: Calculate subject_grade
    UPDATE tmp_historical_subject_totals tst
    SET tst.subject_grade = (
        SELECT gr.grade
        FROM grading_rules gr
        WHERE gr.grading_system_id = tst.grading_system_id
          AND FLOOR(tst.subject_mean) BETWEEN gr.min_score AND gr.max_score
        LIMIT 1
    );

    -- Step 2.2: Calculate subject_points (FIXED - same as exam_aggregates)
    UPDATE tmp_historical_subject_totals tst
    SET tst.subject_points = COALESCE(
        (SELECT gr.points
         FROM grading_rules gr
         WHERE gr.grading_system_id = tst.grading_system_id
           AND FLOOR(tst.subject_mean) BETWEEN gr.min_score AND gr.max_score
         ORDER BY gr.min_score DESC
         LIMIT 1),
        1
    );

    -- Step 3: Student-level term aggregates
    DROP TEMPORARY TABLE IF EXISTS tmp_historical_student_term;
    CREATE TEMPORARY TABLE tmp_historical_student_term (
        school_id       INT,
        student_id      INT,
        class_id        INT,
        stream_id       INT,
        term            VARCHAR(50),
        year            YEAR,
        total_marks     DECIMAL(10,2),
        average         DECIMAL(7,2),
        total_points    DECIMAL(7,3),
        min_subjects    INT,
        grading_system_id INT
    );

    INSERT INTO tmp_historical_student_term 
        (school_id, student_id, class_id, stream_id, term, year, total_marks, 
         average, total_points, min_subjects, grading_system_id)
    SELECT
        tst.school_id,
        tst.student_id,
        MIN(tst.class_id) AS class_id,
        MIN(tst.stream_id) AS stream_id,
        tst.term,
        tst.year,
        SUM(tst.subject_mean) AS total_marks,
        ROUND(SUM(tst.subject_mean) / MIN(tst.min_subjects), 2) AS average,
        ROUND(SUM(COALESCE(tst.subject_points, 0)) / MIN(tst.min_subjects), 3) AS total_points,
        MIN(tst.min_subjects) AS min_subjects,
        MIN(tst.grading_system_id) AS grading_system_id
    FROM tmp_historical_subject_totals tst
    GROUP BY tst.school_id, tst.student_id, tst.term, tst.year
    HAVING SUM(tst.subject_mean) > 0;

    -- Step 4: Insert/Update historical data
    INSERT INTO student_termly_historical_data 
        (school_id, student_id, class_id, stream_id, term, year,
         total_marks, average, total_points, min_subjects, grading_system_id)
    SELECT
        school_id, student_id, class_id, stream_id, term, year,
        total_marks, average, total_points, min_subjects, grading_system_id
    FROM tmp_historical_student_term
    ON DUPLICATE KEY UPDATE
        total_marks = VALUES(total_marks),
        average = VALUES(average),
        total_points = VALUES(total_points),
        min_subjects = VALUES(min_subjects),
        grading_system_id = VALUES(grading_system_id),
        class_id = VALUES(class_id),
        stream_id = VALUES(stream_id);

    -- Step 5: Calculate positions (stable ranking)
    DROP TEMPORARY TABLE IF EXISTS tmp_historical_ranking;
    CREATE TEMPORARY TABLE tmp_historical_ranking AS
    SELECT
        school_id,
        student_id,
        term,
        year,
        class_id,
        stream_id,
        total_points,
        ROW_NUMBER() OVER (
            PARTITION BY school_id, term, year, class_id
            ORDER BY total_points DESC, student_id ASC
        ) AS class_position,
        ROW_NUMBER() OVER (
            PARTITION BY school_id, term, year, class_id, COALESCE(stream_id, 0)
            ORDER BY total_points DESC, student_id ASC
        ) AS stream_position
    FROM student_termly_historical_data
    WHERE school_id = p_school_id;

    -- Update positions
    UPDATE student_termly_historical_data h
    JOIN tmp_historical_ranking r
        ON h.school_id = r.school_id
       AND h.student_id = r.student_id
       AND h.term = r.term
       AND h.year = r.year
       AND h.class_id = r.class_id
    SET
        h.class_position = r.class_position,
        h.stream_position = r.stream_position
    WHERE h.school_id = p_school_id;

    -- Cleanup
    DROP TEMPORARY TABLE IF EXISTS tmp_historical_subject_scores;
    DROP TEMPORARY TABLE IF EXISTS tmp_historical_subject_totals;
    DROP TEMPORARY TABLE IF EXISTS tmp_historical_student_term;
    DROP TEMPORARY TABLE IF EXISTS tmp_historical_ranking;

END$$

DELIMITER ;