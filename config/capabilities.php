<?php

/*
 * `requires_program` marks a capability a department cannot exercise until it
 * owns at least one program -- you cannot build, submit or delegate a timetable
 * for programs that do not exist yet. CapabilityMiddleware used to infer this
 * from the `schedule.` name prefix, which swept in capabilities that have
 * nothing to do with programs: an account granted instructor assignment was
 * refused the teaching-load and availability editors, and the timeslot grid,
 * purely because its department had no program row. Declaring it per capability
 * keeps the rule where it can be read.
 *
 * `requires` lists the capabilities a grant cannot stand without. Every
 * schedule screen reads the timetable before it can change it, so granting
 * instructor assignment while withholding `schedule.view` produced an account
 * whose only unlocked pages answered 403 to their own data fetches. A role's
 * defaults must therefore include everything its capabilities require.
 */
return [
    'permissions' => [
        'schedule.view' => [
            'module' => 'schedule_workspace',
            'title' => 'View Schedules',
            'description' => 'Browse section schedules, department timetables, and academic calendars.',
        ],
        'schedule.create' => [
            'requires' => ['schedule.view'],
            'module' => 'schedule_workspace',
            'requires_program' => true,
            'title' => 'Create Schedules',
            'description' => 'Place new classes, schedule splits, and batch schedule placements.',
        ],
        'schedule.update' => [
            'requires' => ['schedule.view'],
            'module' => 'schedule_workspace',
            'requires_program' => true,
            'title' => 'Update Schedules',
            'description' => 'Modify existing schedule slots, timeslots, and room allocations.',
        ],
        'schedule.delete' => [
            'requires' => ['schedule.view'],
            'module' => 'schedule_workspace',
            'requires_program' => true,
            'title' => 'Delete Schedules',
            'description' => 'Remove schedules and drop timetable placements from sections.',
        ],
        'schedule.generate' => [
            'requires' => ['schedule.view'],
            'module' => 'recommendations',
            'requires_program' => true,
            'title' => 'Generate Recommendations',
            'description' => 'Run the automated recommendation engine and review proposals.',
        ],
        'schedule.assign_instructor' => [
            'requires' => ['schedule.view'],
            'module' => 'instructor_assignment',
            'title' => 'Assign Instructors',
            'description' => 'Assign faculty members to courses and manage teaching load allocations.',
        ],
        'faculty.manage_designations' => [
            'module' => 'instructor_assignment',
            'title' => 'Manage Designations',
            'description' => 'Maintain the administrative designations instructors hold, and the deload each one carries.',
            // Deliberately not delegable. A designation moves an instructor's
            // Basic Load institution-wide, so the list stays with the office
            // that owns faculty loading; no other role may be granted it.
            'allowed_roles' => ['vpaa'],
        ],
        'schedule.assign_instructor_cross_department' => [
            'requires' => ['schedule.view'],
            'module' => 'instructor_assignment',
            'requires_program' => true,
            'title' => 'Cross-Department Assignment',
            'description' => 'Decide which college teaches a delegable course owned by another department.',
        ],
        'schedule.submit' => [
            'requires' => ['schedule.view'],
            'module' => 'submission_workflow',
            'requires_program' => true,
            'title' => 'Submit Schedules',
            'description' => 'Submit completed department schedules for administrative approval.',
        ],
        'schedule.withdraw' => [
            'requires' => ['schedule.view'],
            'module' => 'submission_workflow',
            'requires_program' => true,
            'title' => 'Recall Submission',
            'description' => 'Recall pending department schedule submissions for further editing.',
        ],
        'schedule.approve_dean' => [
            'requires' => ['schedule.view'],
            'module' => 'approval_workflow',
            'title' => 'Dean Approval',
            'description' => 'Approve, endorse, or return department schedules as College Dean.',
            'allowed_roles' => ['dean', 'vpaa'],
        ],
        'schedule.approve_vpaa' => [
            'requires' => ['schedule.view'],
            'module' => 'approval_workflow',
            'title' => 'VPAA Approval',
            'description' => 'Approve or return department schedules as Vice President for Academic Affairs.',
            'allowed_roles' => ['vpaa'],
        ],
        'room.request' => [
            'requires' => ['schedule.view'],
            'module' => 'room_requests',
            'requires_program' => true,
            'title' => 'Request Rooms',
            'description' => "Ask another department to lend one of its vacant rooms during specific weekly windows.",
        ],
        'room.review_requests' => [
            'requires' => ['schedule.view'],
            'module' => 'room_requests',
            'title' => 'Review Room Requests',
            'description' => "Approve, reject, or revoke other departments' requests for your department's rooms.",
            // The department that owns the room decides whether to lend it; the
            // controller also checks the room belongs to the reviewer's department.
            'allowed_roles' => ['secretary'],
        ],
        'room.view_all_requests' => [
            'requires' => ['schedule.view'],
            'module' => 'room_requests',
            'title' => 'Monitor Room Borrowing',
            'description' => 'See every room request between departments without deciding on them.',
            // The VPAA is kept informed of borrowing but no longer approves it.
            'allowed_roles' => ['vpaa'],
        ],
        'curriculum.manage' => [
            'requires' => ['schedule.view'],
            'module' => 'curriculum',
            'title' => 'Manage Curriculum',
            'description' => 'Create, edit, duplicate, activate, and archive curricula and their course placements.',
            // Curriculum authoring sits with the department secretary, who runs
            // the programs the curriculum describes. The dean and the VPAA read
            // curricula through the view routes and hold no write access, so
            // neither role may be granted this.
            'allowed_roles' => ['secretary'],
        ],
    ],

    'modules' => [
        'schedule_workspace' => [
            'title' => 'Schedule Workspace',
            'description' => 'Timetabling grid, section schedules, and class session operations.',
        ],
        'recommendations' => [
            'title' => 'Recommendations Engine',
            'description' => 'Automated timetable generation and schedule proposal reviews.',
        ],
        'instructor_assignment' => [
            'title' => 'Instructor Assignment',
            'description' => 'Faculty teaching assignments and workload allocation.',
        ],
        'submission_workflow' => [
            'title' => 'Submission Workflow',
            'description' => 'Department schedule submission and recall lifecycle.',
        ],
        'approval_workflow' => [
            'title' => 'Approval Workflow',
            'description' => 'Multi-stage administrative review and endorsement actions.',
        ],
        'room_requests' => [
            'title' => 'Room Requests',
            'description' => "Borrowing another department's vacant rooms for a semester.",
        ],
        'curriculum' => [
            'title' => 'Curriculum',
            'description' => 'Curriculum records and the courses placed in each year level and semester.',
        ],
    ],

    /*
     * What every account holding a role may do. Access is decided by role
     * alone -- there are no per-account grants -- so two secretaries always
     * hold the same power, and changing it means editing this list and adding
     * a migration that re-syncs the stored roles.
     *
     * Secretaries and program heads build their department's timetable end to
     * end; approvals and designations stay with the dean and VPAA. The
     * secretary alone also decides on requests to borrow their department's
     * rooms and authors the curriculum.
     *
     * The VPAA holds every capability except `curriculum.manage` and
     * `room.review_requests`: the curriculum is authored by the department
     * secretary that owns the programs, and room lending is settled between
     * departments, with the VPAA only notified.
     */
    'role_defaults' => [
        'vpaa' => [
            'schedule.view', 'schedule.create', 'schedule.update', 'schedule.delete',
            'schedule.generate', 'schedule.submit', 'schedule.withdraw',
            'schedule.assign_instructor', 'schedule.assign_instructor_cross_department',
            'schedule.approve_dean', 'schedule.approve_vpaa',
            'faculty.manage_designations',
            'room.request', 'room.view_all_requests',
        ],
        'dean' => ['schedule.view', 'schedule.approve_dean'],
        'secretary' => [
            'schedule.view', 'schedule.create', 'schedule.update', 'schedule.delete',
            'schedule.generate', 'schedule.submit', 'schedule.withdraw',
            'schedule.assign_instructor', 'schedule.assign_instructor_cross_department',
            'room.request', 'room.review_requests', 'curriculum.manage',
        ],
        'program_head' => [
            'schedule.view', 'schedule.create', 'schedule.update', 'schedule.delete',
            'schedule.generate', 'schedule.submit', 'schedule.withdraw',
            'schedule.assign_instructor', 'schedule.assign_instructor_cross_department',
            'room.request',
        ],
    ],
];
