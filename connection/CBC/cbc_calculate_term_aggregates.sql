DELIMITER //

DROP PROCEDURE IF EXISTS cbc_calculate_term_aggregates //

CREATE PROCEDURE cbc_calculate_term_aggregates(
    IN p_school_id   INT,
    IN p_class_id    INT,
    IN p_term        VARCHAR(50),
    IN p_year        YEAR,
    IN p_stream_id   INT          -- 0 = all streams, >0 = specific
)
BEGIN
    DECLARE v_done            BOOLEAN DEFAULT FALSE;
    DECLARE v_student_id      INT;
    DECLARE v_subject_id      INT;
    DECLARE v_subject_mean    DECIMAL(7,2);
    DECLARE v_subject_grade   VARCHAR(5);
    DECLARE v_subject_points  DECIMAL(5,3);
    DECLARE v_exam_count      INT;
    DECLARE v_total_marks     DECIMAL(10,2) DEFAULT 0;
    DECLARE v_subject_count   INT DEFAULT 0;
    DECLARE v_total_points    DECIMAL(10,3) DEFAULT 0.000;
    DECLARE v_mean_score      DECIMAL(7,2);
    DECLARE v_mean_points     DECIMAL(7,3);
    DECLARE v_overall_grade   VARCHAR(5);
    DECLARE v_class_remark    VARCHAR(255);
    DECLARE v_principal_remark VARCHAR(255);

    -- Cursor: students in class/stream
    DECLARE cur_students CURSOR FOR
        SELECT s.student_id
        FROM students s
        WHERE s.school_id = p_school_id
          AND s.class_id  = p_class_id
          AND (p_stream_id = 0 OR s.stream_id = p_stream_id);

    -- Cursor: exams in this term/year/class
    DECLARE cur_exams CURSOR FOR
        SELECT exam_id
        FROM exams
        WHERE school_id = p_school_id
          AND class_id  = p_class_id
          AND term      = p_term
          AND YEAR(created_at) = p_year
          AND status    = 'closed';

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = TRUE;

    -- Clear old aggregates for this term
    DELETE FROM cbc_term_subject_aggregates 
     WHERE school_id = p_school_id 
       AND term = p_term 
       AND year = p_year
       AND class_id = p_class_id
       AND (p_stream_id = 0 OR stream_id = p_stream_id);

    DELETE FROM cbc_term_aggregates 
     WHERE school_id = p_school_id 
       AND term = p_term 
       AND year = p_year
       AND class_id = p_class_id
       AND (p_stream_id = 0 OR stream_id = p_stream_id);

    OPEN cur_students;

    student_loop: LOOP
        FETCH cur_students INTO v_student_id;
        IF v_done THEN LEAVE student_loop; END IF;

        SET v_done = FALSE;
        SET v_total_marks   = 0;
        SET v_subject_count = 0;
        SET v_total_points  = 0.000;

        -- For each subject the student has results in this term
        INSERT INTO cbc_term_subject_aggregates (
            school_id, student_id, class_id, stream_id, subject_id,
            term, year, exam_count, subject_total, subject_mean,
            subject_grade, subject_points, subject_teacher_remark_text
        )
        SELECT 
            p_school_id, v_student_id, p_class_id, s.stream_id, r.subject_id,
            p_term, p_year,
            COUNT(DISTINCT r.exam_id) AS exam_count,
            SUM(r.score) AS subject_total,
            AVG(r.score) AS subject_mean,
            NULL, NULL, NULL   -- will be updated later
        FROM results r
        JOIN students s ON r.student_id = s.student_id
        WHERE r.school_id = p_school_id
          AND r.student_id = v_student_id
          AND EXISTS (
              SELECT 1 FROM exams e 
              WHERE e.exam_id = r.exam_id 
                AND e.term = p_term 
                AND YEAR(e.created_at) = p_year
          )
        GROUP BY r.subject_id
        HAVING exam_count > 0;

        -- Now update grade & points for each inserted subject row
        UPDATE cbc_term_subject_aggregates tsa
        JOIN grading_rules gr ON 
            gr.grading_system_id = (SELECT grading_system_id FROM exams LIMIT 1)  -- assume same system
            AND tsa.subject_mean BETWEEN gr.min_score AND gr.max_score
        SET tsa.subject_grade  = gr.grade,
            tsa.subject_points = gr.points,
            tsa.subject_teacher_remark_text = (
                SELECT remark_text FROM remarks_rules 
                WHERE is_cbc = 1 AND category = 'subject_teacher'
                  AND tsa.subject_mean BETWEEN min_score AND max_score
                LIMIT 1
            )
        WHERE tsa.school_id = p_school_id
          AND tsa.student_id = v_student_id
          AND tsa.term = p_term
          AND tsa.year = p_year;

        -- Now aggregate per student
        SELECT 
            COUNT(*),
            SUM(subject_mean),
            SUM(subject_points)
        INTO 
            v_subject_count,
            v_total_marks,
            v_total_points
        FROM cbc_term_subject_aggregates
        WHERE school_id = p_school_id
          AND student_id = v_student_id
          AND term = p_term
          AND year = p_year;

        IF v_subject_count > 0 THEN
            SET v_mean_score  = v_total_marks / v_subject_count;
            SET v_mean_points = v_total_points / v_subject_count;

            -- Overall grade (principal category)
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

            -- Save student term aggregate
            INSERT INTO cbc_term_aggregates (
                school_id, student_id, class_id, stream_id,
                term, year, subject_count, total_marks, mean_score,
                total_points, mean_points, overall_grade,
                class_teacher_remark_text, principal_remark_text
            ) VALUES (
                p_school_id, v_student_id, p_class_id, 
                (SELECT stream_id FROM students WHERE student_id = v_student_id),
                p_term, p_year, v_subject_count, v_total_marks, v_mean_score,
                v_total_points, v_mean_points, v_overall_grade,
                v_class_remark, v_principal_remark
            )
            ON DUPLICATE KEY UPDATE
                subject_count              = v_subject_count,
                total_marks                = v_total_marks,
                mean_score                 = v_mean_score,
                total_points               = v_total_points,
                mean_points                = v_mean_points,
                overall_grade              = v_overall_grade,
                class_teacher_remark_text  = v_class_remark,
                principal_remark_text      = v_principal_remark;
        END IF;

        SET v_done = FALSE;

    END LOOP student_loop;

    CLOSE cur_students;

END //

DELIMITER ;