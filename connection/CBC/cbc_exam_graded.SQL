DELIMITER //

DROP PROCEDURE IF EXISTS cbc_exam_graded //

CREATE PROCEDURE cbc_exam_graded(
    IN p_school_id  INT,
    IN p_class_id   INT,
    IN p_exam_id    INT,
    IN p_stream_id  INT     -- 0 = all streams, >0 = specific stream
)
BEGIN
    -- === 1. Variables first ===
    DECLARE v_done              BOOLEAN DEFAULT FALSE;
    DECLARE v_student_id        INT;
    DECLARE v_full_name         VARCHAR(150);
    DECLARE v_stream_name       VARCHAR(100);
    DECLARE v_subject_id        INT;
    DECLARE v_subject_name      VARCHAR(100);
    DECLARE v_subject_score     DECIMAL(7,2);
    DECLARE v_subject_grade     VARCHAR(5);
    DECLARE v_subject_points    INT;
    DECLARE v_subject_remark    VARCHAR(255);
    DECLARE v_total_score       DECIMAL(7,2) DEFAULT 0.00;
    DECLARE v_total_subjects    INT DEFAULT 0;
    DECLARE v_mean_score        DECIMAL(5,2);
    DECLARE v_total_points      DECIMAL(7,3) DEFAULT 0.000;
    DECLARE v_mean_points       DECIMAL(5,3);
    DECLARE v_overall_grade     VARCHAR(5);
    DECLARE v_class_remark      VARCHAR(255);
    DECLARE v_principal_remark  VARCHAR(255);

    -- === 2. Cursors next ===
    DECLARE cur_students CURSOR FOR
        SELECT 
            s.student_id,
            s.full_name,
            COALESCE(str.stream_name, 'No Stream') AS stream_name
        FROM students s
        LEFT JOIN streams str ON s.stream_id = str.stream_id
        WHERE s.school_id = p_school_id
          AND s.class_id  = p_class_id
          AND (p_stream_id = 0 OR s.stream_id = p_stream_id)
        ORDER BY s.full_name;

    DECLARE cur_subjects CURSOR FOR
        SELECT 
            es.subject_id,
            s.name AS subject_name
        FROM exam_subjects es
        JOIN subjects s ON es.subject_id = s.subject_id
        WHERE es.exam_id = p_exam_id;

    -- === 3. Handlers last ===
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = TRUE;

    -- =================================================================
    -- Main logic starts here
    -- =================================================================

    -- Clear old data (safe to re-run)
    DELETE FROM cbc_exam_subject_aggregates 
     WHERE school_id = p_school_id AND exam_id = p_exam_id;

    DELETE FROM cbc_exam_aggregates 
     WHERE school_id = p_school_id AND exam_id = p_exam_id;

    OPEN cur_students;

    student_loop: LOOP
        FETCH cur_students INTO v_student_id, v_full_name, v_stream_name;
        IF v_done THEN
            LEAVE student_loop;
        END IF;

        SET v_total_score    = 0.00;
        SET v_total_subjects = 0;
        SET v_total_points   = 0.000;
        SET v_done           = FALSE;           -- Reset for inner loop

        OPEN cur_subjects;

        subject_loop: LOOP
            FETCH cur_subjects INTO v_subject_id, v_subject_name;
            IF v_done THEN
                LEAVE subject_loop;
            END IF;

            -- Get average score for this subject (handles multi-paper)
            SELECT COALESCE(AVG(r.score), 0.00) INTO v_subject_score
              FROM results r
             WHERE r.school_id  = p_school_id
               AND r.exam_id    = p_exam_id
               AND r.student_id = v_student_id
               AND r.subject_id = v_subject_id;

            -- Subject grade & remark (CBC)
            SELECT grade, remark_text 
              INTO v_subject_grade, v_subject_remark
              FROM remarks_rules
             WHERE is_cbc = 1
               AND category = 'subject_teacher'
               AND v_subject_score BETWEEN min_score AND max_score
             LIMIT 1;

            IF v_subject_grade IS NULL THEN
                SET v_subject_grade  = 'BE2';
                SET v_subject_remark = 'Minimal';
            END IF;

            -- Points mapping
            SET v_subject_points = CASE v_subject_grade
                WHEN 'EE1' THEN 8 WHEN 'EE2' THEN 7
                WHEN 'ME1' THEN 6 WHEN 'ME2' THEN 5
                WHEN 'AE1' THEN 4 WHEN 'AE2' THEN 3
                WHEN 'BE1' THEN 2 WHEN 'BE2' THEN 1
                ELSE 1
            END;

            -- Save subject result
            INSERT INTO cbc_exam_subject_aggregates (
                school_id, exam_id, student_id, subject_id, subject_name,
                subject_score, subject_grade, subject_points,
                subject_teacher_remark_text, created_at
            ) VALUES (
                p_school_id, p_exam_id, v_student_id, v_subject_id, v_subject_name,
                v_subject_score, v_subject_grade, v_subject_points,
                v_subject_remark, NOW()
            )
            ON DUPLICATE KEY UPDATE
                subject_score              = VALUES(subject_score),
                subject_grade              = VALUES(subject_grade),
                subject_points             = VALUES(subject_points),
                subject_teacher_remark_text = VALUES(subject_teacher_remark_text);

            -- Accumulate
            SET v_total_score    = v_total_score    + v_subject_score;
            SET v_total_subjects = v_total_subjects + 1;
            SET v_total_points   = v_total_points   + v_subject_points;

        END LOOP subject_loop;

        CLOSE cur_subjects;

        -- Overall calculation only if student has subjects
        IF v_total_subjects > 0 THEN
            SET v_mean_score  = v_total_score  / v_total_subjects;
            SET v_mean_points = v_total_points / v_total_subjects;

            -- Overall grade
            SELECT grade INTO v_overall_grade
              FROM remarks_rules
             WHERE is_cbc = 1 AND category = 'principal'
               AND v_mean_score BETWEEN min_score AND max_score
             LIMIT 1;

            IF v_overall_grade IS NULL THEN
                SET v_overall_grade = 'BE2';
            END IF;

            -- Remarks
            SELECT remark_text INTO v_class_remark
              FROM remarks_rules
             WHERE is_cbc = 1 AND category = 'class_teacher'
               AND v_mean_score BETWEEN min_score AND max_score
             LIMIT 1;

            SELECT remark_text INTO v_principal_remark
              FROM remarks_rules
             WHERE is_cbc = 1 AND category = 'principal'
               AND v_mean_score BETWEEN min_score AND max_score
             LIMIT 1;

            -- Save overall aggregate
            INSERT INTO cbc_exam_aggregates (
                school_id, exam_id, student_id, student_name, stream_name,
                total_score, total_subjects, mean_score,
                total_points, mean_points, overall_grade,
                class_teacher_remark_text, principal_remark_text,
                created_at
            ) VALUES (
                p_school_id, p_exam_id, v_student_id, v_full_name, v_stream_name,
                v_total_score, v_total_subjects, v_mean_score,
                v_total_points, v_mean_points, v_overall_grade,
                v_class_remark, v_principal_remark, NOW()
            )
            ON DUPLICATE KEY UPDATE
                student_name               = VALUES(student_name),
                stream_name                = VALUES(stream_name),
                total_score                = VALUES(total_score),
                total_subjects             = VALUES(total_subjects),
                mean_score                 = VALUES(mean_score),
                total_points               = VALUES(total_points),
                mean_points                = VALUES(mean_points),
                overall_grade              = VALUES(overall_grade),
                class_teacher_remark_text  = VALUES(class_teacher_remark_text),
                principal_remark_text      = VALUES(principal_remark_text);
        END IF;

        SET v_done = FALSE;     -- Reset for next student

    END LOOP student_loop;

    CLOSE cur_students;

    -- Class position (all students in the exam/class context)
    UPDATE cbc_exam_aggregates ea
    JOIN (
        SELECT aggregate_id,
               DENSE_RANK() OVER (ORDER BY mean_points DESC) AS pos
          FROM cbc_exam_aggregates
         WHERE school_id = p_school_id 
           AND exam_id   = p_exam_id
    ) r ON ea.aggregate_id = r.aggregate_id
    SET ea.class_position = r.pos
    WHERE ea.school_id = p_school_id 
      AND ea.exam_id   = p_exam_id;

    -- Stream position (only when specific stream selected)
    IF p_stream_id > 0 THEN
        UPDATE cbc_exam_aggregates ea
        JOIN (
            SELECT aggregate_id,
                   DENSE_RANK() OVER (ORDER BY mean_points DESC) AS pos
              FROM cbc_exam_aggregates
             WHERE school_id  = p_school_id 
               AND exam_id    = p_exam_id
               AND stream_name != 'No Stream'
        ) r ON ea.aggregate_id = r.aggregate_id
        SET ea.stream_position = r.pos
        WHERE ea.school_id = p_school_id 
          AND ea.exam_id   = p_exam_id;
    END IF;

END //

DELIMITER ;