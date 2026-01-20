DELIMITER //

DROP PROCEDURE IF EXISTS cbc_generate_exam_aggregates //

CREATE PROCEDURE cbc_generate_exam_aggregates(
    IN p_exam_id       INT,
    IN p_school_id     INT,
    IN p_class_id      INT,
    IN p_stream_id     INT     -- 0 = whole class, >0 = specific stream only
)
BEGIN
    DECLARE v_term             VARCHAR(50);
    DECLARE v_year             YEAR;
    DECLARE v_min_subjects     INT DEFAULT 7;
    DECLARE v_grading_system_id INT;

    -- 1. Get exam metadata
    SELECT 
        term,
        YEAR(created_at),
        COALESCE(min_subjects, 7),
        grading_system_id
    INTO 
        v_term, v_year, v_min_subjects, v_grading_system_id
    FROM exams
    WHERE exam_id = p_exam_id
      AND school_id = p_school_id;

    IF v_grading_system_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Exam not found or missing grading system';
    END IF;

    -- Enforce CBC class (optional but recommended)
    IF NOT EXISTS (
        SELECT 1 FROM classes 
         WHERE class_id = p_class_id 
           AND school_id = p_school_id 
           AND is_cbc = 1
    ) THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Procedure intended for CBC classes only';
    END IF;

    -- 2. Clear previous aggregates for this exam
    DELETE FROM cbc_exam_subject_aggregates 
     WHERE school_id = p_school_id 
       AND exam_id   = p_exam_id;

    DELETE FROM cbc_exam_aggregates 
     WHERE school_id = p_school_id 
       AND exam_id   = p_exam_id;

    -- ───────────────────────────────────────────────────────────────
    -- Subject level aggregates + subject teacher remark (uses subject_score)
    -- ───────────────────────────────────────────────────────────────
    DROP TEMPORARY TABLE IF EXISTS tmp_subject;
    CREATE TEMPORARY TABLE tmp_subject (
        student_id      INT NOT NULL,
        subject_id      INT NOT NULL,
        subject_score   DECIMAL(7,2) DEFAULT 0.00,
        subject_grade   VARCHAR(5),
        subject_points  DECIMAL(5,3),          -- using DECIMAL even though your insert uses INT
        subject_remark  VARCHAR(255),
        PRIMARY KEY (student_id, subject_id)
    );

    -- Step A: calculate subject score
    INSERT INTO tmp_subject (student_id, subject_id, subject_score)
    SELECT
        r.student_id,
        r.subject_id,
        ROUND(
            CASE WHEN es.use_papers = 0
                 THEN COALESCE(MAX(r.score), 0.00)
                 ELSE COALESCE(SUM(r.score * sp.contribution_percentage / sp.max_score), 0.00)
            END, 2
        ) AS subject_score
    FROM results r
    JOIN exam_subjects es ON es.exam_id = r.exam_id AND es.subject_id = r.subject_id
    LEFT JOIN subject_papers sp ON sp.paper_id = r.paper_id
    WHERE r.exam_id   = p_exam_id
      AND r.school_id = p_school_id
      AND EXISTS (
          SELECT 1 FROM students s
          WHERE s.student_id = r.student_id
            AND s.school_id  = p_school_id
            AND s.class_id   = p_class_id
            AND (p_stream_id = 0 OR s.stream_id = p_stream_id)
      )
    GROUP BY r.student_id, r.subject_id;

    -- Step B: assign grade, points and remark using subject_score
    UPDATE tmp_subject t
    SET 
        subject_grade = COALESCE(
            (SELECT gr.grade
             FROM grading_rules gr
             WHERE gr.grading_system_id = v_grading_system_id
               AND gr.is_cbc = 1
               AND t.subject_score BETWEEN gr.min_score AND gr.max_score
             ORDER BY gr.min_score DESC LIMIT 1),
            'BE2'
        ),

        subject_points = COALESCE(
            (SELECT gr.points
             FROM grading_rules gr
             WHERE gr.grading_system_id = v_grading_system_id
               AND gr.is_cbc = 1
               AND t.subject_score BETWEEN gr.min_score AND gr.max_score
             ORDER BY gr.min_score DESC LIMIT 1),
            1
        ),

        subject_remark = COALESCE(
            (SELECT rr.remark_text
             FROM remarks_rules rr
             WHERE rr.is_cbc = 1
               AND rr.category = 'subject_teacher'
               AND t.subject_score BETWEEN rr.min_score AND rr.max_score
             LIMIT 1),
            'Minimal'
        );

    -- Save subject aggregates
    INSERT INTO cbc_exam_subject_aggregates
    (
        school_id, exam_id, student_id, subject_id, subject_name,
        subject_score, subject_grade, subject_points, subject_teacher_remark_text
    )
    SELECT 
        p_school_id, p_exam_id, t.student_id, t.subject_id,
        s.name,
        t.subject_score, t.subject_grade, t.subject_points, t.subject_remark
    FROM tmp_subject t
    JOIN subjects s ON s.subject_id = t.subject_id
    ON DUPLICATE KEY UPDATE
        subject_score              = VALUES(subject_score),
        subject_grade              = VALUES(subject_grade),
        subject_points             = VALUES(subject_points),
        subject_teacher_remark_text = VALUES(subject_teacher_remark_text);

    -- ───────────────────────────────────────────────────────────────
    -- Student level aggregates + class/principal remarks (uses total_points)
    -- ───────────────────────────────────────────────────────────────
    DROP TEMPORARY TABLE IF EXISTS tmp_student;
    CREATE TEMPORARY TABLE tmp_student (
        student_id       INT PRIMARY KEY,
        total_score      DECIMAL(10,2) DEFAULT 0.00,
        total_points     DECIMAL(10,3) DEFAULT 0.000,
        subject_count    INT DEFAULT 0,
        mean_score       DECIMAL(7,2),
        mean_points      DECIMAL(7,3),
        overall_grade    VARCHAR(5),
        ct_remark        VARCHAR(255),
        principal_remark VARCHAR(255)
    );

    INSERT INTO tmp_student
    SELECT
        student_id,
        SUM(subject_score)                                  AS total_score,
        SUM(subject_points)                                 AS total_points,
        COUNT(*)                                            AS subject_count,
        ROUND(SUM(subject_score)     / GREATEST(COUNT(*), v_min_subjects), 2) AS mean_score,
        ROUND(SUM(subject_points)    / GREATEST(COUNT(*), v_min_subjects), 3) AS mean_points,

        -- Overall grade (usually based on mean_points or mean_score - your choice)
        COALESCE(
            (SELECT gr.grade FROM grading_rules gr
             WHERE gr.grading_system_id = v_grading_system_id
               AND gr.is_cbc = 1
               AND ROUND(SUM(subject_points)/GREATEST(COUNT(*), v_min_subjects), 3) 
                   BETWEEN gr.min_score AND gr.max_score
             ORDER BY gr.min_score DESC LIMIT 1),
            'BE2'
        ) AS overall_grade,

        -- Class teacher remark → based on TOTAL points
        COALESCE(
            (SELECT rr.remark_text FROM remarks_rules rr
             WHERE rr.is_cbc = 1
               AND rr.category = 'class_teacher'
               AND FLOOR(SUM(subject_points)) BETWEEN rr.min_score AND rr.max_score
             LIMIT 1),
            'Minimal'
        ) AS ct_remark,

        -- Principal remark → based on TOTAL points
        COALESCE(
            (SELECT rr.remark_text FROM remarks_rules rr
             WHERE rr.is_cbc = 1
               AND rr.category = 'principal'
               AND FLOOR(SUM(subject_points)) BETWEEN rr.min_score AND rr.max_score
             LIMIT 1),
            'Minimal'
        ) AS principal_remark

    FROM tmp_subject
    GROUP BY student_id
    HAVING subject_count > 0;

    -- Save final student aggregates
    INSERT INTO cbc_exam_aggregates
    (
        school_id, exam_id, student_id, student_name, stream_name,
        total_score, total_subjects, mean_score,
        total_points, mean_points, overall_grade,
        class_teacher_remark_text, principal_remark_text
    )
    SELECT
        p_school_id, p_exam_id, ts.student_id, st.full_name,
        COALESCE(str.stream_name, 'No Stream'),
        ts.total_score, ts.subject_count, ts.mean_score,
        ts.total_points, ts.mean_points, ts.overall_grade,
        ts.ct_remark, ts.principal_remark
    FROM tmp_student ts
    JOIN students st ON st.student_id = ts.student_id
    LEFT JOIN streams str ON str.stream_id = st.stream_id
    WHERE st.school_id = p_school_id
      AND st.class_id  = p_class_id
      AND (p_stream_id = 0 OR st.stream_id = p_stream_id)
    ON DUPLICATE KEY UPDATE
        student_name                = VALUES(student_name),
        stream_name                 = VALUES(stream_name),
        total_score                 = VALUES(total_score),
        total_subjects              = VALUES(total_subjects),
        mean_score                  = VALUES(mean_score),
        total_points                = VALUES(total_points),
        mean_points                 = VALUES(mean_points),
        overall_grade               = VALUES(overall_grade),
        class_teacher_remark_text   = VALUES(class_teacher_remark_text),
        principal_remark_text       = VALUES(principal_remark_text);

    -- 7. Class position (whole class)
    UPDATE cbc_exam_aggregates agg
    JOIN (
        SELECT aggregate_id,
               DENSE_RANK() OVER (ORDER BY total_points DESC) AS rnk
        FROM cbc_exam_aggregates
        WHERE school_id = p_school_id 
          AND exam_id   = p_exam_id
    ) rk ON agg.aggregate_id = rk.aggregate_id
    SET agg.class_position = rk.rnk
    WHERE agg.school_id = p_school_id AND agg.exam_id = p_exam_id;

    -- 8. Stream position (only when specific stream requested)
    IF p_stream_id > 0 THEN
        UPDATE cbc_exam_aggregates agg
        JOIN (
            SELECT aggregate_id,
                   DENSE_RANK() OVER (ORDER BY total_points DESC) AS rnk
            FROM cbc_exam_aggregates
            WHERE school_id  = p_school_id 
              AND exam_id    = p_exam_id
              AND stream_name != 'No Stream'
        ) rk ON agg.aggregate_id = rk.aggregate_id
        SET agg.stream_position = rk.rnk
        WHERE agg.school_id = p_school_id 
          AND agg.exam_id   = p_exam_id;
    END IF;

    -- Cleanup
    DROP TEMPORARY TABLE IF EXISTS tmp_subject, tmp_student;

END //

DELIMITER ;