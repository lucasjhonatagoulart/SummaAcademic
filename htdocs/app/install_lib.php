<?php
// Criação do banco, perfis, instituição, administrador e (opcional) dados de exemplo.

function schema_statements(string $driver): array {
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    $sql = preg_replace('/^--.*$/m', '', $sql);
    if ($driver === 'sqlite') {
        $sql = str_replace(['INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', 'INT UNSIGNED', 'MEDIUMTEXT'], ['INTEGER PRIMARY KEY AUTOINCREMENT', '', 'INTEGER', 'TEXT'], $sql);
    }
    return array_values(array_filter(array_map('trim', explode(';', $sql))));
}

function install_run(array $form): void {
    $driver = CONFIG['db']['driver'] ?? 'mysql';
    foreach (schema_statements($driver) as $st) db()->exec($st);
    tx(function () use ($form) {
        $instId = insert('institutions', [
            'name' => $form['inst_name'], 'short_name' => $form['inst_short'], 'city' => $form['inst_city'] ?? null, 'state' => $form['inst_state'] ?? null,
            'director_name' => $form['director'] ?? null, 'teaching_director_name' => $form['teaching_director'] ?? null, 'secretary_name' => $form['secretary'] ?? null, 'created_at' => now(),
        ]);
        insert('settings', ['k' => 'active_institution_id', 'v' => (string) $instId]);
        insert('settings', ['k' => 'installed_at', 'v' => now()]);
        foreach (SYSTEM_ROLES as $kind => [$name, $desc, $perms]) {
            $rid = insert('roles', ['kind' => $kind, 'name' => $name, 'description' => $desc]);
            if (is_array($perms)) foreach ($perms as $pc) insert('role_permissions', ['role_id' => $rid, 'perm' => $pc]);
        }
        $adminId = insert('users', ['institution_id' => $instId, 'name' => $form['admin_name'], 'email' => mb_strtolower($form['admin_email']), 'password_hash' => password_hash($form['admin_pass'], PASSWORD_DEFAULT), 'status' => 'ACTIVE', 'created_at' => now()]);
        insert('user_roles', ['user_id' => $adminId, 'role_id' => (int) val("SELECT id FROM roles WHERE kind = 'ADMIN'")]);
        if (!empty($form['demo'])) seed_demo($instId, $adminId);
    });
    users_to_individual_perms(); // usuários de exemplo ficam com permissões individuais, sem perfis
    set_setting('schema_version', (string) SCHEMA_VERSION);
}

function role_id(string $kind): int { return (int) val('SELECT id FROM roles WHERE kind = ?', [$kind]); }
function make_user(int $inst, string $name, string $email, string $kind, string $pass): int {
    $id = insert('users', ['institution_id' => $inst, 'name' => $name, 'email' => $email, 'password_hash' => password_hash($pass, PASSWORD_DEFAULT), 'status' => 'ACTIVE', 'created_at' => now()]);
    insert('user_roles', ['user_id' => $id, 'role_id' => role_id($kind)]);
    return $id;
}

