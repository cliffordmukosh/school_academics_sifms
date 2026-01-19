DELIMITER $$

CREATE PROCEDURE sp_generate_exam_aggregates(IN p_exam_id INT, IN p_school_id INT)
BEGIN
    DECLARE v_term VARCHAR(50);
    DECLARE v_year YEAR;
    DECLARE v_min_subjects INT;

    -- Fetch term, year, and min_subjects from exam
    SELECT term, YEAR(created_at), min_subjects
    INTO v_term, v_year, v_min_subjects
    FROM exams
    WHERE exam_id = p_exam_id AND school_id = p_school_id;

    -- Check if exam exists
    IF v_term IS NULL OR v_year IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'No exam found for the given exam_id and school_id';
    END IF;

    -- Validate min_subjects
    IF v_min_subjects IS NULL OR v_min_subjects <= 0 THEN
        SET v_min_subjects = 1; -- Default to 1 to avoid division by zero
    END IF;

    -- Step 1: Temporary table for subject-level scores, grades, points, and remarks
    DROP TEMPORARY TABLE IF EXISTS tmp_subject_scores;
    CREATE TEMPORARY TABLE tmp_subject_scores (
        student_id INT,
        subject_id INT,
        subject_score DECIMAL(7,2),
        subject_grade VARCHAR(5),
        subject_points INT,
        subject_remark_text VARCHAR(255)
    );

    INSERT INTO tmp_subject_scores (student_id, subject_id, subject_score, subject_grade, subject_points, subject_remark_text)
    SELECT 
        r.student_id,
        r.subject_id,
        CASE 
            WHEN es.use_papers = 0 THEN MAX(r.score)
            ELSE SUM((r.score / sp.max_score) * sp.contribution_percentage)
        END AS subject_score,
        COALESCE(
            (
                SELECT gr.grade 
                FROM grading_rules gr
                JOIN exams e ON e.grading_system_id = gr.grading_system_id
                WHERE e.exam_id = p_exam_id
                AND ROUND(
                    CASE 
                        WHEN es.use_papers = 0 THEN MAX(r.score)
                        ELSE SUM((r.score / sp.max_score) * sp.contribution_percentage)
                    END
                ) BETWEEN gr.min_score AND gr.max_score
                ORDER BY gr.min_score DESC
                LIMIT 1
            ),
            'E'  -- Default to 'E' for out-of-range scores
        ) AS subject_grade,
        COALESCE(
            (
                SELECT gr.points
                FROM grading_rules gr
                JOIN exams e ON e.grading_system_id = gr.grading_system_id
                WHERE e.exam_id = p_exam_id
                AND ROUND(
                    CASE 
                        WHEN es.use_papers = 0 THEN MAX(r.score)
                        ELSE SUM((r.score / sp.max_score) * sp.contribution_percentage)
                    END
                ) BETWEEN gr.min_score AND gr.max_score
                ORDER BY gr.min_score DESC
                LIMIT 1
            ),
            1  -- Default points for 'E' grade
        ) AS subject_points,
        COALESCE(
            (
                SELECT rr.remark_text
                FROM remarks_rules rr
                WHERE rr.category = 'subject_teacher'
                AND (
                    CASE 
                        WHEN es.use_papers = 0 THEN MAX(r.score)
                        ELSE SUM((r.score / sp.max_score) * sp.contribution_percentage)
                    END
                ) >= rr.min_score AND (
                    CASE 
                        WHEN es.use_papers = 0 THEN MAX(r.score)
                        ELSE SUM((r.score / sp.max_score) * sp.contribution_percentage)
                    END
                ) <= rr.max_score
                AND (rr.school_id = p_school_id OR rr.school_id IS NULL)
                AND (rr.class_id = es.class_id OR rr.class_id IS NULL)
                AND (rr.stream_id IS NULL)
                AND (rr.subject_id = r.subject_id OR rr.subject_id IS NULL)
                ORDER BY rr.subject_id DESC, rr.class_id DESC, rr.school_id DESC
                LIMIT 1
            ),
            'No subject remark available'
        ) AS subject_remark_text
    FROM results r
    JOIN exam_subjects es 
        ON r.exam_id = es.exam_id AND r.subject_id = es.subject_id
    LEFT JOIN subject_papers sp 
        ON r.subject_id = sp.subject_id AND r.paper_id = sp.paper_id
    WHERE r.exam_id = p_exam_id
      AND r.school_id = p_school_id
    GROUP BY r.student_id, r.subject_id, es.use_papers;

    -- Step 2: Insert subject-level aggregates into exam_subject_aggregates
    INSERT INTO exam_subject_aggregates (
        school_id, exam_id, student_id, subject_id, subject_score, subject_grade, remark_text
    )
    SELECT 
        p_school_id,
        p_exam_id,
        ts.student_id,
        ts.subject_id,
        ts.subject_score,
        ts.subject_grade,
        ts.subject_remark_text
    FROM tmp_subject_scores ts
    ON DUPLICATE KEY UPDATE
        subject_score = VALUES(subject_score),
        subject_grade = VALUES(subject_grade),
        remark_text = VALUES(remark_text);

    -- Step 3: Aggregate per student
    DROP TEMPORARY TABLE IF EXISTS tmp_student_aggregates;
    CREATE TEMPORARY TABLE tmp_student_aggregates (
        student_id INT,
        total_score DECIMAL(7,2),
        mean_score DECIMAL(5,2),
        mean_grade VARCHAR(5),
        total_points DECIMAL(7,3),
        remark_text VARCHAR(255),
        class_teacher_remark VARCHAR(255),
        subject_count INT
    );

    INSERT INTO tmp_student_aggregates (student_id, total_score, mean_score, mean_grade, total_points, remark_text, class_teacher_remark, subject_count)
    SELECT 
        ts.student_id,
        SUM(ts.subject_score) AS total_score,
        ROUND(SUM(ts.subject_score) / GREATEST(COUNT(ts.subject_id), v_min_subjects), 2) AS mean_score,
        COALESCE(
            (
                SELECT gr.grade
                FROM grading_rules gr
                JOIN exams e ON e.grading_system_id = gr.grading_system_id
                WHERE e.exam_id = p_exam_id
                AND ROUND(ROUND(SUM(ts.subject_score) / GREATEST(COUNT(ts.subject_id), v_min_subjects), 2)) BETWEEN gr.min_score AND gr.max_score
                ORDER BY gr.min_score DESC
                LIMIT 1
            ),
            'E'  -- Default to 'E' for out-of-range scores
        ) AS mean_grade,
        ROUND(SUM(ts.subject_points) / GREATEST(COUNT(ts.subject_id), v_min_subjects), 3) AS total_points,
        COALESCE(
            (
                SELECT rr.remark_text
                FROM remarks_rules rr
                WHERE rr.category = 'principal'
                AND rr.grade = (
                    SELECT gr.grade
                    FROM grading_rules gr
                    JOIN exams e ON e.grading_system_id = gr.grading_system_id
                    WHERE e.exam_id = p_exam_id
                    AND ROUND(ROUND(SUM(ts.subject_score) / GREATEST(COUNT(ts.subject_id), v_min_subjects), 2)) BETWEEN gr.min_score AND gr.max_score
                    ORDER BY gr.min_score DESC
                    LIMIT 1
                )
                AND (rr.school_id = p_school_id OR rr.school_id IS NULL)
                AND rr.class_id IS NULL
                AND rr.stream_id IS NULL
                AND rr.subject_id IS NULL
                ORDER BY rr.school_id DESC
                LIMIT 1
            ),
            'No principal remark available'
        ) AS remark_text,
        COALESCE(
            (
                SELECT rr.remark_text
                FROM remarks_rules rr
                WHERE rr.category = 'class_teacher'
                AND rr.grade = (
                    SELECT gr.grade
                    FROM grading_rules gr
                    JOIN exams e ON e.grading_system_id = gr.grading_system_id
                    WHERE e.exam_id = p_exam_id
                    AND ROUND(ROUND(SUM(ts.subject_score) / GREATEST(COUNT(ts.subject_id), v_min_subjects), 2)) BETWEEN gr.min_score AND gr.max_score
                    ORDER BY gr.min_score DESC
                    LIMIT 1
                )
                AND rr.school_id IS NULL
                AND rr.class_id IS NULL
                AND rr.stream_id IS NULL
                AND rr.subject_id IS NULL
                ORDER BY rr.remark_id
                LIMIT 1
            ),
            'No class teacher remark available'
        ) AS class_teacher_remark,
        COUNT(ts.subject_id) AS subject_count
    FROM tmp_subject_scores ts
    GROUP BY ts.student_id;

    -- Step 4: Insert/Update exam_aggregates
    INSERT INTO exam_aggregates (
        school_id, exam_id, student_id, class_id, stream_id,
        term, year,
        total_score, mean_score, mean_grade, remark_text, class_teacher_remark, total_points
    )
    SELECT 
        p_school_id,
        p_exam_id,
        s.student_id,
        s.class_id,
        s.stream_id,
        v_term,
        v_year,
        sa.total_score,
        sa.mean_score,
        sa.mean_grade,
        sa.remark_text,
        sa.class_teacher_remark,
        sa.total_points
    FROM tmp_student_aggregates sa
    JOIN students s ON s.student_id = sa.student_id
    ON DUPLICATE KEY UPDATE
        total_score = VALUES(total_score),
        mean_score = VALUES(mean_score),
        mean_grade = VALUES(mean_grade),
        remark_text = VALUES(remark_text),
        class_teacher_remark = VALUES(class_teacher_remark),
        total_points = VALUES(total_points);

    -- Step 5: Assign Overall Positions (Per Class)
    UPDATE exam_aggregates ea
    JOIN (
        SELECT ea2.student_id, ea2.class_id,
               RANK() OVER (PARTITION BY ea2.class_id ORDER BY ea2.total_score DESC) AS class_pos
        FROM exam_aggregates ea2
        WHERE ea2.exam_id = p_exam_id AND ea2.school_id = p_school_id
    ) ranked
    ON ea.student_id = ranked.student_id AND ea.class_id = ranked.class_id
    SET ea.position_class = ranked.class_pos
    WHERE ea.exam_id = p_exam_id AND ea.school_id = p_school_id;

    -- Step 6: Assign Stream Positions
    UPDATE exam_aggregates ea
    JOIN (
        SELECT ea2.student_id, ea2.stream_id,
               RANK() OVER (PARTITION BY ea2.stream_id ORDER BY ea2.total_score DESC) AS stream_pos
        FROM exam_aggregates ea2
        WHERE ea2.exam_id = p_exam_id AND ea2.school_id = p_school_id
    ) ranked
    ON ea.student_id = ranked.student_id AND ea.stream_id = ranked.stream_id
    SET ea.position_stream = ranked.stream_pos
    WHERE ea.exam_id = p_exam_id AND ea.school_id = p_school_id;

END$$

DELIMITER ;