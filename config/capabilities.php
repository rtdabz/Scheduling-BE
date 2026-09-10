<?php

return [
    'permissions' => [
        'schedule.view' => [
            'module' => 'schedule_workspace',
            'title' => 'View Schedules',
            'description' => 'Browse section schedules, department timetables, and academic calendars.',
        ],
        'schedule.create' => [
            'module' => 'schedule_workspace',
            'title' => 'Create Schedules',
            'description' => 'Place new classes, schedule splits, and batch schedule placements.',
        ],
        'schedule.update' => [
            'module' => 'schedule_workspace',
            'title' => 'Update Schedules',
            'description' => 'Modify existing schedule slots, timeslots, and room allocations.',
        ],
        'schedule.delete' => [
            'module' => 'schedule_workspace',
            'title' => 'Delete Schedules',
            'description' => 'Remove schedules and drop timetable placements from sections.',
        ],
        'schedule.generate' => [
            'module' => 'recommendations',
            'title' => 'Generate Recommendations',
            'description' => 'Run the automated recommendation engine and review proposals.',
        ],
        'schedule.assign_instructor' => [
            'module' => 'instructor_assignment',
            'title' => 'Assign Instructors',
            'description' => 'Assign faculty members to courses and manage teaching load allocations.',
        ],
        'schedule.assign_instructor_cross_department' => [
            'module' => 'instructor_assignment',
            'title' => 'Cross-Department Assignment',
            'description' => 'Decide which college teaches a delegable course owned by another department.',
        ],
        'schedule.submit' => [
            'module' => 'submission_workflow',
            'title' => 'Submit Schedules',
            'description' => 'Submit completed department schedules for administrative approval.',
        ],
        'schedule.withdraw' => [
            'module' => 'submission_workflow',
            'title' => 'Withdraw Submission',
            'description' => 'Recall pending department schedule submissions for further editing.',
        ],
        'schedule.approve_dean' => [
            'module' => 'approval_workflow',
            'title' => 'Dean Approval',
            'description' => 'Approve, endorse, or return department schedules as College Dean.',
            'allowed_roles' => ['dean', 'vpaa'],
        ],
        'schedule.approve_vpaa' => [
            'module' => 'approval_workflow',
            'title' => 'VPAA Approval',
            'description' => 'Approve or return department schedules as Vice President for Academic Affairs.',
            'allowed_roles' => ['vpaa'],
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
            'description' => 'Department schedule submission and withdrawal lifecycle.',
        ],
        'approval_workflow' => [
            'title' => 'Approval Workflow',
            'description' => 'Multi-stage administrative review and endorsement actions.',
        ],
    ],

    /*
     * Permissions inherited from the role itself, for every account that holds
     * it. Deliberately sparse: what a secretary, program head or director may
     * do differs from one appointment to the next, so their power is granted
     * per account instead. An inherited permission cannot be taken away from
     * one user without taking it from the whole role, which is exactly the
     * rigidity this system is meant to avoid.
     *
     * The VPAA keeps the full set: it is the break-glass account.
     */
    'role_defaults' => [
        'vpaa' => [
            'schedule.view', 'schedule.create', 'schedule.update', 'schedule.delete',
            'schedule.generate', 'schedule.submit', 'schedule.withdraw',
            'schedule.assign_instructor', 'schedule.assign_instructor_cross_department',
            'schedule.approve_dean', 'schedule.approve_vpaa',
        ],
        'dean' => ['schedule.view', 'schedule.approve_dean'],
        'secretary' => [],
        'program_head' => [],
        'director' => [],
    ],

    'presets' => [
        'view_only' => [
            'label' => 'Reviewer (View Only)',
            'description' => 'Reads schedules and history without changing anything.',
            'permissions' => ['schedule.view'],
        ],
        'assign_only' => [
            'label' => 'Instructor Assigner',
            'description' => 'Assigns faculty to courses, including courses delegated from other colleges.',
            'permissions' => [
                'schedule.view',
                'schedule.assign_instructor',
                'schedule.assign_instructor_cross_department',
            ],
        ],
        'full_workspace' => [
            'label' => 'Schedule Builder',
            'description' => 'Builds and submits the department timetable, and assigns its instructors.',
            'permissions' => [
                'schedule.view', 'schedule.create', 'schedule.update', 'schedule.delete',
                'schedule.generate', 'schedule.submit', 'schedule.withdraw', 'schedule.assign_instructor',
            ],
        ],
    ],
];