/** Dados de exemplo para conhecer o sistema (podem ser apagados depois) */
function seed_demo(int $inst, int $adminId): void {
    mt_srand(2026);
    $pw = 'Academus@2026';
    update('institutions', ['legal_name' => 'Centro Universitário de Demonstração Ltda.', 'cnpj' => '11.222.333/0001-81', 'emec_code' => '12345', 'address' => 'Av. das Nações, 1000 · Centro', 'zip_code' => '01310-100', 'phone' => '(11) 3456-7000', 'email' => 'secretaria@academus.dev', 'website' => 'https://academus.dev', 'director_name' => 'Dra. Helena Prado', 'teaching_director_name' => 'Prof. Carlos Menezes', 'secretary_name' => 'Mariana Souza'], 'id = ?', [$inst]);
    make_user($inst, 'Mariana Souza', 'secretaria@academus.dev', 'STAFF', $pw);
    make_user($inst, 'Dra. Helena Prado', 'diretoria@academus.dev', 'DIRECTOR', $pw);
    make_user($inst, 'Rogério Batista', 'financeiro@academus.dev', 'FINANCE', $pw);

    // Cursos e matrizes
    $courses = [
        ['ADS', 'Análise e Desenvolvimento de Sistemas', 'Tecnólogo', 5, [
            [1, 'ADS101', 'Algoritmos e Lógica de Programação', 80], [1, 'ADS102', 'Fundamentos de Sistemas', 60], [1, 'ADS103', 'Matemática Discreta', 60], [1, 'ADS104', 'Comunicação e Expressão', 40, 'D', 0],
            [2, 'ADS201', 'Estruturas de Dados', 80], [2, 'ADS202', 'Banco de Dados I', 80], [2, 'ADS203', 'Programação Web', 80], [2, 'ADS204', 'Engenharia de Software', 60],
            [3, 'ADS301', 'Programação Orientada a Objetos', 80], [3, 'ADS302', 'Banco de Dados II', 60], [3, 'ADS303', 'Redes de Computadores', 60], [3, 'ADS304', 'Ética e Cidadania', 40, 'D', 0],
            [4, 'ADS401', 'Desenvolvimento Mobile', 80], [4, 'ADS402', 'Segurança da Informação', 60], [5, 'ADS501', 'Projeto Integrador', 80],
            [3, 'OPT01', 'Introdução à Ciência de Dados', 40, 'P', 1, 0], [3, 'OPT02', 'Libras', 40, 'D', 1, 0],
        ]],
        ['ADM', 'Administração', 'Bacharelado', 8, [
            [1, 'ADM101', 'Teoria Geral da Administração', 80], [1, 'ADM102', 'Matemática Aplicada', 60], [1, 'ADM103', 'Comunicação Empresarial', 40, 'D', 0],
            [2, 'ADM201', 'Contabilidade Geral', 80], [2, 'ADM202', 'Economia', 60], [2, 'ADM203', 'Gestão de Pessoas', 60],
            [3, 'ADM301', 'Marketing', 60], [3, 'ADM302', 'Finanças Empresariais', 80], [3, 'OPT01', 'Introdução à Ciência de Dados', 40, 'P', 1, 0],
        ]],
    ];
    $subjectIds = []; $courseIds = []; $matrix = [];
    foreach ($courses as [$code, $name, $deg, $per, $items]) {
        $cid = insert('courses', ['institution_id' => $inst, 'code' => $code, 'name' => $name, 'degree' => $deg, 'periods' => $per, 'active' => 1]);
        $courseIds[$code] = $cid;
        foreach ($items as $it) {
            [$period, $scode, $sname, $wl] = $it; $mod = $it[4] ?? 'P'; $tracks = $it[5] ?? 1; $req = $it[6] ?? 1;
            $subjectIds[$scode] ??= insert('subjects', ['institution_id' => $inst, 'code' => $scode, 'name' => $sname, 'workload' => $wl, 'modality' => $mod, 'tracks_attendance' => $tracks]);
            insert('course_subjects', ['course_id' => $cid, 'subject_id' => $subjectIds[$scode], 'period' => $period, 'required' => $req, 'prerequisites' => null]);
            $matrix[$code][$period][] = [$scode, $req];
        }
    }
    update('course_subjects', ['prerequisites' => 'ADS101'], 'subject_id = ?', [$subjectIds['ADS201']]);
    update('course_subjects', ['prerequisites' => 'ADS202'], 'subject_id = ?', [$subjectIds['ADS302']]);

    // Períodos letivos
    $y = (int) date('Y');
    $past = insert('terms', ['institution_id' => $inst, 'name' => "$y.1", 'starts_on' => "$y-02-02", 'ends_on' => "$y-06-30", 'active' => 0, 'closed' => 1, 'max_electives' => 2]);
    $cur = insert('terms', ['institution_id' => $inst, 'name' => "$y.2", 'starts_on' => "$y-08-03", 'ends_on' => "$y-12-18", 'active' => 1, 'closed' => 0,
        'elective_start' => date('Y-m-d', strtotime('-10 days')), 'elective_end' => date('Y-m-d', strtotime('+20 days')), 'max_electives' => 2, 'grades_deadline' => "$y-12-23"]);

    // Professores
    $profData = [['Prof. Ricardo Alves', 'professor@academus.dev', 'Mestre', 'Computação'], ['Profa. Juliana Costa', 'juliana.costa@academus.dev', 'Doutora', 'Computação'],
        ['Prof. Marcos Lima', 'marcos.lima@academus.dev', 'Especialista', 'Gestão'], ['Profa. Beatriz Nogueira', 'beatriz.nogueira@academus.dev', 'Mestre', 'Gestão']];
    $profs = [];
    foreach ($profData as $i => [$n, $e, $t, $d]) { $uid = make_user($inst, $n, $e, 'PROFESSOR', $pw); $profs[] = insert('professors', ['user_id' => $uid, 'registration' => 'P' . str_pad((string) (101 + $i), 4, '0', STR_PAD_LEFT), 'title' => $t, 'department' => $d, 'hired_on' => ($y - 3 + $i % 2) . '-02-01']); }
    foreach ($profs as $k => $pid) insert('professor_rates', ['professor_id' => $pid, 'institution_id' => $inst, 'rate_cents' => [25000, 22000, 18000, 9000][$k % 4], 'unit' => $k % 4 === 3 ? 'HORA' : 'AULA', 'updated_at' => now()]);

    // Turmas: ADS 2º período e ADS 3º período; ADM 1º período
    $groups = [
        'ADS-2N' => insert('class_groups', ['institution_id' => $inst, 'term_id' => $cur, 'course_id' => $courseIds['ADS'], 'code' => 'ADS-2N', 'period_number' => 2, 'shift' => 'Noite']),
        'ADS-3N' => insert('class_groups', ['institution_id' => $inst, 'term_id' => $cur, 'course_id' => $courseIds['ADS'], 'code' => 'ADS-3N', 'period_number' => 3, 'shift' => 'Noite']),
        'ADM-1N' => insert('class_groups', ['institution_id' => $inst, 'term_id' => $cur, 'course_id' => $courseIds['ADM'], 'code' => 'ADM-1N', 'period_number' => 1, 'shift' => 'Noite']),
    ];
    $pastGroups = [
        'ADS-1N' => insert('class_groups', ['institution_id' => $inst, 'term_id' => $past, 'course_id' => $courseIds['ADS'], 'code' => 'ADS-1N', 'period_number' => 1, 'shift' => 'Noite']),
        'ADS-2N' => insert('class_groups', ['institution_id' => $inst, 'term_id' => $past, 'course_id' => $courseIds['ADS'], 'code' => 'ADS-2N', 'period_number' => 2, 'shift' => 'Noite']),
    ];

    $mkSection = function (int $term, string $scode, array $groupIds, array $profIds, array $slots, string $room, string $type = 'REGULAR', ?int $cap = null) use ($inst, $subjectIds) {
        $sid = insert('sections', ['institution_id' => $inst, 'term_id' => $term, 'subject_id' => $subjectIds[$scode], 'code' => $scode . '-' . ($type === 'ELECTIVE' ? 'ELT' : implode('', array_keys($groupIds))), 'offer_type' => $type, 'room' => $room, 'capacity' => $cap, 'min_grade' => 6, 'min_attendance' => 75]);
        foreach ($groupIds as $gid) insert('section_groups', ['section_id' => $sid, 'group_id' => $gid]);
        foreach ($profIds as $pid) insert('section_professors', ['section_id' => $sid, 'professor_id' => $pid]);
        foreach ($slots as [$d, $a, $b]) insert('schedules', ['section_id' => $sid, 'weekday' => $d, 'start_time' => $a, 'end_time' => $b]);
        return $sid;
    };
    $N1 = ['19:00', '20:40']; $N2 = ['20:50', '22:30'];
    $cs = [];
    // Período atual
    $cs['ADS-2N'] = [
        $mkSection($cur, 'ADS201', ['A2' => $groups['ADS-2N']], [$profs[0]], [[1, ...$N1], [3, ...$N1]], 'Lab 1'),
        $mkSection($cur, 'ADS202', ['A2' => $groups['ADS-2N']], [$profs[1]], [[1, ...$N2], [4, ...$N1]], 'Lab 2'),
        $mkSection($cur, 'ADS203', ['A2' => $groups['ADS-2N']], [$profs[0]], [[2, ...$N1], [4, ...$N2]], 'Lab 1'),
        $mkSection($cur, 'ADS204', ['A2' => $groups['ADS-2N']], [$profs[1]], [[5, ...$N1]], 'Sala 12'),
    ];
    $cs['ADS-3N'] = [
        $mkSection($cur, 'ADS301', ['A3' => $groups['ADS-3N']], [$profs[0]], [[2, ...$N2], [5, ...$N2]], 'Lab 3'),
        $mkSection($cur, 'ADS302', ['A3' => $groups['ADS-3N']], [$profs[1]], [[3, ...$N2]], 'Lab 2'),
        $mkSection($cur, 'ADS303', ['A3' => $groups['ADS-3N']], [$profs[1]], [[2, ...$N1]], 'Lab 4'),
        $mkSection($cur, 'ADS304', ['A3' => $groups['ADS-3N']], [$profs[2]], [], 'EaD'),
    ];
    $cs['ADM-1N'] = [
        $mkSection($cur, 'ADM101', ['M1' => $groups['ADM-1N']], [$profs[2]], [[1, ...$N1], [3, ...$N2]], 'Sala 20'),
        $mkSection($cur, 'ADM102', ['M1' => $groups['ADM-1N']], [$profs[3]], [[2, ...$N1], [4, ...$N1]], 'Sala 20'),
        $mkSection($cur, 'ADM103', ['M1' => $groups['ADM-1N']], [$profs[3]], [], 'EaD'),
    ];
    $elec = [$mkSection($cur, 'OPT01', [], [$profs[1]], [[6, '08:00', '11:30']], 'Lab 4', 'ELECTIVE', 25), $mkSection($cur, 'OPT02', [], [$profs[3]], [[4, ...$N2]], 'EaD', 'ELECTIVE', 40)];
    // Período anterior (já encerrado)
    $pastSec = [
        'ADS-1N' => [$mkSection($past, 'ADS101', ['P1' => $pastGroups['ADS-1N']], [$profs[0]], [[1, ...$N1]], 'Lab 1'), $mkSection($past, 'ADS102', ['P1' => $pastGroups['ADS-1N']], [$profs[1]], [[2, ...$N1]], 'Sala 10'),
            $mkSection($past, 'ADS103', ['P1' => $pastGroups['ADS-1N']], [$profs[2]], [[3, ...$N1]], 'Sala 11'), $mkSection($past, 'ADS104', ['P1' => $pastGroups['ADS-1N']], [$profs[3]], [], 'EaD')],
        'ADS-2N' => [$mkSection($past, 'ADS201', ['P2' => $pastGroups['ADS-2N']], [$profs[0]], [[1, ...$N2]], 'Lab 1'), $mkSection($past, 'ADS202', ['P2' => $pastGroups['ADS-2N']], [$profs[1]], [[2, ...$N2]], 'Lab 2'),
            $mkSection($past, 'ADS203', ['P2' => $pastGroups['ADS-2N']], [$profs[0]], [[3, ...$N2]], 'Lab 1'), $mkSection($past, 'ADS204', ['P2' => $pastGroups['ADS-2N']], [$profs[1]], [[4, ...$N2]], 'Sala 12')],
    ];

    // Alunos
    $first = ['Ana Beatriz', 'Bruno', 'Carla', 'Diego', 'Eduarda', 'Felipe', 'Gabriela', 'Henrique', 'Isabela', 'João Pedro', 'Larissa', 'Lucas', 'Mariana', 'Nicolas', 'Olívia', 'Pedro', 'Rafaela', 'Samuel', 'Talita', 'Vinícius', 'Yasmin', 'Caio', 'Letícia', 'Matheus'];
    $last = ['Lima', 'Santos', 'Oliveira', 'Pereira', 'Costa', 'Rodrigues', 'Almeida', 'Nascimento', 'Ferreira', 'Araújo', 'Ribeiro', 'Carvalho'];
    $plan = array_merge(array_fill(0, 9, 'ADS-2N'), array_fill(0, 8, 'ADS-3N'), array_fill(0, 7, 'ADM-1N'));
    $fee = ['ADS' => 89000, 'ADM' => 79000];
    foreach ($plan as $i => $gcode) {
        $name = $first[$i] . ' ' . $last[$i % count($last)];
        $email = $i === 0 ? 'aluno@academus.dev' : strtolower(fold(str_replace(' ', '.', $first[$i]))) . '.' . strtolower(fold($last[$i % count($last)])) . '@academus.dev';
        $uid = make_user($inst, $name, $email, 'STUDENT', $pw);
        $course = substr($gcode, 0, 3);
        $cpfBase = str_pad((string) (100000000 + $i * 7919), 9, '0', STR_PAD_LEFT);
        $stId = insert('students', ['user_id' => $uid, 'ra' => ($course === 'ADS' ? '2026' : '2026') . str_pad((string) (1001 + $i), 5, '0', STR_PAD_LEFT), 'course_id' => $courseIds[$course], 'group_id' => $groups[$gcode],
            'entry_term' => $gcode === 'ADM-1N' ? "$y.2" : ($gcode === 'ADS-2N' ? "$y.1" : ($y - 1) . '.2'), 'status' => 'ATIVO', 'cpf' => cpf_with_digits($cpfBase), 'birth_date' => (2000 + $i % 6) . '-0' . (1 + $i % 9) . '-1' . ($i % 9),
            'address' => 'Rua das Flores, ' . (100 + $i) . ' · São Paulo/SP', 'monthly_fee_cents' => $fee[$course]]);
        foreach ($cs[$gcode] as $sid) insert('enrollments', ['student_id' => $stId, 'section_id' => $sid, 'status' => 'CURSANDO', 'created_at' => now()]);
        // Histórico do período anterior
        $prev = $gcode === 'ADS-2N' ? 'ADS-1N' : ($gcode === 'ADS-3N' ? 'ADS-2N' : null);
        if ($prev) foreach ($pastSec[$prev] as $k => $sid) {
            $g = round(mt_rand(48, 98) / 10, 1); $att = mt_rand(68, 100);
            $tracks = !in_array($k, [3], true) || $prev !== 'ADS-1N';
            insert('enrollments', ['student_id' => $stId, 'section_id' => $sid, 'status' => final_status($g, $tracks ? $att : null, 6, 75, $tracks), 'final_grade' => $g, 'attendance_pct' => $tracks ? $att : null, 'created_at' => "$y-02-02 08:00:00"]);
        }
        if ($gcode === 'ADS-3N') foreach ($pastSec['ADS-1N'] as $sid) insert('enrollments', ['student_id' => $stId, 'section_id' => $sid, 'status' => 'APROVADO', 'final_grade' => round(mt_rand(65, 95) / 10, 1), 'attendance_pct' => mt_rand(80, 100), 'created_at' => ($y - 1) . '-08-01 08:00:00']);
        if ($i % 4 === 1 && $gcode !== 'ADM-1N') insert('enrollments', ['student_id' => $stId, 'section_id' => $elec[0], 'status' => 'CURSANDO', 'created_at' => now()]);
        // Mensalidades do período atual
        for ($m = 8; $m <= 12; $m++) {
            $due = sprintf('%d-%02d-10', $y, $m);
            $paid = $due < date('Y-m-d') && !($i % 6 === 2 && $m >= 10) && !($i % 9 === 4 && $m === (int) date('n'));
            insert('charges', ['institution_id' => $inst, 'student_id' => $stId, 'description' => "Mensalidade " . sprintf('%02d/%d', $m, $y), 'competence' => sprintf('%d-%02d', $y, $m), 'due_on' => $due,
                'amount_cents' => $fee[$course], 'status' => $paid ? 'PAGA' : 'ABERTA', 'paid_on' => $paid ? sprintf('%d-%02d-%02d', $y, $m, 5 + $i % 8) : null, 'paid_cents' => $paid ? $fee[$course] : null, 'method' => $paid ? ['PIX', 'Boleto', 'Cartão'][$i % 3] : null, 'created_at' => now()]);
        }
    }

    // Aulas, chamadas e notas no período atual (até hoje)
    foreach (array_merge(...array_values($cs)) as $sid) {
        $sec = row('SELECT se.*, su.tracks_attendance FROM sections se JOIN subjects su ON su.id = se.subject_id WHERE se.id = ?', [$sid]);
        $enrs = col('SELECT id FROM enrollments WHERE section_id = ?', [$sid]);
        $days = col('SELECT weekday FROM schedules WHERE section_id = ?', [$sid]);
        if ($days && $sec['tracks_attendance']) {
            $d = strtotime("$y-08-03"); $n = 0;
            while ($d < strtotime('-1 day') && $n < 24) {
                if (in_array((int) date('N', $d), array_map('intval', $days), true)) {
                    $lid = insert('lessons', ['section_id' => $sid, 'lesson_date' => date('Y-m-d', $d), 'topic' => 'Aula ' . (++$n) . ': conteúdo do plano de ensino', 'hours' => 2]);
                    foreach ($enrs as $k => $eid) insert('attendance', ['lesson_id' => $lid, 'enrollment_id' => $eid, 'present' => (mt_rand(1, 100) > ($k % 7 === 3 ? 30 : 8)) ? 1 : 0]);
                }
                $d = strtotime('+1 day', $d);
            }
        }
        $a1 = insert('assessments', ['section_id' => $sid, 'name' => 'Prova 1', 'weight' => 4, 'due_on' => "$y-09-20", 'published' => 1]);
        $a2 = insert('assessments', ['section_id' => $sid, 'name' => 'Trabalho', 'weight' => 2, 'due_on' => "$y-10-25", 'published' => 1]);
        insert('assessments', ['section_id' => $sid, 'name' => 'Prova 2', 'weight' => 4, 'due_on' => "$y-12-05", 'published' => 0]);
        foreach ($enrs as $k => $eid) { insert('grades', ['assessment_id' => $a1, 'enrollment_id' => $eid, 'score' => round(mt_rand($k % 5 === 2 ? 30 : 55, 100) / 10, 1)]); insert('grades', ['assessment_id' => $a2, 'enrollment_id' => $eid, 'score' => round(mt_rand(60, 100) / 10, 1)]); }
    }

    // Comunicados e quadro de avisos
    $ann = [
        ['COMM', null, 'Semana de provas: 7 a 12 de dezembro', 'Confira o calendário de avaliações finais com os professores de cada disciplina.', 'ALL', 1, '-5 days', null, 'Secretaria Acadêmica'],
        ['COMM', null, 'Rematrícula ' . ($y + 1) . '.1 aberta', 'O período de rematrícula vai de 16/11 a 04/12 pelo portal do aluno.', 'STUDENTS', 0, '-7 days', '+25 days', 'Secretaria Acadêmica'],
        ['COMM', null, 'Prazo para lançamento de notas: 23 de dezembro', 'Notas e frequência do período devem ser lançadas até 23/12. Depois dessa data, alterações só com autorização da coordenação.', 'PROFESSORS', 1, '-4 days', '+40 days', 'Secretaria Acadêmica'],
        ['COMM', null, 'Recesso administrativo de fim de ano', 'Os setores administrativos funcionam em regime de plantão de 24/12 a 03/01.', 'STAFF', 0, '-3 days', '+60 days', 'Secretaria Acadêmica'],
        ['BOARD', 'EVENT', 'Semana Acadêmica de Tecnologia', 'Palestras, oficinas e hackathon no auditório. Inscrições gratuitas na secretaria.', 'ALL', 1, '-4 days', '+17 days', 'Comunicação'],
        ['BOARD', 'CAMPAIGN', 'Vestibular: indique um amigo', 'Indicou um calouro que se matriculou? Você ganha 10% de desconto na mensalidade de fevereiro.', 'ALL', 0, '-6 days', '+35 days', 'Comunicação'],
        ['BOARD', 'ACADEMIC', 'Biblioteca em horário estendido', 'Na semana de provas, a biblioteca abre das 7h às 23h, inclusive no sábado.', 'STUDENTS', 0, '-2 days', '+32 days', 'Secretaria Acadêmica'],
        ['BOARD', 'INSTITUTIONAL', 'Campanha do Agasalho', 'Doe roupas e cobertores em bom estado. Pontos de coleta na recepção e na cantina.', 'ALL', 0, '-8 days', '+20 days', 'Comunicação'],
        ['BOARD', 'FINANCE', 'Boletos do próximo mês disponíveis', 'Os boletos já estão no menu Financeiro do portal do aluno.', 'STUDENTS', 0, '-1 days', '+30 days', 'Secretaria Acadêmica'],
        ['BOARD', 'ACADEMIC', 'Reunião pedagógica', 'Às 18h, na sala dos professores. Pauta: fechamento do período e calendário do próximo ano.', 'PROFESSORS', 0, '-5 days', '+15 days', 'Secretaria Acadêmica'],
    ];
    foreach ($ann as [$k, $cat, $t, $b, $aud, $pin, $from, $to, $by])
        insert('announcements', ['institution_id' => $inst, 'kind' => $k, 'category' => $cat, 'title' => $t, 'body' => $b, 'audience' => $aud, 'author_label' => $by, 'pinned' => $pin,
            'published_on' => date('Y-m-d', strtotime($from)), 'expires_on' => $to ? date('Y-m-d', strtotime($to)) : null, 'created_by' => $adminId, 'created_at' => now()]);
}
function cpf_with_digits(string $nine): string {
    $c = $nine;
    for ($t = 9; $t < 11; $t++) { $d = 0; for ($i = 0; $i < $t; $i++) $d += (int) $c[$i] * (($t + 1) - $i); $c .= ((10 * $d) % 11) % 10; }
    return substr($c, 0, 3) . '.' . substr($c, 3, 3) . '.' . substr($c, 6, 3) . '-' . substr($c, 9, 2);
}
