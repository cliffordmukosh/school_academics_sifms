DELIMITER //

DROP PROCEDURE IF EXISTS cbc_generate_exam_aggregates //

CREATE PROCEDURE cbc_generate_exam_aggregates(
    IN p_exam_id       INT,
    IN p_school_id     INT,
    IN p_class_id      INT,
    IN p_stream_id     INT     -- 0 = all streams, >0 = specific stream
)
BEGIN
    DECLARE v_term          VARCHAR(50);
    DECLARE v_year          YEAR;
    DECLARE v_min_subjects  INT DEFAULT 7;
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

    IF v_term IS NULL OR v_grading_system_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Exam not found or missing grading system';
    END IF;

    -- Optional early exit if class is not CBC
    IF NOT EXISTS (
        SELECT 1 FROM classes 
        WHERE class_id = p_class_id 
          AND school_id = p_school_id 
          AND is_cbc = 1
    ) THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'This procedure is for CBC classes only (is_cbc = 1)';
    END IF;

    -- 2. Clear previous aggregates
    DELETE FROM cbc_exam_subject_aggregates
     WHERE school_id = p_school_id 
       AND exam_id   = p_exam_id;

    DELETE FROM cbc_exam_aggregates
     WHERE school_id = p_school_id 
       AND exam_id   = p_exam_id;

    -- 3. Temporary table for subject-level results
    DROP TEMPORARY TABLE IF EXISTS tmp_cbc_subject;
    CREATE TEMPORARY TABLE tmp_cbc_subject (
        student_id      INT,
        subject_id      INT,
        subject_score   DECIMAL(7,2),
        subject_grade   VARCHAR(5),
        subject_points  INT,
        remark_text     VARCHAR(255),
        PRIMARY KEY (student_id, subject_id)
    );

    -- Fixed INSERT: repeat score expression instead of using alias in subquery
    INSERT INTO tmp_cbc_subject
    SELECT
        r.student_id,
        r.subject_id,
        
        -- Final subject score
        ROUND(
            CASE WHEN es.use_papers = 0 
                 THEN COALESCE(MAX(r.score), 0.00)
                 ELSE COALESCE(SUM((r.score / sp.max_score) * sp.contribution_percentage), 0.00)
            END, 2
        ) AS subject_score,

        -- Grade lookup
        COALESCE(
            (SELECT gr.grade
             FROM grading_rules gr
             WHERE gr.grading_system_id = v_grading_system_id
               AND gr.is_cbc = 1
               AND ROUND(
                   CASE WHEN es.use_papers = 0 THEN COALESCE(MAX(r.score), 0.00)
                        ELSE COALESCE(SUM((r.score / sp.max_score) * sp.contribution_percentage), 0.00)
                   END, 2
               ) BETWEEN gr.min_score AND gr.max_score
             ORDER BY gr.min_score DESC
             LIMIT 1),
            'BE2'
        ) AS subject_grade,

        -- Points lookup
        COALESCE(
            (SELECT gr.points
             FROM grading_rules gr
             WHERE gr.grading_system_id = v_grading_system_id
               AND gr.is_cbc = 1
               AND ROUND(
                   CASE WHEN es.use_papers = 0 THEN COALESCE(MAX(r.score), 0.00)
                        ELSE COALESCE(SUM((r.score / sp.max_score) * sp.contribution_percentage), 0.00)
                   END, 2
               ) BETWEEN gr.min_score AND gr.max_score
             ORDER BY gr.min_score DESC
             LIMIT 1),
            1
        ) AS subject_points,

        -- Subject teacher remark – FIXED: use full expression instead of alias
        COALESCE(
            (SELECT rr.remark_text
             FROM remarks_rules rr
             WHERE rr.is_cbc = 1
               AND rr.category = 'subject_teacher'
               AND ROUND(
                   CASE WHEN es.use_papers = 0 THEN COALESCE(MAX(r.score), 0.00)
                        ELSE COALESCE(SUM((r.score / sp.max_score) * sp.contribution_percentage), 0.00)
                   END, 2
               ) BETWEEN rr.min_score AND rr.max_score
             LIMIT 1),
            'Minimal'
        ) AS remark_text

    FROM results r
    JOIN exam_subjects es ON es.exam_id = r.exam_id AND es.subject_id = r.subject_id
    LEFT JOIN subject_papers sp ON sp.paper_id = r.paper_id

    WHERE r.exam_id    = p_exam_id
      AND r.school_id  = p_school_id
      AND EXISTS (
          SELECT 1 FROM students s
          JOIN classes c ON c.class_id = s.class_id
          WHERE s.student_id = r.student_id
            AND s.school_id  = p_school_id
            AND s.class_id   = p_class_id
            AND c.is_cbc     = 1
            AND (p_stream_id = 0 OR s.stream_id = p_stream_id)
      )
    GROUP BY r.student_id, r.subject_id;

    -- 4. Save subject aggregates
    INSERT INTO cbc_exam_subject_aggregates (
        school_id, exam_id, student_id, subject_id, subject_name,
        subject_score, subject_grade, subject_points,
        subject_teacher_remark_text, created_at
    )
    SELECT 
        p_school_id, p_exam_id, ts.student_id, ts.subject_id,
        sub.name,
        ts.subject_score, ts.subject_grade, ts.subject_points,
        ts.remark_text, NOW()
    FROM tmp_cbc_subject ts
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    ON DUPLICATE KEY UPDATE
        subject_score              = VALUES(subject_score),
        subject_grade              = VALUES(subject_grade),
        subject_points             = VALUES(subject_points),
        subject_teacher_remark_text = VALUES(subject_teacher_remark_text);

    -- 5. Student-level aggregates
    DROP TEMPORARY TABLE IF EXISTS tmp_cbc_student;
    CREATE TEMPORARY TABLE tmp_cbc_student (
        student_id     INT PRIMARY KEY,
        total_score    DECIMAL(10,2),
        total_points   DECIMAL(10,3),
        subject_count  INT,
        mean_score     DECIMAL(7,2),
        mean_points    DECIMAL(7,3),
        overall_grade  VARCHAR(5),
        class_remark   VARCHAR(255),
        principal_remark VARCHAR(255)
    );

    INSERT INTO tmp_cbc_student
    SELECT
        ts.student_id,
        SUM(ts.subject_score)                           AS total_score,
        SUM(ts.subject_points)                          AS total_points,
        COUNT(DISTINCT ts.subject_id)                   AS subject_count,
        ROUND(SUM(ts.subject_score) / GREATEST(COUNT(DISTINCT ts.subject_id), v_min_subjects), 2) AS mean_score,
        ROUND(SUM(ts.subject_points) / GREATEST(COUNT(DISTINCT ts.subject_id), v_min_subjects), 3) AS mean_points,
        
        COALESCE(
            (SELECT gr.grade
             FROM grading_rules gr
             WHERE gr.grading_system_id = v_grading_system_id
               AND gr.is_cbc = 1
               AND ROUND(SUM(ts.subject_points) / GREATEST(COUNT(DISTINCT ts.subject_id), v_min_subjects), 3)
                   BETWEEN gr.min_score AND gr.max_score
             ORDER BY gr.min_score DESC
             LIMIT 1),
            'BE2'
        ) AS overall_grade,

        COALESCE(
            (SELECT rr.remark_text
             FROM remarks_rules rr
             WHERE rr.is_cbc = 1 
               AND rr.category = 'class_teacher'
               AND ROUND(SUM(ts.subject_points) / GREATEST(COUNT(DISTINCT ts.subject_id), v_min_subjects), 3)
                   BETWEEN rr.min_score AND rr.max_score
             LIMIT 1),
            'Minimal'
        ) AS class_remark,

        COALESCE(
            (SELECT rr.remark_text
             FROM remarks_rules rr
             WHERE rr.is_cbc = 1 
               AND rr.category = 'principal'
               AND ROUND(SUM(ts.subject_points) / GREATEST(COUNT(DISTINCT ts.subject_id), v_min_subjects), 3)
                   BETWEEN rr.min_score AND rr.max_score
             LIMIT 1),
            'Minimal'
        ) AS principal_remark

    FROM tmp_cbc_subject ts
    GROUP BY ts.student_id
    HAVING subject_count > 0;

    -- 6. Save final student aggregates
    INSERT INTO cbc_exam_aggregates (
        school_id, exam_id, student_id, student_name, stream_name,
        total_score, total_subjects, mean_score,
        total_points, mean_points, overall_grade,
        class_teacher_remark_text, principal_remark_text,
        created_at
    )
    SELECT
        p_school_id, p_exam_id, s.student_id, s.full_name,
        COALESCE(str.stream_name, 'No Stream'),
        tcs.total_score, tcs.subject_count, tcs.mean_score,
        tcs.total_points, tcs.mean_points, tcs.overall_grade,
        tcs.class_remark, tcs.principal_remark, NOW()
    FROM tmp_cbc_student tcs
    JOIN students s ON s.student_id = tcs.student_id
    LEFT JOIN streams str ON str.stream_id = s.stream_id
    WHERE s.school_id = p_school_id
      AND s.class_id  = p_class_id
      AND (p_stream_id = 0 OR s.stream_id = p_stream_id)
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

    -- 7. Class position
    UPDATE cbc_exam_aggregates ea
    JOIN (
        SELECT aggregate_id,
               DENSE_RANK() OVER (ORDER BY mean_points DESC) AS pos
        FROM cbc_exam_aggregates
        WHERE school_id = p_school_id AND exam_id = p_exam_id
    ) r ON ea.aggregate_id = r.aggregate_id
    SET ea.class_position = r.pos
    WHERE ea.school_id = p_school_id AND ea.exam_id = p_exam_id;

    -- 8. Stream position (only if specific stream)
    IF p_stream_id > 0 THEN
        UPDATE cbc_exam_aggregates ea
        JOIN (
            SELECT aggregate_id,
                   DENSE_RANK() OVER (ORDER BY mean_points DESC) AS pos
            FROM cbc_exam_aggregates
            WHERE school_id = p_school_id 
              AND exam_id   = p_exam_id
              AND stream_name != 'No Stream'
        ) r ON ea.aggregate_id = r.aggregate_id
        SET ea.stream_position = r.pos
        WHERE ea.school_id = p_school_id AND ea.exam_id = p_exam_id;
    END IF;

    -- Cleanup
    DROP TEMPORARY TABLE IF EXISTS tmp_cbc_subject, tmp_cbc_student;

END //

DELIMITER ;