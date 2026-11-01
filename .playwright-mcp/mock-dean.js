async (page) => {
  const role = 'dean';
  const depts = [
    ['CIT', 'College of Information Technology'], ['CBA', 'College of Business Administration'], ['CED', 'College of Education'],
    ['CAS', 'College of Arts and Sciences'], ['CCJ', 'College of Criminal Justice'], ['CON', 'College of Nursing'],
  ].map(([code, name], i) => ({ id: i + 1, department_code: code, department_name: name, logo: null }));
  const programs = { 1: ['BSIT', 'BSCS'], 2: ['BSBA', 'BSA'], 3: ['BEED', 'BSED'], 4: ['ABPSY'], 5: ['BSCRIM'], 6: ['BSN'] };
  const sections = []; let sid = 1;
  for (const [d, progs] of Object.entries(programs)) for (const p of progs) for (let y = 1; y <= 4; y++) sections.push({ id: sid++, section_name: `${p} ${y}A`, department_id: Number(d), year_level: y });
  const statusFor = { 1: 'pending_vpaa', 2: 'approved', 3: 'pending_dean', 4: 'returned', 5: 'pending_vpaa', 6: 'draft' };
  const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
  const rooms = ['R 301', 'R 302', 'R 303', 'LAB 1', 'LAB 2', 'R 201', 'R 202', 'AVR', 'R 101', 'R 102'].map((code, i) => ({ id: i + 1, room_code: code, room_type: code.startsWith('LAB') ? 'laboratory' : 'lecture', building: i < 5 ? 'Main Building' : i < 8 ? 'Annex' : 'Science Hall', status: 'available', department_id: null }));
  const names = [['Grace', 'Hopper'], ['Alan', 'Turing'], ['Ada', 'Lovelace'], ['Donald', 'Knuth'], ['Maria', 'Santos'], ['Jose', 'Rizal'], ['Liza', 'Reyes'], ['Mark', 'Villanueva'], ['Ana', 'Cruz'], ['Paolo', 'Garcia'], ['Rina', 'Mendoza'], ['Carlo', 'Bautista']];
  const faculties = names.map(([f, l], i) => ({ id: i + 1, first_name: f, last_name: l, max_units: 21, assigned_units: [21, 18, 24, 0, 15, 21, 12, 27, 9, 21, 6, 18][i], department_id: (i % 6) + 1, status: 'active', employment_type: 'full-time' }));
  const courses = [['IT 101', 'Introduction to Computing'], ['IT 202', 'Data Structures'], ['BA 110', 'Principles of Management'], ['ED 120', 'Child Development'], ['GE 101', 'Purposive Communication']];
  const schedules = []; let id = 1;
  sections.forEach((s, i) => {
    if (statusFor[s.department_id] === 'draft' && i % 2) return;
    for (let k = 0; k < 3; k++) {
      const c = courses[(i + k) % courses.length]; const r = rooms[(i + k) % rooms.length]; const f = faculties[(i + k) % faculties.length];
      const start = 7 + ((i * 2 + k * 3) % 9);
      schedules.push({ id: id++, semester_id: 1, section_id: s.id, department_id: s.department_id, room_id: r.id, faculty_id: k === 2 && i % 4 === 0 ? null : f.id, day: days[(i + k) % 5],
        start_time: `${String(start).padStart(2, '0')}:00:00`, end_time: `${String(start + 1).padStart(2, '0')}:30:00`, mode: 'on-site', status: 'finalized', meeting_type: 'lecture',
        section: { id: s.id, section_name: s.section_name, department_id: s.department_id }, faculty: k === 2 && i % 4 === 0 ? null : { id: f.id, first_name: f.first_name, last_name: f.last_name },
        room: { id: r.id, room_code: r.room_code, building: r.building, room_type: r.room_type }, course: { id: (i + k) % 5 + 1, course_code: c[0], course_name: c[1], units: 3 }, course_id: (i + k) % 5 + 1 });
    }
  });
  const submissions = depts.filter(d => statusFor[d.id] !== 'draft').map(d => ({ id: d.id, department_id: d.id, semester_id: 1, revision_number: d.id === 5 ? 2 : 1, status: statusFor[d.id],
    submitted_at: new Date(Date.now() - d.id * 1.6 * 864e5).toISOString(), dean_reviewed_at: null, approval_override: d.id === 5, approval_override_reason: d.id === 5 ? 'Room-type rule waived' : null,
    sections: sections.filter(s => s.department_id === d.id).map(s => ({ id: s.id, pivot: { state: 'included' } })), submitter: { name: 'Juan Dela Cruz' } }));
  const initial = { time_grid: { opening_time: '07:00', closing_time: '19:00', slot_minutes: 30 }, active_semester: { id: 1, academic_year: '2026-2027', semester: '1st', is_active: true, status: 'active' },
    departments: depts, sections, schedules, schedule_submissions: submissions, faculties, rooms, courses: courses.map((c, i) => ({ id: i + 1, subject_code: c[0], subject_name: c[1], department_id: 1 })), active_curricula_count: 11 };
  const deptStatus = { 1: 'submitted', 2: 'submitted', 3: 'draft', 4: 'completed' };
  const statusResp = { department_id: 1, department_name: 'College of Information Technology', has_dean: true, cross_department_pending: 2,
    sections: sections.filter(s => s.department_id === 1).map((s, i) => ({ id: s.id, code: s.section_name, year_level: s.year_level, status: false ? ['completed', 'completed', 'draft', 'revision'][i % 4] : (s.section_name.startsWith('BSCS') && i % 3 === 0 ? 'revision' : deptStatus[s.year_level]) })) };
  const insights = { semester_id: 1, generated_at: new Date().toISOString(), utilization: { open_minutes_per_day: 720, rooms_total: 10, rooms_in_use: 8, rooms_unavailable: 0, average_utilization: 46,
    buildings: [{ building: 'Main Building', rooms: 5, rooms_in_use: 5, meetings: 48, booked_hours: 70, utilization: 72 }, { building: 'Annex', rooms: 3, rooms_in_use: 2, meetings: 20, booked_hours: 28, utilization: 41 }, { building: 'Science Hall', rooms: 2, rooms_in_use: 1, meetings: 9, booked_hours: 12, utilization: 88 }],
    busiest_rooms: [], idle_rooms: [{ id: 9, room_code: 'R 101', building: 'Science Hall', room_type: 'lecture', meetings: 0, booked_minutes: 0, utilization: 0, is_unavailable: false }], idle_room_count: 2 },
    peak_load: { days: [], hours: [], matrix: {}, peak: 0, peak_day: null, peak_hour: null }, coverage: { sections_with_schedule: 40, classes_without_instructor: 4, sections_without_instructor: 4, classes_without_room: 0, departments_with_gaps: 1 } };
  const activity = { data: [
    ['schedule_approved_by_vpaa', 'schedule_workflow', 'VPAA Office', 0.1], ['schedule_submitted', 'schedule_workflow', 'Juan Dela Cruz', 0.4], ['faculty_assigned', 'faculty_assignment', 'Liza Reyes', 1.2],
    ['user_created', 'user_management', 'VPAA Office', 2], ['schedule_returned_by_dean', 'schedule_workflow', 'Dean Ramos', 3]].map(([e, c, a, d], i) => ({ id: 'x' + i, event: e, category: c, occurred_at: new Date(Date.now() - d * 864e5).toISOString(), actor: { name: a } })) };
  const caps = ['schedule.view', 'schedule.update', 'schedule.assign_instructor', 'schedule.assign_instructor_cross_department'].map(id => ({ id, title: id, description: '', granted: true }));
  const user = { id: 7, name: 'Demo User', role, department_id: 1, capabilities: caps, permissions: caps.map(c => c.id) };

  await page.context().unroute('**/api/**').catch(() => {});
  await page.context().route('**/api/**', route => {
    const url = new URL(route.request().url()); const p = url.pathname.replace(/^\/api/, '');
    let body = {};
    if (p === '/initial-data') body = initial;
    else if (p === '/vpaa/dashboard-insights') body = insights;
    else if (p === '/activity-log') body = activity;
    else if (/schedule-status$/.test(p)) body = statusResp;
    else if (p === '/user' || p === '/me') body = user;
    else if (p === '/departments') body = depts;
    else if (p.startsWith('/notifications')) body = { data: [], unread_count: 0, meta: {} };
    else body = [];
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
  });
  await page.goto('http://localhost:5174/login');
  await page.evaluate(u => { localStorage.clear(); sessionStorage.clear(); localStorage.setItem('token', 'preview'); localStorage.setItem('user', JSON.stringify(u)); localStorage.setItem('role', u.role); }, user);
  const path = role === 'vpaa' ? '/dashboard' : `/${role}/dashboard`;
  await page.goto('http://localhost:5174' + path);
  await page.waitForSelector('#dashboard-metrics', { timeout: 20000 });
  await page.waitForTimeout(1200);
  return 'ok';
}
