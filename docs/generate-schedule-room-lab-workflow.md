# Generate Schedule Room/Laboratory Workflow

This workflow explains how schedule generation should choose a room for a major course when a department may or may not have its own laboratory.

```mermaid
flowchart TD
    A["Start generate schedule"] --> B["Select department, term, section, and major course"]
    B --> C{"Does the course need a laboratory?"}

    C -- "No" --> D["Find available lecture room owned by the department"]
    D --> E{"Lecture room available?"}
    E -- "Yes" --> F["Assign lecture room"]
    E -- "No" --> G["Find shared available lecture room"]
    G --> H{"Shared lecture room available?"}
    H -- "Yes" --> F
    H -- "No" --> I["Try another time/day"]

    C -- "Yes" --> J["Find available laboratory owned by the department"]
    J --> K{"Department laboratory available?"}
    K -- "Yes" --> L["Assign laboratory room"]
    K -- "No" --> M{"Can this major use lecture room as fallback?"}

    M -- "Yes" --> N["Find available lecture room owned by the department"]
    N --> O{"Lecture room available?"}
    O -- "Yes" --> P["Assign lecture room with lab fallback penalty"]
    O -- "No" --> Q["Find shared available lecture room"]
    Q --> R{"Shared lecture room available?"}
    R -- "Yes" --> P
    R -- "No" --> I

    M -- "No" --> S["Try another time/day or mark unresolved"]

    F --> T["Check section, faculty, room, day, and time conflicts"]
    L --> T
    P --> T
    I --> T
    S --> U["Return conflict or recommendation message"]

    T --> V{"Any conflict?"}
    V -- "No" --> W["Save or recommend schedule"]
    V -- "Yes" --> X["Try another room/time/day"]
    X --> Y{"Found valid option?"}
    Y -- "Yes" --> W
    Y -- "No" --> U
```

## Simple Rule Summary

- If the course does not need a lab, use a department lecture room first.
- If no department lecture room is available, use a shared lecture room.
- If the course needs a lab, use a department laboratory first.
- If the department has no available laboratory, a lecture room can be used only when the course allows fallback.
- A laboratory fallback to lecture should be treated as less ideal, not as the first choice.
- If no valid room/time/day combination exists, return a conflict or recommendation message instead of forcing the schedule.

