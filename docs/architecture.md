# Architecture

## Application Components

```mermaid
flowchart LR
    Passenger["Passenger browser"] --> Index["index.php"]
    Staff["Staff browser"] --> StaffUI["staff.php"]
    Index --> API["api.php"]
    StaffUI --> API
    Index --> Bootstrap["bootstrap.php"]
    StaffUI --> Bootstrap
    API --> Bootstrap
    Bootstrap --> Settings["settings table"]
    Bootstrap --> Items["items table"]
    Bootstrap --> Pending["pending_reports table"]
    Bootstrap --> Airlines["airlines table"]
    Bootstrap --> SQLite["Station SQLite database"]
    API --> Uploads["uploads/ runtime files"]
    StaffUI --> Mail["SMTP or PHP mail"]
    API --> Mail
```

## Request Flow

```mermaid
sequenceDiagram
    participant P as Passenger
    participant UI as index.php
    participant API as api.php
    participant Boot as bootstrap.php
    participant DB as SQLite
    participant Mail as Mail transport

    P->>UI: Search found items or submit lost report
    UI->>API: JSON or form action
    API->>Boot: Resolve station and database
    Boot->>DB: Provision schema if needed
    API->>DB: Read or write item/report records
    API->>Mail: Send configured notification when needed
    API-->>UI: JSON response
    UI-->>P: Updated terminal view
```

## Station Resolution

```mermaid
flowchart TD
    Start["Incoming request"] --> StaffPinned{"Staff session has active station?"}
    StaffPinned -->|Yes| Station["Use staff station"]
    StaffPinned -->|No| Param{"station in GET or POST?"}
    Param -->|Yes| ValidateParam["Validate against stations.json"]
    ValidateParam --> Station
    Param -->|No| Session{"active_station in session?"}
    Session -->|Yes| Station
    Session -->|No| Cookie{"af_station cookie?"}
    Cookie -->|Yes| Station
    Cookie -->|No| Header{"X-Station header?"}
    Header -->|Yes| Station
    Header -->|No| Default["Use first station, fallback MUC"]
    Default --> Station
    Station --> DBFile["cabin_db.sqlite or cabin_db_CODE.sqlite"]
```

## Data Model

```mermaid
erDiagram
    settings {
        text key PK
        text value
    }

    items {
        text tag_no
        text item_description
        text contents
        text pax_name
        text pax_email
        text status
        text photo
        datetime created_at
    }

    pending_reports {
        integer id PK
        text report_ref
        text tag_no
        text airline
        text pax_name
        text pax_email
        text status
        text matched_tag_no
        datetime created_at
        datetime reviewed_at
    }

    airlines {
        integer id PK
        text name
        text code
        text logo
        text domain
    }

    deleted_items {
        text tag_no PK
    }

    deleted_airlines {
        text code PK
    }
```

## Runtime Files

- `cabin_db.sqlite` and `cabin_db_CODE.sqlite` are generated locally and ignored by Git.
- `uploads/` stores runtime uploads and local email copies.
- `release/` stores generated release builds and is ignored by Git.
- `uploads/.htaccess` is tracked because it blocks script execution inside uploads.
