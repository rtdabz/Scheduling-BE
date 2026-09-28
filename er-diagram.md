Table account_invitation_tokens {
  email varchar(255) [primary key, not null]
  token varchar(255) [not null]
  created_at timestamp [null, default: 'NULL']
}
Table authentication_audit_logs {
  id bigint(20) [primary key, not null, note: 'unsigned']
  actor_user_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  subject_user_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  event varchar(80) [not null]
  ip_address varchar(45) [null, default: 'NULL']
  user_agent text [null, default: 'NULL']
  metadata longtext [null, default: 'NULL']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
}
Table courses {
  id bigint(20) [primary key, not null, note: 'unsigned']
  course_code varchar(255) [not null, unique]
  course_name varchar(255) [not null]
  lecture_hours int(11) [not null, default: '0']
  lab_hours int(11) [not null, default: '0']
  units int(11) [not null, default: '0']
  course_category enum('major','minor') [not null]
  room_type_required enum('lecture','laboratory','field','online') [not null, default: ''lecture'']
  year_level enum('1','2','3','4') [not null]
  semester enum('1st','2nd','summer') [not null]
  department_id bigint(20) [null, unique, default: 'NULL', note: 'unsigned']
  teaching_department_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  teaching_program_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  program_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  status enum('active','inactive') [not null, default: ''active'']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
  deleted_at timestamp [null, default: 'NULL']
  shared_course_code varchar(255) [null, unique, default: 'NULL']
}
Table curriculum {
  id bigint(20) [primary key, not null, note: 'unsigned']
  name varchar(255) [not null]
  department_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  program_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  code varchar(255) [not null, unique]
  effective_school_year varchar(255) [not null]
  status enum('active','deactivated','archived') [not null, default: ''deactivated'']
  description text [null, default: 'NULL']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
}
Table curriculum_course {
  id bigint(20) [primary key, not null, note: 'unsigned']
  curriculum_id bigint(20) [not null, unique, note: 'unsigned']
  course_id bigint(20) [not null, unique, note: 'unsigned']
  year_level tinyint(3) [not null, note: 'unsigned']
  semester tinyint(3) [not null, note: 'unsigned']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
}
Table departments {
  id bigint(20) [primary key, not null, note: 'unsigned']
  department_name varchar(255) [not null, unique]
  department_code varchar(255) [not null, unique]
  scheduling_profile varchar(32) [not null, default: ''standard'']
  logo longtext [null, default: 'NULL']
  lecture_lab_schedule_override_enabled tinyint(1) [not null, default: '0']
  custom_lab_duration_override_enabled tinyint(1) [not null, default: '0']
  custom_lab_duration_minutes smallint(5) [null, default: 'NULL', note: 'unsigned']
  custom_lab_duration_6_hours_enabled tinyint(1) [not null, default: '0']
  custom_lab_duration_5_hours_enabled tinyint(1) [not null, default: '0']
  custom_lab_duration_other_enabled tinyint(1) [not null, default: '0']
  gec_split_schedule_override_enabled tinyint(1) [not null, default: '0']
  major_lecture_split_schedule_override_enabled tinyint(1) [not null, default: '0']
  deleted_at timestamp [null, default: 'NULL']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
  sunday_classes_enabled tinyint(1) [not null, default: '0']
  room_sharing_policy varchar(16) [not null, default: ''open'']
}
Table department_course_rules {
  id bigint(20) [primary key, not null, note: 'unsigned']
  department_id bigint(20) [not null, unique, note: 'unsigned']
  course_id bigint(20) [not null, unique, note: 'unsigned']
  section_id bigint(20) [null, unique, default: 'NULL', note: 'unsigned']
  forced_day enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') [null, default: 'NULL']
  is_field tinyint(1) [not null, default: '0']
  consecutive_day_count tinyint(3) [null, default: 'NULL', note: 'unsigned']
  preferred_start_day enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') [null, default: 'NULL']
  meeting_days varchar(80) [null, default: 'NULL']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
}
Table designations {
  id bigint(20) [primary key, not null, note: 'unsigned']
  parent_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  name varchar(255) [not null]
  code varchar(255) [null, default: 'NULL']
  deload_units int(10) [not null, default: '0', note: 'unsigned']
  description varchar(255) [null, default: 'NULL']
  status enum('active','inactive') [not null, default: ''active'']
  sort_order int(10) [not null, default: '0', note: 'unsigned']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
  deleted_at timestamp [null, default: 'NULL']
}
Table designation_faculty {
  id bigint(20) [primary key, not null, note: 'unsigned']
  faculty_id bigint(20) [not null, unique, note: 'unsigned']
  designation_id bigint(20) [not null, unique, note: 'unsigned']
  position tinyint(3) [not null, default: '0', note: 'unsigned']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
}
Table faculties {
  id bigint(20) [primary key, not null, note: 'unsigned']
  user_id bigint(20) [null, unique, default: 'NULL', note: 'unsigned']
  administrative_role varchar(255) [null, default: 'NULL']
  first_name varchar(255) [not null]
  last_name varchar(255) [not null]
  middle_name varchar(255) [null, default: 'NULL']
  suffix varchar(10) [null, default: 'NULL']
  employment_type enum('full-time','part-time') [not null]
  max_units int(11) [not null, default: '21']
  overload_units int(11) [not null, default: '0']
  deload_units int(11) [not null, default: '0']
  probono_units int(11) [not null, default: '0']
  profile_picture longtext [null, default: 'NULL']
  department_id bigint(20) [not null, note: 'unsigned']
  program_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  status enum('active','inactive') [not null, default: ''active'']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
  deleted_at timestamp [null, default: 'NULL']
}
Table faculty_availabilities {
  id bigint(20) [primary key, not null, note: 'unsigned']
  faculty_id bigint(20) [not null, note: 'unsigned']
  day enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') [not null]
  start_time time [not null]
  end_time time [not null]
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
}
Table institution_settings {
  id bigint(20) [primary key, not null, note: 'unsigned']
  president_name varchar(150) [not null, default: ''College President'']
  president_title varchar(150) [not null, default: ''President'']
  opening_time time [not null, default: ''07:00:00'']
  closing_time time [not null, default: ''20:30:00'']
  field_end_time time [not null, default: ''17:00:00'']
  slot_interval int(11) [not null, default: '30']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
}
Table model_has_permissions {
  permission_id bigint(20) [primary key, not null, note: 'unsigned']
  model_type varchar(255) [primary key, not null]
  model_id bigint(20) [primary key, not null, note: 'unsigned']
}
Table model_has_roles {
  role_id bigint(20) [primary key, not null, note: 'unsigned']
  model_type varchar(255) [primary key, not null]
  model_id bigint(20) [primary key, not null, note: 'unsigned']
}
Table permissions {
  id bigint(20) [primary key, not null, note: 'unsigned']
  name varchar(255) [not null, unique]
  guard_name varchar(255) [not null, unique]
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
}
Table personal_access_tokens {
  id bigint(20) [primary key, not null, note: 'unsigned']
  tokenable_type varchar(255) [not null]
  tokenable_id bigint(20) [not null, note: 'unsigned']
  name text [not null]
  token varchar(64) [not null, unique]
  abilities text [null, default: 'NULL']
  last_used_at timestamp [null, default: 'NULL']
  expires_at timestamp [null, default: 'NULL']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
}
Table programs {
  id bigint(20) [primary key, not null, note: 'unsigned']
  department_id bigint(20) [not null, unique, note: 'unsigned']
  major varchar(255) [not null, unique, default: '''']
  code varchar(255) [not null, unique]
  name varchar(255) [null, default: 'NULL']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
  deleted_at timestamp [null, default: 'NULL']
}
Table roles {
  id bigint(20) [primary key, not null, note: 'unsigned']
  name varchar(255) [not null, unique]
  guard_name varchar(255) [not null, unique]
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
}
Table role_has_permissions {
  permission_id bigint(20) [primary key, not null, note: 'unsigned']
  role_id bigint(20) [primary key, not null, note: 'unsigned']
}
Table rooms {
  id bigint(20) [primary key, not null, note: 'unsigned']
  room_code varchar(255) [not null, unique]
  building varchar(255) [null, default: 'NULL']
  room_type enum('lecture','laboratory','online','field') [not null]
  allow_lecture_usage tinyint(1) [not null, default: '0']
  status enum('available','not available') [not null, default: ''available'']
  department_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  home_program_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
  deleted_at timestamp [null, default: 'NULL']
}
Table room_requests {
  id bigint(20) [primary key, not null, note: 'unsigned']
  room_id bigint(20) [not null, note: 'unsigned']
  semester_id bigint(20) [not null, note: 'unsigned']
  requesting_department_id bigint(20) [not null, note: 'unsigned']
  owner_department_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  status enum('pending','approved','rejected','cancelled','revoked') [not null, default: ''pending'']
  purpose text [null, default: 'NULL']
  review_remarks text [null, default: 'NULL']
  requested_by bigint(20) [null, default: 'NULL', note: 'unsigned']
  reviewed_by bigint(20) [null, default: 'NULL', note: 'unsigned']
  reviewed_at timestamp [null, default: 'NULL']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
}
Table room_request_windows {
  id bigint(20) [primary key, not null, note: 'unsigned']
  room_request_id bigint(20) [not null, note: 'unsigned']
  day enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') [not null]
  start_time time [not null]
  end_time time [not null]
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
}
Table schedules {
  id bigint(20) [primary key, not null, note: 'unsigned']
  semester_id bigint(20) [not null, note: 'unsigned']
  section_id bigint(20) [not null, note: 'unsigned']
  curriculum_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  course_id bigint(20) [not null, note: 'unsigned']
  faculty_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  faculty_assignment_done tinyint(1) [not null, default: '0']
  faculty_conflict_override tinyint(1) [not null, default: '0']
  room_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  department_id bigint(20) [not null, note: 'unsigned']
  program_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  day enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') [not null]
  start_time time [not null]
  end_time time [not null]
  mode enum('on-site','online','field') [not null, default: ''on-site'']
  is_hybrid tinyint(1) [not null, default: '0']
  preferred_pattern varchar(20) [null, default: 'NULL']
  status enum('draft','completed','submitted','approved_by_dean','conditionally_approved','rejected_by_dean','approved','faculty_assignment','reassignment','finalized','rejected','revision') [not null, default: ''draft'']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
  deleted_at timestamp [null, default: 'NULL']
}
Table schedule_generation_runs {
  id bigint(20) [primary key, not null, note: 'unsigned']
  run_id char(36) [not null, unique]
  requested_by bigint(20) [not null, note: 'unsigned']
  semester_id bigint(20) [not null, note: 'unsigned']
  department_id bigint(20) [not null, note: 'unsigned']
  year_level tinyint(3) [not null, note: 'unsigned']
  status varchar(20) [not null]
  result longtext [null, default: 'NULL']
  error_message text [null, default: 'NULL']
  started_at timestamp [null, default: 'NULL']
  finished_at timestamp [null, default: 'NULL']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
}
Table schedule_history_items {
  id bigint(20) [primary key, not null, note: 'unsigned']
  history_version_id bigint(20) [not null, note: 'unsigned']
  original_schedule_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  before_snapshot longtext [null, default: 'NULL']
  after_snapshot longtext [null, default: 'NULL']
  snapshot_metadata longtext [null, default: 'NULL']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
}
Table schedule_history_versions {
  id bigint(20) [primary key, not null, note: 'unsigned']
  semester_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  academic_year varchar(50) [null, default: 'NULL']
  semester varchar(30) [null, default: 'NULL']
  department_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  actor_user_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  action varchar(80) [not null]
  source varchar(40) [null, default: 'NULL']
  reason text [null, default: 'NULL']
  change_summary longtext [null, default: 'NULL']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
}
Table schedule_recommendations {
  id bigint(20) [primary key, not null, note: 'unsigned']
  semester_id bigint(20) [not null, note: 'unsigned']
  section_id bigint(20) [not null, note: 'unsigned']
  department_id bigint(20) [not null, note: 'unsigned']
  generation_run_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  requested_by bigint(20) [null, default: 'NULL', note: 'unsigned']
  accepted_by bigint(20) [null, default: 'NULL', note: 'unsigned']
  rejected_by bigint(20) [null, default: 'NULL', note: 'unsigned']
  rank int(10) [not null, default: '1', note: 'unsigned']
  score int(11) [not null, default: '0']
  status enum('pending','accepted','rejected') [not null, default: ''pending'']
  input_payload longtext [null, default: 'NULL']
  recommended_schedules longtext [not null]
  rejection_reason text [null, default: 'NULL']
  accepted_at timestamp [null, default: 'NULL']
  rejected_at timestamp [null, default: 'NULL']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
}
Table schedule_splits {
  id bigint(20) [primary key, not null, note: 'unsigned']
  schedule_id bigint(20) [not null, note: 'unsigned']
  split_group_id char(36) [null, default: 'NULL']
  meeting_type enum('lecture','laboratory') [null, default: 'NULL']
  meeting_index tinyint(3) [not null, default: '1', note: 'unsigned']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
  deleted_at timestamp [null, default: 'NULL']
  live_schedule_id bigint(20) [null, unique, default: 'NULL', note: 'unsigned']
}
Table schedule_submissions {
  id bigint(20) [primary key, not null, note: 'unsigned']
  department_id bigint(20) [not null, unique, note: 'unsigned']
  semester_id bigint(20) [not null, unique, note: 'unsigned']
  parent_submission_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  revision_number int(10) [not null, unique, note: 'unsigned']
  status varchar(40) [not null]
  submitted_by bigint(20) [null, default: 'NULL', note: 'unsigned']
  submitted_at timestamp [null, default: 'NULL']
  dean_reviewed_by bigint(20) [null, default: 'NULL', note: 'unsigned']
  dean_reviewed_at timestamp [null, default: 'NULL']
  vpaa_reviewed_by bigint(20) [null, default: 'NULL', note: 'unsigned']
  vpaa_reviewed_at timestamp [null, default: 'NULL']
  withdrawn_by bigint(20) [null, default: 'NULL', note: 'unsigned']
  withdrawn_at timestamp [null, default: 'NULL']
  rejection_reason text [null, default: 'NULL']
  approval_override tinyint(1) [not null, default: '0']
  approval_override_reason varchar(2000) [null, default: 'NULL']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
}
Table schedule_submission_sections {
  id bigint(20) [primary key, not null, note: 'unsigned']
  schedule_submission_id bigint(20) [not null, unique, note: 'unsigned']
  section_id bigint(20) [not null, unique, note: 'unsigned']
  state varchar(30) [not null, default: ''included'']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
}
Table scheduling_audit_logs {
  id bigint(20) [primary key, not null, note: 'unsigned']
  user_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  schedule_recommendation_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  history_version_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  schedule_submission_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  semester_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  section_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  department_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  action varchar(80) [not null]
  metadata longtext [null, default: 'NULL']
  created_at timestamp [not null, default: 'current_timestamp()']
}
Table sections {
  id bigint(20) [primary key, not null, note: 'unsigned']
  section_name varchar(255) [not null, unique]
  year_level enum('1','2','3','4') [not null]
  semester enum('1st','2nd','summer') [not null]
  department_id bigint(20) [not null, unique, note: 'unsigned']
  program_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  curriculum_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  semester_id bigint(20) [not null, unique, note: 'unsigned']
  status enum('active','inactive') [not null, default: ''active'']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
}
Table semesters {
  id bigint(20) [primary key, not null, note: 'unsigned']
  academic_year varchar(255) [not null]
  semester enum('1st','2nd','summer') [not null]
  is_active tinyint(1) [not null, default: '0']
  is_enabled tinyint(1) [not null, default: '1']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
  deleted_at timestamp [null, default: 'NULL']
  semester_key varchar(300) [null, unique, default: 'NULL']
  active_key tinyint(4) [null, unique, default: 'NULL']
}
Table system_notifications {
  id bigint(20) [primary key, not null, note: 'unsigned']
  user_id bigint(20) [not null, note: 'unsigned']
  actor_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  department_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  semester_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  type varchar(80) [not null]
  title varchar(160) [not null]
  message text [not null]
  remarks text [null, default: 'NULL']
  metadata longtext [null, default: 'NULL']
  read_at timestamp [null, default: 'NULL']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
}
Table timeslot_override {
  id bigint(20) [primary key, not null, note: 'unsigned']
  duration_minutes int(11) [not null]
  start_time time [not null]
  is_active tinyint(1) [not null, default: '1']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
  deleted_at timestamp [null, default: 'NULL']
}
Table users {
  id bigint(20) [primary key, not null, note: 'unsigned']
  name varchar(255) [not null]
  first_name varchar(255) [null, default: 'NULL']
  middle_initial varchar(1) [null, default: 'NULL']
  last_name varchar(255) [null, default: 'NULL']
  suffix varchar(10) [null, default: 'NULL']
  username varchar(255) [not null, unique]
  email varchar(255) [null, unique, default: 'NULL']
  password varchar(255) [not null]
  role varchar(255) [not null, default: ''secretary'']
  is_active tinyint(1) [not null, default: '1']
  allow_google_login tinyint(1) [not null, default: '0']
  google_id varchar(255) [null, unique, default: 'NULL']
  google_email varchar(255) [null, default: 'NULL']
  google_linked_at timestamp [null, default: 'NULL']
  last_login_at timestamp [null, default: 'NULL']
  department_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  profile_picture longtext [null, default: 'NULL']
  program_id bigint(20) [null, default: 'NULL', note: 'unsigned']
  remember_token varchar(100) [null, default: 'NULL']
  created_at timestamp [null, default: 'NULL']
  updated_at timestamp [null, default: 'NULL']
  deleted_at timestamp [null, default: 'NULL']
}
Ref: authentication_audit_logs.actor_user_id > users.id
Ref: authentication_audit_logs.subject_user_id > users.id
Ref: courses.department_id - departments.id
Ref: courses.program_id > programs.id
Ref: courses.teaching_department_id > departments.id
Ref: courses.teaching_program_id > programs.id
Ref: curriculum.department_id > departments.id
Ref: curriculum.program_id > programs.id
Ref: curriculum_course.course_id - courses.id
Ref: curriculum_course.curriculum_id - curriculum.id
Ref: department_course_rules.course_id - courses.id
Ref: department_course_rules.department_id - departments.id
Ref: department_course_rules.section_id - sections.id
Ref: designations.parent_id > designations.id
Ref: designation_faculty.designation_id - designations.id
Ref: designation_faculty.faculty_id - faculties.id
Ref: faculties.department_id > departments.id
Ref: faculties.program_id > programs.id
Ref: faculties.user_id - users.id
Ref: faculty_availabilities.faculty_id > faculties.id
Ref: model_has_permissions.permission_id - permissions.id
Ref: model_has_roles.role_id - roles.id
Ref: programs.department_id - departments.id
Ref: role_has_permissions.permission_id - permissions.id
Ref: role_has_permissions.role_id - roles.id
Ref: rooms.department_id > departments.id
Ref: rooms.home_program_id > programs.id
Ref: room_requests.owner_department_id > departments.id
Ref: room_requests.requested_by > users.id
Ref: room_requests.requesting_department_id > departments.id
Ref: room_requests.reviewed_by > users.id
Ref: room_requests.room_id > rooms.id
Ref: room_requests.semester_id > semesters.id
Ref: room_request_windows.room_request_id > room_requests.id
Ref: schedules.course_id > courses.id
Ref: schedules.curriculum_id > curriculum.id
Ref: schedules.department_id > departments.id
Ref: schedules.faculty_id > faculties.id
Ref: schedules.program_id > programs.id
Ref: schedules.room_id > rooms.id
Ref: schedules.section_id > sections.id
Ref: schedules.semester_id > semesters.id
Ref: schedule_generation_runs.department_id > departments.id
Ref: schedule_generation_runs.requested_by > users.id
Ref: schedule_generation_runs.semester_id > semesters.id
Ref: schedule_history_items.history_version_id > schedule_history_versions.id
Ref: schedule_history_versions.actor_user_id > users.id
Ref: schedule_history_versions.department_id > departments.id
Ref: schedule_history_versions.semester_id > semesters.id
Ref: schedule_recommendations.accepted_by > users.id
Ref: schedule_recommendations.department_id > departments.id
Ref: schedule_recommendations.generation_run_id > schedule_generation_runs.id
Ref: schedule_recommendations.rejected_by > users.id
Ref: schedule_recommendations.requested_by > users.id
Ref: schedule_recommendations.section_id > sections.id
Ref: schedule_recommendations.semester_id > semesters.id
Ref: schedule_splits.schedule_id > schedules.id
Ref: schedule_submissions.dean_reviewed_by > users.id
Ref: schedule_submissions.department_id - departments.id
Ref: schedule_submissions.parent_submission_id > schedule_submissions.id
Ref: schedule_submissions.semester_id - semesters.id
Ref: schedule_submissions.submitted_by > users.id
Ref: schedule_submissions.vpaa_reviewed_by > users.id
Ref: schedule_submissions.withdrawn_by > users.id
Ref: schedule_submission_sections.schedule_submission_id - schedule_submissions.id
Ref: schedule_submission_sections.section_id - sections.id
Ref: scheduling_audit_logs.department_id > departments.id
Ref: scheduling_audit_logs.history_version_id > schedule_history_versions.id
Ref: scheduling_audit_logs.schedule_recommendation_id > schedule_recommendations.id
Ref: scheduling_audit_logs.schedule_submission_id > schedule_submissions.id
Ref: scheduling_audit_logs.section_id > sections.id
Ref: scheduling_audit_logs.semester_id > semesters.id
Ref: scheduling_audit_logs.user_id > users.id
Ref: sections.curriculum_id > curriculum.id
Ref: sections.department_id - departments.id
Ref: sections.program_id > programs.id
Ref: sections.semester_id - semesters.id
Ref: system_notifications.actor_id > users.id
Ref: system_notifications.department_id > departments.id
Ref: system_notifications.semester_id > semesters.id
Ref: system_notifications.user_id > users.id
Ref: users.department_id > departments.id
Ref: users.program_id > programs.id

```
